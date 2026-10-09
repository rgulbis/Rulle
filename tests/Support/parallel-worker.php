<?php

/*
 * One contender in a ParallelRequestsTest race. Boots the real application
 * against the shared SQLite file, then waits at a barrier so that every
 * worker fires its request at the same instant, and prints what came back.
 *
 *   php parallel-worker.php '<json spec>'
 *
 * Not a test itself: it is only ever started by tests/Parallel.
 */

use App\Models\User;
use App\Support\Payments\CheckoutFulfillment;
use App\Support\Payments\StripeGateway;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;
use Tests\Support\FakeStripeGateway;

$spec = json_decode($argv[1] ?? '', true, flags: JSON_THROW_ON_ERROR);

// Real environment variables beat .env, so none of this touches the
// developer's own database or cache.
foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => $spec['app_key'],
    'APP_DEBUG' => 'true',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $spec['database'],
    'DB_URL' => '',
    'CACHE_STORE' => 'database',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'BROADCAST_CONNECTION' => 'null',
    'MAIL_MAILER' => 'array',
    'LOG_CHANNEL' => 'null',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * A Stripe whose checkout sessions outlive the process, so a session created
 * by one worker can be found (and found open) by another.
 */
$stripe = new class($spec['stripe_log'] ?? null, $spec['cancel_log'] ?? null) extends FakeStripeGateway
{
    public function __construct(private readonly ?string $log, private readonly ?string $cancelLog) {}

    public function cancelSubscriptionNow(string $stripeSubscriptionId): void
    {
        parent::cancelSubscriptionNow($stripeSubscriptionId);

        if ($this->cancelLog) {
            file_put_contents($this->cancelLog, $stripeSubscriptionId."\n", FILE_APPEND | LOCK_EX);
        }
    }

    public function createSubscriptionCheckout(User $user, string $priceId, string $successUrl, string $cancelUrl): Session
    {
        $session = parent::createSubscriptionCheckout($user, $priceId, $successUrl, $cancelUrl);

        if ($this->log) {
            file_put_contents($this->log, getmypid().'-'.$session->id."\n", FILE_APPEND | LOCK_EX);
        }

        return $session;
    }

    public function retrieveCheckoutSession(string $sessionId): Session
    {
        return $this->sessions[$sessionId] ??= Session::constructFrom([
            'id' => $sessionId,
            'status' => 'open',
            'payment_status' => 'unpaid',
            'url' => 'https://checkout.test/'.$sessionId,
        ]);
    }
};
$app->instance(StripeGateway::class, $stripe);

// Webhook requests are sent unsigned; the real signature check is tested on
// its own, and has nothing to do with what a race does to the data.
$app['config']->set('cashier.webhook.secret', null);

// Sessions Stripe would report as paid, for the workers that look one up.
foreach ($spec['paid_sessions'] ?? [] as $paidSession) {
    $stripe->addSession($paidSession);
}

// Everything slow happens before the barrier: the first query, the user, the
// kernel. Only the contested operation is left to race.
if ($spec['user_id']) {
    $app['auth']->guard('web')->setUser(User::findOrFail($spec['user_id']));
}

touch($spec['ready']);

$deadline = microtime(true) + 30;
while (! file_exists($spec['barrier'])) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "barrier never opened\n");
        exit(2);
    }
    usleep(200);
}

// The gap between "read" and "write" is microseconds wide, so an unprotected
// check-then-write would usually get away with it and the test would pass for
// the wrong reason. Pausing after every read stretches that gap until a
// missing transaction or lock cannot hide: code that really is atomic is
// unaffected, because the other workers are held at BEGIN / the lock.
//
// Not for the cache tables: the rate limiter and locks read those inside their
// own short transactions, and pausing there would hold the write lock and line
// the workers up one behind the other - the opposite of a race.
DB::listen(function ($query) {
    $sql = ltrim(strtolower($query->sql));

    if (str_starts_with($sql, 'select') && ! str_contains($sql, '"cache')) {
        usleep(30_000);
    }
});

if (($spec['action'] ?? 'http') === 'fulfil') {
    // Both the webhook and the success page end up here.
    $session = Session::constructFrom(['id' => $spec['session'], 'payment_status' => 'paid', 'payment_intent' => 'pi_'.$spec['session']]);
    $stripe->addSession($spec['session']);

    try {
        app(CheckoutFulfillment::class)->fulfill($session);
        $result = ['status' => 200];
    } catch (Throwable $e) {
        $result = ['status' => 500, 'body' => $e->getMessage()];
    }
} else {
    // `json` is a request body (a webhook); `params` are form fields.
    $request = Request::create($spec['uri'], $spec['method'], $spec['params'] ?? [], [], [], array_filter([
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_REFERER' => url('/'),
        'CONTENT_TYPE' => isset($spec['json']) ? 'application/json' : null,
    ]), isset($spec['json']) ? json_encode($spec['json']) : null);

    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);

    $result = ['status' => $response->getStatusCode(), 'body' => mb_substr((string) $response->getContent(), 0, 400)];
}

echo json_encode($result)."\n";

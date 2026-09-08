<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ScanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('staff/scan');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = User::where('qr_code', $validated['code'])->first();

        if (! $user) {
            return response()->json([
                'found' => false,
                'message' => 'No user matches this QR code.',
            ], 404);
        }

        $entering = ! $user->checked_in;

        if ($entering) {
            if (! $user->hasActiveAccess()) {
                return response()->json([
                    'found' => true,
                    'allowed' => false,
                    'name' => $user->name,
                    'message' => 'No active subscription.',
                ], 403);
            }

            if (! $user->subscribed('default') && $purchase = $user->activeOneTimePurchase()) {
                $purchase->decrement('visits_remaining');

                if ($purchase->visits_remaining <= 0) {
                    $purchase->update(['status' => 'used_up']);
                }
            }
        }

        $user->checked_in = $entering;
        $user->save();

        return response()->json([
            'found' => true,
            'allowed' => true,
            'name' => $user->name,
            'checked_in' => $user->checked_in,
        ]);
    }
}

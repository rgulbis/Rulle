<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reverb endpoint as the browser sees it
    |--------------------------------------------------------------------------
    |
    | The Content-Security-Policy's connect-src has to allow the WebSocket the
    | page opens in resources/js/echo.ts. Those are the build-time VITE_*
    | values (the public port and scheme through Cloudflare), not
    | REVERB_PORT/REVERB_SCHEME, which are where the app reaches Reverb
    | privately inside Docker. See SetSecurityHeaders.
    |
    */

    'reverb' => [
        'port' => env('VITE_REVERB_PORT'),
        'scheme' => env('VITE_REVERB_SCHEME', 'https'),
    ],

];

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

        $user->checked_in = ! $user->checked_in;
        $user->save();

        return response()->json([
            'found' => true,
            'name' => $user->name,
            'checked_in' => $user->checked_in,
        ]);
    }
}

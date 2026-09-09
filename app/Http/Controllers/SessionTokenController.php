<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionTokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'token' => $request->session()->token(),
            'user_id' => (string) $request->user()->getAuthIdentifier(),
        ])->header('Cache-Control', 'no-store, private');
    }
}

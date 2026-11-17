<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExpireInactiveToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $idleMinutes = max(1, (int) config('sanctum.idle_expiration', 30));

        if ($token instanceof PersonalAccessToken && $token->last_activity_at && $token->last_activity_at->lt(now()->subMinutes($idleMinutes))) {
            $token->delete();
            return response()->json(['message' => 'Your session expired after inactivity. Please sign in again.'], 401);
        }

        if ($token instanceof PersonalAccessToken) {
            $token->last_activity_at = now();
            $token->save();
        }

        return $next($request);
    }
}

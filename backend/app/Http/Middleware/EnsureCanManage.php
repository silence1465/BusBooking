<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Station masters need an admin to switch on each thing they may create: buses, pickup_points, trips. */
class EnsureCanManage
{
    public function handle(Request $request, Closure $next, string $what): Response
    {
        $user = $request->user();

        if (! $user || ! $user->canManage($what)) {
            return response()->json(['message' => 'An admin has not given you access to this.'], 403);
        }

        return $next($request);
    }
}

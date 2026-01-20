<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExtendExecutionTime
{
    /**
     * Increase max execution time for long-running requests.
     */
    public function handle(Request $request, Closure $next, int $seconds = 300): Response
    {
        // Suppress warnings if set_time_limit is disabled in the environment.
        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }

        // Also set max_execution_time for environments that honor ini settings.
        @ini_set('max_execution_time', (string) $seconds);

        return $next($request);
    }
}

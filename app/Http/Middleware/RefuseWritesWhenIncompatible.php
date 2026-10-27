<?php

namespace App\Http\Middleware;

use App\Services\CompatibilityService;
use Closure;
use Illuminate\Http\Request;

/**
 * Refuses non-safe requests before any row is written while the ISPConfig
 * schema is below ISPCONFIG_COMPAT (spec 010 FR-008). Runs after api.key, so
 * unauthenticated callers still get 401 and learn nothing; reads pass.
 */
class RefuseWritesWhenIncompatible
{
    public function __construct(private readonly CompatibilityService $compatibility) {}

    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethodSafe()) {
            $report = $this->compatibility->report();

            if (! $report->compatible()) {
                throw $report->toException();
            }
        }

        return $next($request);
    }
}

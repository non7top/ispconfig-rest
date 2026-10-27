<?php

namespace App\Http\Controllers;

use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

/**
 * Unauthenticated orchestrator probes (spec 010 FR-012/FR-013). Per-check
 * states only: no versions, levels or hostnames.
 */
class ProbeController
{
    public function livez(): JsonResponse
    {
        return response()->json(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }

    public function readyz(HealthCheckService $health): JsonResponse
    {
        $results = $health->run();
        $ready = $health->ready($results);

        return response()->json([
            'status' => $ready ? 'ok' : 'unavailable',
            'checks' => collect($results)->mapWithKeys(fn ($check) => [$check->name => $check->state])->all(),
        ], $ready ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}

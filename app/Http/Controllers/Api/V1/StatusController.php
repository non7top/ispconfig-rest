<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CompatibilityService;
use Illuminate\Http\JsonResponse;

/**
 * GET /status — the numbers behind /readyz, administrator keys only (spec 010 FR-015).
 */
class StatusController extends Controller
{
    public function show(CompatibilityService $compatibility): JsonResponse
    {
        $report = $compatibility->report();

        return response()->json([
            'release' => config('api.release'),
            'compatible' => $report->compatible(),
            'ispconfig' => [
                'version' => $report->version,
                'dbversion' => $report->dbversion,
                'required_dbversion' => $report->requiredDbversion,
                'minimum_version' => $report->minVersion,
            ],
        ]);
    }
}

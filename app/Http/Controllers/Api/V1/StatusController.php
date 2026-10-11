<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CompatibilityService;
use App\Support\Logging\LogHealth;
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
            'compatible' => $report->compatible(),
            'ispconfig' => [
                'version' => $report->version,
                'dbversion' => $report->dbversion,
                'required_dbversion' => $report->requiredDbversion,
                'minimum_version' => $report->minVersion,
            ],
            'logging' => ['state' => LogHealth::state()],
        ]);
    }
}

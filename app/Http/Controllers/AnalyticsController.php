<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    public function index(AnalyticsService $analytics): JsonResponse
    {
        return response()->json([
            'data' => [
                'summary' => $analytics->summary(),
                'trend' => $analytics->trend(14),
                'best_job_types' => $analytics->bestJobTypes(),
                'best_hours' => $analytics->bestHours(),
                'score_calibration' => $analytics->scoreCalibration(),
                'recent_activity' => $analytics->recentActivity(20),
            ],
        ]);
    }
}

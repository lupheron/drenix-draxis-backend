<?php

namespace App\Http\Controllers;

use App\Services\EmployeeMetricsService;
use App\Services\EmployeeScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserMetricsController extends Controller
{
    public function __construct(
        private readonly EmployeeScopeService $employeeScope,
        private readonly EmployeeMetricsService $metricsService,
    ) {}

    public function daily(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $query = DB::table('users')->where('id', $id);
        $query = $this->employeeScope->applyScope($query, $request->user());

        if (! $query->exists()) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $daily = $this->metricsService->dailyForUser(
            $id,
            $validated['from'],
            $validated['to'],
        );

        return response()->json(['data' => $daily]);
    }
}

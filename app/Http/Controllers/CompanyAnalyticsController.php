<?php

namespace App\Http\Controllers;

use App\Services\EmployeeMetricsService;
use App\Services\EmployeeScopeService;
use App\Support\EmployeeAttributes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyAnalyticsController extends Controller
{
    public function __construct(
        private readonly EmployeeScopeService $employeeScope,
        private readonly EmployeeMetricsService $metricsService,
    ) {}

    public function hrAnalytics(Request $request, string $company): JsonResponse
    {
        $company = strtoupper($company);

        if (! in_array($company, EmployeeAttributes::COMPANIES, true)) {
            return response()->json(['message' => 'Invalid company.'], 422);
        }

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $query = DB::table('users')
            ->select($this->employeeScope->getVisibleColumns($request->user()))
            ->where('company', $company)
            ->where('department', 'hr');

        $query = $this->employeeScope->applyScope($query, $request->user());

        $employees = $query->orderBy('first_name')->orderBy('last_name')->get();
        $employeesWithMetrics = $this->metricsService->attachMetrics(
            $employees,
            $validated['from'],
            $validated['to'],
        );

        return response()->json([
            'data' => [
                'company' => $company,
                'employee_count' => $employeesWithMetrics->count(),
                ...$this->metricsService->sumMetrics($employeesWithMetrics),
                'by_employee' => $employeesWithMetrics->values(),
            ],
        ]);
    }
}

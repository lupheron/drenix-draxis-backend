<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ClientPortalMetricsService;
use App\Support\ClientEmployeePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MeController extends Controller
{
    public function __construct(
        private readonly ClientPortalMetricsService $portalMetrics,
    ) {}

    public function profile(Request $request): JsonResponse
    {
        /** @var User $employee */
        $employee = $request->user();

        return response()->json([
            'data' => ClientEmployeePresenter::present($employee->fresh()),
        ]);
    }

    public function password(Request $request): JsonResponse
    {
        /** @var User $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $employee->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $employee->password = $validated['password'];
        $employee->save();
        $employee->tokens()->delete();

        return response()->json(['message' => 'ok']);
    }

    public function metrics(Request $request): JsonResponse
    {
        /** @var User $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $this->portalMetrics->totals(
                $employee,
                $validated['from'],
                $validated['to'],
            ),
        ]);
    }

    public function metricsDaily(Request $request): JsonResponse
    {
        /** @var User $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $this->portalMetrics->daily(
                $employee,
                $validated['from'],
                $validated['to'],
            ),
        ]);
    }

    public function leads(Request $request): JsonResponse
    {
        /** @var User $employee */
        $employee = $request->user();

        if (strtolower((string) $employee->department) !== 'hr') {
            return response()->json(['message' => 'Leads are available to HR employees only.'], 403);
        }

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $this->portalMetrics->leads(
                $employee,
                $validated['from'],
                $validated['to'],
            ),
        ]);
    }
}

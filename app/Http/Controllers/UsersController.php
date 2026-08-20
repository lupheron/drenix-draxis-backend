<?php

namespace App\Http\Controllers;

use App\Services\EmployeeMetricsService;
use App\Services\EmployeeScopeService;
use App\Support\EmployeeAttributes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UsersController extends Controller
{
    public function __construct(
        private readonly EmployeeScopeService $employeeScope,
        private readonly EmployeeMetricsService $metricsService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company' => ['nullable', 'string', Rule::in(EmployeeAttributes::COMPANIES)],
            'department' => ['nullable', 'string'],
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'required_with:from'],
        ]);

        $columns = $this->employeeScope->getVisibleColumns($request->user());

        $query = DB::table('users')->select($columns);
        $query = $this->employeeScope->applyScope($query, $request->user());

        if (! empty($validated['company'])) {
            $query->where('company', $validated['company']);
        }

        if ($request->filled('department')) {
            $query->where('department', EmployeeAttributes::normalizeDepartment($validated['department']));
        }

        $users = $query->orderBy('first_name')->orderBy('last_name')->get();

        if (! empty($validated['from']) && ! empty($validated['to'])) {
            $users = $this->metricsService->attachMetrics($users, $validated['from'], $validated['to']);
        }

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedEmployeePayload($request);

        $id = DB::table('users')->insertGetId(array_merge($validated, [
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $user = DB::table('users')->select(
            $this->employeeScope->getVisibleColumns($request->user())
        )->find($id);

        return response()->json(['data' => $user], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'required_with:from'],
        ]);

        $query = DB::table('users')->select(
            $this->employeeScope->getVisibleColumns($request->user())
        )->where('id', $id);

        $query = $this->employeeScope->applyScope($query, $request->user());
        $user = $query->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if (! empty($validated['from']) && ! empty($validated['to'])) {
            $user = $this->metricsService->attachMetrics(collect([$user]), $validated['from'], $validated['to'])->first();
        }

        return response()->json(['data' => $user]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $query = DB::table('users')->where('id', $id);
        $query = $this->employeeScope->applyScope($query, $request->user());

        if (! $query->exists()) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $validated = $this->validatedEmployeePayload($request, partial: true);

        DB::table('users')->where('id', $id)->update(array_merge($validated, [
            'updated_at' => now(),
        ]));

        $user = DB::table('users')->select(
            $this->employeeScope->getVisibleColumns($request->user())
        )->find($id);

        return response()->json(['data' => $user]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $query = DB::table('users')->where('id', $id);
        $query = $this->employeeScope->applyScope($query, $request->user());

        $deleted = $query->delete();

        if (! $deleted) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json(null, 204);
    }

    /**
     * Admin: set/reset Client Portal username + password for an employee.
     */
    public function setPortalCredentials(Request $request, int $id): JsonResponse
    {
        $query = DB::table('users')->where('id', $id);
        $query = $this->employeeScope->applyScope($query, $request->user());

        if (! $query->exists()) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $validated = $request->validate([
            'username' => [
                'required',
                'string',
                'min:3',
                'max:100',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($id),
            ],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = \App\Models\User::query()->findOrFail($id);
        $user->username = $validated['username'];
        $user->password = $validated['password'];
        $user->save();
        $user->tokens()->delete();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'username' => $user->username,
                'portal_enabled' => true,
            ],
            'message' => 'Portal credentials updated.',
        ]);
    }

    private function validatedEmployeePayload(Request $request, bool $partial = false): array
    {
        $validated = $request->validate([
            'first_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'last_name'  => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'birth_date' => [$partial ? 'sometimes' : 'required', 'date'],
            'joined_at'  => [$partial ? 'sometimes' : 'required', 'date'],
            'shift'      => [$partial ? 'sometimes' : 'required', Rule::in(['morning', 'afternoon', 'night', 'flexible'])],
            'salary'     => [$partial ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'grade'      => [$partial ? 'sometimes' : 'required', 'string', 'max:3'],
            'status'     => [$partial ? 'sometimes' : 'required', Rule::in(['normal', 'close_monitor'])],
            'phone'      => ['nullable', 'string', 'max:50'],
            'email'      => ['nullable', 'email', 'max:255'],
            'address'    => ['nullable', 'string', 'max:500'],
            'city'       => ['nullable', 'string', 'max:255'],
            'state'      => ['nullable', 'string', 'max:255'],
            'country'    => ['nullable', 'string', 'max:255'],
            'gender'     => ['nullable', 'string', 'max:50'],
            'department' => EmployeeAttributes::departmentRule(! $partial),
            'position'   => ['nullable', 'string', 'max:255'],
            'company'    => EmployeeAttributes::companyRule(! $partial),
        ]);

        if (isset($validated['department'])) {
            $validated['department'] = EmployeeAttributes::normalizeDepartment($validated['department']);
        }

        if (isset($validated['company'])) {
            $validated['company'] = strtoupper($validated['company']);
        }

        return $validated;
    }
}

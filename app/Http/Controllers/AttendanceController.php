<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDay;
use App\Models\AttendanceRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceRequestService;
use App\Services\Attendance\AttendanceScopeService;
use App\Services\Attendance\AttendanceService;
use App\Services\Integrations\Attendance\AttendanceMetricsAggregator;
use App\Support\EmployeeAttributes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\AttendanceAuditLog;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceScopeService $scope,
        private readonly AttendanceRequestService $requests,
        private readonly AttendanceMetricsAggregator $metrics,
    ) {}

    public function days(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company' => ['nullable', 'string', Rule::in(EmployeeAttributes::COMPANIES)],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
        ]);

        $query = AttendanceDay::query()
            ->with('user:id,first_name,last_name,company,department')
            ->whereBetween('date', [$validated['from'], $validated['to']]);

        if (! empty($validated['company'])) {
            $query->where('company', $validated['company']);
        }

        if (! empty($validated['status'])) {
            $status = $validated['status'];
            if ($status === 'present') {
                $query->whereIn('status', ['present', 'break', 'missing_punch']);
            } else {
                $query->where('status', $status);
            }
        }

        if (! empty($validated['employee_id'])) {
            $employee = DB::table('users')->find($validated['employee_id']);
            if (! $employee || ! $this->scope->canAccessEmployee($request->user(), $employee)) {
                return response()->json(['message' => 'Employee not found.'], 404);
            }
            $query->where('user_id', $validated['employee_id']);
        } else {
            $allowedUserIds = $this->scopedUserIds($request);
            if ($allowedUserIds !== null) {
                $query->whereIn('user_id', $allowedUserIds);
            }
        }

        $days = $query->orderByDesc('date')->limit(500)->get();
        $eventsByKey = $this->attendance->eventsForDays($days);

        return response()->json([
            'data' => $days->map(function (AttendanceDay $day) use ($eventsByKey) {
                $key = $day->user_id.'|'.$day->date->toDateString();
                $formatted = $this->attendance->formatDay(
                    $day,
                    $eventsByKey->get($key, collect()),
                );

                return array_merge($formatted, [
                    'employee' => [
                        'id' => $day->user?->id,
                        'first_name' => $day->user?->first_name,
                        'last_name' => $day->user?->last_name,
                        'company' => $day->user?->company,
                        'department' => $day->user?->department,
                    ],
                ]);
            }),
        ]);
    }

    public function employee(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $employee = DB::table('users')->find($id);
        if (! $employee || ! $this->scope->canAccessEmployee($request->user(), $employee)) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        $user = User::query()->findOrFail($id);

        return response()->json([
            'data' => [
                'employee' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'company' => $user->company,
                    'department' => $user->department,
                ],
                'summary' => $this->attendance->summary($user, $validated['from'], $validated['to']),
                'days' => $this->attendance->days($user, $validated['from'], $validated['to']),
            ],
        ]);
    }

    public function updateDay(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in([
                'present', 'late', 'no_show', 'break', 'missing_punch', 'excused', 'pending_review',
            ])],
            'admin_note' => ['nullable', 'string', 'max:5000'],
            'check_in_at' => ['nullable', 'date'],
            'check_out_at' => ['nullable', 'date'],
            'late_minutes' => ['nullable', 'integer', 'min:0'],
        ]);

        $day = AttendanceDay::query()->with('user')->find($id);
        if (! $day || ! $this->scope->canAccessEmployee($request->user(), $day->user)) {
            return response()->json(['message' => 'Attendance day not found.'], 404);
        }

        $before = $day->toArray();
        $day = $this->requests->overrideDay($day, null, $request->user(), $validated);
        $this->metrics->rebuildForUserDate($day->user_id, $day->date->toDateString());

        AttendanceAuditLog::query()->create([
            'attendance_day_id' => $day->id,
            'user_id' => $day->user_id,
            'actor_type' => $request->user()::class,
            'actor_id' => $request->user()->id,
            'action' => 'override',
            'before' => $before,
            'after' => $day->toArray(),
        ]);

        return response()->json(['data' => $this->attendance->formatDay($day, collect())]);
    }

    public function requests(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'resolved'])],
            'company' => ['nullable', 'string', Rule::in(EmployeeAttributes::COMPANIES)],
        ]);

        $query = AttendanceRequest::query()
            ->with('user:id,first_name,last_name,company,department')
            ->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['company'])) {
            $query->where('company', $validated['company']);
        }

        $allowedUserIds = $this->scopedUserIds($request);
        if ($allowedUserIds !== null) {
            $query->whereIn('user_id', $allowedUserIds);
        }

        $items = $query->limit(200)->get()->map(function (AttendanceRequest $item) {
            return array_merge($this->attendance->formatRequest($item), [
                'employee' => [
                    'id' => $item->user?->id,
                    'first_name' => $item->user?->first_name,
                    'last_name' => $item->user?->last_name,
                    'company' => $item->user?->company,
                    'department' => $item->user?->department,
                ],
            ]);
        });

        return response()->json(['data' => $items]);
    }

    public function approveRequest(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);

        $item = AttendanceRequest::query()->with('user')->find($id);
        if (! $item || ! $this->scope->canAccessEmployee($request->user(), $item->user)) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        $updated = $this->requests->approve($request->user(), $item, $validated['comment'] ?? null);

        return response()->json(['data' => $this->attendance->formatRequest($updated)]);
    }

    public function rejectRequest(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);

        $item = AttendanceRequest::query()->with('user')->find($id);
        if (! $item || ! $this->scope->canAccessEmployee($request->user(), $item->user)) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        $updated = $this->requests->reject($request->user(), $item, $validated['comment'] ?? null);

        return response()->json(['data' => $this->attendance->formatRequest($updated)]);
    }

    private function scopedUserIds(Request $request): ?array
    {
        if ($request->user() instanceof \App\Models\Admin) {
            return null;
        }

        $query = DB::table('users')->select('id');
        $this->scope->applyUserScope($query, $request->user());

        return $query->pluck('id')->all();
    }
}

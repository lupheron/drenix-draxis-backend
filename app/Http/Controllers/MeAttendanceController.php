<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceRequestService;
use App\Services\Attendance\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeAttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceRequestService $requests,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        /** @var User $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $this->attendance->summary(
                $employee,
                $validated['from'],
                $validated['to'],
            ),
        ]);
    }

    public function days(Request $request): JsonResponse
    {
        /** @var User $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $this->attendance->days(
                $employee,
                $validated['from'],
                $validated['to'],
            ),
        ]);
    }

    public function day(Request $request, string $date): JsonResponse
    {
        /** @var User $employee */
        $employee = $request->user();

        $day = $this->attendance->day($employee, $date);
        if (! $day) {
            return response()->json(['message' => 'Attendance day not found.'], 404);
        }

        return response()->json(['data' => $day]);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        /** @var User $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'type' => ['required', Rule::in(['dispute', 'absence'])],
            'date' => ['required', 'date'],
            'message' => ['required', 'string', 'max:5000'],
            'related_day_id' => ['nullable', 'integer', 'exists:attendance_days,id'],
        ]);

        if (! empty($validated['related_day_id'])) {
            $ownsDay = \App\Models\AttendanceDay::query()
                ->where('id', $validated['related_day_id'])
                ->where('user_id', $employee->id)
                ->exists();

            if (! $ownsDay) {
                return response()->json(['message' => 'Related attendance day not found.'], 422);
            }
        }

        $created = $this->requests->create($employee, $validated);

        return response()->json([
            'data' => $this->attendance->formatRequest($created),
        ], 201);
    }

    public function requests(Request $request): JsonResponse
    {
        /** @var User $employee */
        $employee = $request->user();

        $items = AttendanceRequest::query()
            ->where('user_id', $employee->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AttendanceRequest $item) => $this->attendance->formatRequest($item));

        return response()->json(['data' => $items]);
    }
}

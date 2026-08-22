<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function timezone(): string
    {
        return config('integrations.attendance.timezone', 'America/Chicago');
    }

    public function todayDate(): string
    {
        return Carbon::now($this->timezone())->toDateString();
    }

    public function summary(User $user, string $from, string $to): array
    {
        $days = AttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from, $to])
            ->get();

        $today = AttendanceDay::query()
            ->where('user_id', $user->id)
            ->where('date', $this->todayDate())
            ->first();

        $pendingRequests = AttendanceRequest::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->count();

        return [
            'from' => $from,
            'to' => $to,
            'timezone' => $this->timezone(),
            'today' => $today ? $this->formatToday($today) : $this->emptyToday(),
            'period' => $this->periodCounts($days, $pendingRequests),
        ];
    }

    public function days(User $user, string $from, string $to): array
    {
        $days = AttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from, $to])
            ->orderByDesc('date')
            ->get();

        $eventsByDate = $this->eventsGroupedByDate($user->id, $from, $to);

        return $days->map(fn (AttendanceDay $day) => $this->formatDay(
            $day,
            $eventsByDate[$day->date->toDateString()] ?? collect(),
        ))->values()->all();
    }

    public function day(User $user, string $date): ?array
    {
        $day = AttendanceDay::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->first();

        if (! $day) {
            return null;
        }

        $events = AttendanceEvent::query()
            ->where('user_id', $user->id)
            ->where('shift_date', $date)
            ->orderBy('occurred_at')
            ->get();

        return $this->formatDay($day, $events);
    }

    public function attachStaffAttendance(Collection $users, string $from, string $to): Collection
    {
        if ($users->isEmpty()) {
            return $users;
        }

        $userIds = $users->pluck('id')->all();
        $today = $this->todayDate();

        $todayStatuses = AttendanceDay::query()
            ->whereIn('user_id', $userIds)
            ->where('date', $today)
            ->get()
            ->keyBy('user_id');

        $periodCounts = DB::table('attendance_days')
            ->select([
                'user_id',
                DB::raw("SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count"),
                DB::raw("SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show_count"),
            ])
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from, $to])
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $openRequests = DB::table('attendance_requests')
            ->select(['user_id', DB::raw('COUNT(*) as open_requests')])
            ->whereIn('user_id', $userIds)
            ->where('status', 'pending')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        return $users->map(function ($user) use ($todayStatuses, $periodCounts, $openRequests) {
            $today = $todayStatuses->get($user->id);
            $period = $periodCounts->get($user->id);
            $requests = $openRequests->get($user->id);

            $user->attendance = [
                'today_status' => $today?->status,
                'late_count' => (int) ($period->late_count ?? 0),
                'no_show_count' => (int) ($period->no_show_count ?? 0),
                'open_requests' => (int) ($requests->open_requests ?? 0),
            ];

            return $user;
        });
    }

    public function formatDay(AttendanceDay $day, Collection $events): array
    {
        return [
            'id' => $day->id,
            'date' => $day->date->toDateString(),
            'status' => $day->status,
            'check_in_at' => $day->check_in_at?->toIso8601String(),
            'check_out_at' => $day->check_out_at?->toIso8601String(),
            'break_at' => $day->break_at?->toIso8601String(),
            'late_minutes' => (int) $day->late_minutes,
            'shift_start' => $day->shift_start,
            'shift_end' => $day->shift_end,
            'sheet_note' => $day->sheet_note,
            'admin_note' => $day->admin_note,
            'is_manual_override' => (bool) $day->is_manual_override,
            'events' => $events->map(fn (AttendanceEvent $event) => [
                'id' => $event->id,
                'type' => $event->event_type,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'source' => 'sheet',
            ])->values()->all(),
        ];
    }

    public function formatRequest(AttendanceRequest $request): array
    {
        return [
            'id' => $request->id,
            'type' => $request->type,
            'status' => $request->status,
            'date' => $request->date->toDateString(),
            'message' => $request->message,
            'admin_comment' => $request->admin_comment,
            'related_day_id' => $request->related_day_id,
            'resolved_at' => $request->resolved_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    private function formatToday(AttendanceDay $day): array
    {
        return [
            'date' => $day->date->toDateString(),
            'status' => $day->status,
            'check_in_at' => $day->check_in_at?->toIso8601String(),
            'check_out_at' => $day->check_out_at?->toIso8601String(),
            'late_minutes' => (int) $day->late_minutes,
            'notes' => $day->admin_note ?? $day->sheet_note,
        ];
    }

    private function emptyToday(): array
    {
        return [
            'date' => $this->todayDate(),
            'status' => null,
            'check_in_at' => null,
            'check_out_at' => null,
            'late_minutes' => 0,
            'notes' => null,
        ];
    }

    private function periodCounts(Collection $days, int $pendingRequests): array
    {
        $counts = [
            'present_days' => 0,
            'late_days' => 0,
            'no_show_days' => 0,
            'excused_days' => 0,
            'break_days' => 0,
            'pending_requests' => $pendingRequests,
        ];

        foreach ($days as $day) {
            match ($day->status) {
                'present', 'missing_punch' => $counts['present_days']++,
                'late' => $counts['late_days']++,
                'no_show' => $counts['no_show_days']++,
                'excused' => $counts['excused_days']++,
                'break' => $counts['break_days']++,
                default => null,
            };
        }

        return $counts;
    }

    private function eventsGroupedByDate(int $userId, string $from, string $to): Collection
    {
        return AttendanceEvent::query()
            ->where('user_id', $userId)
            ->whereBetween('shift_date', [$from, $to])
            ->orderBy('occurred_at')
            ->get()
            ->groupBy(fn (AttendanceEvent $event) => $event->shift_date->toDateString());
    }
}

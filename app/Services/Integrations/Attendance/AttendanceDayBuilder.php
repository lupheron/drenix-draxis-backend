<?php

namespace App\Services\Integrations\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Support\AttendanceNormalizer;
use Carbon\Carbon;

class AttendanceDayBuilder
{
    public function rebuildForUserDate(int $userId, string $company, string $date): AttendanceDay
    {
        $events = AttendanceEvent::query()
            ->where('user_id', $userId)
            ->where('shift_date', $date)
            ->orderBy('occurred_at')
            ->get();

        $day = AttendanceDay::query()->firstOrNew([
            'user_id' => $userId,
            'date' => $date,
        ]);

        $wasOverride = (bool) $day->is_manual_override;
        $overrideStatus = $wasOverride ? $day->status : null;

        $checkIn = null;
        $checkOut = null;
        $breakAt = null;
        $lateMinutes = 0;
        $shiftStart = null;
        $shiftEnd = null;
        $sheetNote = null;
        $didntCome = false;

        foreach ($events as $event) {
            if ($event->late_minutes && $event->late_minutes > $lateMinutes) {
                $lateMinutes = (int) $event->late_minutes;
            }

            if ($event->notes && ! $sheetNote) {
                $sheetNote = $event->notes;
            }

            if (AttendanceNormalizer::didntComeFlag($event->didnt_come)) {
                $didntCome = true;
            }

            if ($event->shift_time && ! $shiftStart) {
                $shiftStart = $event->shift_time;
            }

            match ($event->event_type) {
                'check_in' => $checkIn = $checkIn ?? $event->occurred_at,
                'check_out' => $checkOut = $event->occurred_at,
                'break' => $breakAt = $event->occurred_at,
                'no_show' => $didntCome = true,
                default => null,
            };
        }

        if (! $wasOverride) {
            $day->fill([
                'company' => $company,
                'status' => AttendanceNormalizer::deriveDayStatus(
                    $didntCome,
                    $lateMinutes,
                    $checkIn?->toIso8601String(),
                    $checkOut?->toIso8601String(),
                    $breakAt !== null,
                ),
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'break_at' => $breakAt,
                'late_minutes' => $lateMinutes,
                'shift_start' => $shiftStart,
                'shift_end' => $shiftEnd,
                'sheet_note' => $sheetNote,
            ]);
        } else {
            $day->company = $company;
            if ($overrideStatus) {
                $day->status = $overrideStatus;
            }
        }

        $day->save();

        return $day;
    }
}

<?php

namespace App\Services\Integrations\Attendance;

use Illuminate\Support\Facades\DB;

class AttendanceMetricsAggregator
{
    public function rebuildForUserDate(int $userId, string $date): void
    {
        $day = DB::table('attendance_days')
            ->where('user_id', $userId)
            ->where('date', $date)
            ->first();

        $lates = 0;
        $noShows = 0;

        if ($day) {
            if ((int) ($day->late_minutes ?? 0) > 0 || $day->status === 'late') {
                $lates = 1;
            }
            if ($day->status === 'no_show') {
                $noShows = 1;
            }
        }

        $exists = DB::table('employee_daily_metrics')
            ->where('user_id', $userId)
            ->where('date', $date)
            ->exists();

        $payload = [
            'lates' => $lates,
            'no_shows' => $noShows,
            'updated_at' => now(),
        ];

        if (! $exists) {
            $payload['created_at'] = now();
        }

        DB::table('employee_daily_metrics')->updateOrInsert(
            ['user_id' => $userId, 'date' => $date],
            $payload,
        );
    }
}

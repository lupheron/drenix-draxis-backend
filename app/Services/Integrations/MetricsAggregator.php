<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\DB;

class MetricsAggregator
{
    public function rebuildFromCallLogs(int $userId, string $from, string $to): void
    {
        $this->rebuildCallMetricsFromLogs($userId, $from, $to);
    }

    /**
     * Zero call metrics for the range, then rebuild from call_logs (all types).
     */
    public function rebuildCallMetricsFromLogs(int $userId, string $from, string $to): void
    {
        DB::table('employee_daily_metrics')
            ->where('user_id', $userId)
            ->whereBetween('date', [$from, $to])
            ->update([
                'calls_made' => 0,
                'minutes_on_call' => 0,
                'outbound_calls' => 0,
                'inbound_calls' => 0,
                'missed_calls' => 0,
                'voicemail_calls' => 0,
                'other_calls' => 0,
                'outbound_minutes' => 0,
                'inbound_minutes' => 0,
                'updated_at' => now(),
            ]);

        $rows = DB::table('call_logs')
            ->select([
                DB::raw('DATE(started_at) as date'),
                DB::raw('COUNT(*) as calls_made'),
                DB::raw("SUM(CASE WHEN call_type = 'outbound' THEN 1 ELSE 0 END) as outbound_calls"),
                DB::raw("SUM(CASE WHEN call_type = 'inbound' THEN 1 ELSE 0 END) as inbound_calls"),
                DB::raw("SUM(CASE WHEN call_type = 'missed' THEN 1 ELSE 0 END) as missed_calls"),
                DB::raw("SUM(CASE WHEN call_type = 'voicemail' THEN 1 ELSE 0 END) as voicemail_calls"),
                DB::raw("SUM(CASE WHEN call_type = 'other' OR call_type IS NULL THEN 1 ELSE 0 END) as other_calls"),
                DB::raw('COALESCE(SUM(duration_seconds), 0) as total_seconds'),
                DB::raw("COALESCE(SUM(CASE WHEN call_type = 'outbound' THEN duration_seconds ELSE 0 END), 0) as outbound_seconds"),
                DB::raw("COALESCE(SUM(CASE WHEN call_type = 'inbound' THEN duration_seconds ELSE 0 END), 0) as inbound_seconds"),
            ])
            ->where('user_id', $userId)
            ->whereBetween('started_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->groupBy(DB::raw('DATE(started_at)'))
            ->get();

        foreach ($rows as $row) {
            $this->upsertPartial($userId, $row->date, [
                'calls_made' => (int) $row->calls_made,
                'outbound_calls' => (int) $row->outbound_calls,
                'inbound_calls' => (int) $row->inbound_calls,
                'missed_calls' => (int) $row->missed_calls,
                'voicemail_calls' => (int) $row->voicemail_calls,
                'other_calls' => (int) $row->other_calls,
                'minutes_on_call' => (int) round(((int) $row->total_seconds) / 60),
                'outbound_minutes' => (int) round(((int) $row->outbound_seconds) / 60),
                'inbound_minutes' => (int) round(((int) $row->inbound_seconds) / 60),
            ]);
        }
    }

    public function resetMondayMetricsForRange(int $userId, string $from, string $to): void
    {
        DB::table('employee_daily_metrics')
            ->where('user_id', $userId)
            ->whereBetween('date', [$from, $to])
            ->update([
                'leads' => 0,
                'hires' => 0,
                'loaded' => 0,
                'follow_up' => 0,
                'rejected' => 0,
                'updated_at' => now(),
            ]);
    }

    public function rebuildMondayMetricsFromItems(int $userId, string $from, string $to): void
    {
        $this->resetMondayMetricsForRange($userId, $from, $to);

        $rows = DB::table('monday_items')
            ->select([
                'metric_date',
                'metric_type',
                DB::raw('COUNT(*) as total'),
            ])
            ->where('user_id', $userId)
            ->whereBetween('metric_date', [$from, $to])
            ->groupBy('metric_date', 'metric_type')
            ->get();

        $byDate = [];

        foreach ($rows as $row) {
            $date = (string) $row->metric_date;
            if (! isset($byDate[$date])) {
                $byDate[$date] = [
                    'leads' => 0,
                    'hires' => 0,
                    'loaded' => 0,
                    'follow_up' => 0,
                    'rejected' => 0,
                ];
            }

            $type = (string) $row->metric_type;
            if (array_key_exists($type, $byDate[$date])) {
                $byDate[$date][$type] = (int) $row->total;
            }
        }

        foreach ($byDate as $date => $fields) {
            $this->upsertPartial($userId, $date, $fields);
        }
    }

    private function upsertPartial(int $userId, string $date, array $fields): void
    {
        $now = now();

        $existing = DB::table('employee_daily_metrics')
            ->where('user_id', $userId)
            ->where('date', $date)
            ->first();

        if ($existing) {
            DB::table('employee_daily_metrics')
                ->where('id', $existing->id)
                ->update(array_merge($fields, ['updated_at' => $now]));

            return;
        }

        DB::table('employee_daily_metrics')->insert(array_merge([
            'user_id' => $userId,
            'date' => $date,
            'minutes_on_call' => 0,
            'calls_made' => 0,
            'outbound_calls' => 0,
            'inbound_calls' => 0,
            'missed_calls' => 0,
            'voicemail_calls' => 0,
            'other_calls' => 0,
            'outbound_minutes' => 0,
            'inbound_minutes' => 0,
            'lates' => 0,
            'no_shows' => 0,
            'leads' => 0,
            'hires' => 0,
            'loaded' => 0,
            'follow_up' => 0,
            'rejected' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $fields));
    }

    public function setMondayMetrics(int $userId, string $date, array $fields): void
    {
        $this->upsertPartial($userId, $date, $fields);
    }
}

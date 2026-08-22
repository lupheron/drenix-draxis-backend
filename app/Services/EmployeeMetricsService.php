<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EmployeeMetricsService
{
    public function emptyMetrics(): array
    {
        return [
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
            'ringcentral' => $this->emptyRingCentral(),
        ];
    }

    public function emptyRingCentral(): array
    {
        return [
            'total_calls' => 0,
            'outbound' => 0,
            'inbound' => 0,
            'missed' => 0,
            'voicemail' => 0,
            'other' => 0,
            'minutes_total' => 0,
            'minutes_outbound' => 0,
            'minutes_inbound' => 0,
        ];
    }

    public function aggregateForUsers(array $userIds, string $from, string $to): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return DB::table('employee_daily_metrics')
            ->select([
                'user_id',
                DB::raw('COALESCE(SUM(minutes_on_call), 0) as minutes_on_call'),
                DB::raw('COALESCE(SUM(calls_made), 0) as calls_made'),
                DB::raw('COALESCE(SUM(outbound_calls), 0) as outbound_calls'),
                DB::raw('COALESCE(SUM(inbound_calls), 0) as inbound_calls'),
                DB::raw('COALESCE(SUM(missed_calls), 0) as missed_calls'),
                DB::raw('COALESCE(SUM(voicemail_calls), 0) as voicemail_calls'),
                DB::raw('COALESCE(SUM(other_calls), 0) as other_calls'),
                DB::raw('COALESCE(SUM(outbound_minutes), 0) as outbound_minutes'),
                DB::raw('COALESCE(SUM(inbound_minutes), 0) as inbound_minutes'),
                DB::raw('COALESCE(SUM(lates), 0) as lates'),
                DB::raw('COALESCE(SUM(no_shows), 0) as no_shows'),
                DB::raw('COALESCE(SUM(leads), 0) as leads'),
                DB::raw('COALESCE(SUM(hires), 0) as hires'),
                DB::raw('COALESCE(SUM(loaded), 0) as loaded'),
                DB::raw('COALESCE(SUM(follow_up), 0) as follow_up'),
                DB::raw('COALESCE(SUM(rejected), 0) as rejected'),
            ])
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from, $to])
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
    }

    public function dailyForUser(int $userId, string $from, string $to): array
    {
        return DB::table('employee_daily_metrics')
            ->where('user_id', $userId)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => $this->formatMetricsRow($row))
            ->all();
    }

    public function attachMetrics(Collection $users, string $from, string $to): Collection
    {
        $userIds = $users->pluck('id')->all();
        $aggregates = $this->aggregateForUsers($userIds, $from, $to);

        return $users->map(function ($user) use ($aggregates) {
            $metrics = $aggregates->get($user->id);
            $user->metrics = $metrics
                ? $this->formatMetricsRow($metrics)
                : $this->emptyMetrics();

            return $user;
        });
    }

    public function sumMetrics(Collection $usersWithMetrics): array
    {
        $totals = $this->emptyMetrics();

        foreach ($usersWithMetrics as $user) {
            foreach ($totals as $key => $value) {
                if ($key === 'ringcentral') {
                    foreach ($totals['ringcentral'] as $rk => $rv) {
                        $totals['ringcentral'][$rk] += (int) ($user->metrics['ringcentral'][$rk] ?? 0);
                    }
                    continue;
                }
                $totals[$key] += (int) ($user->metrics[$key] ?? 0);
            }
        }

        return $totals;
    }

    public function formatMetricsRow(object $row): array
    {
        $outbound = (int) ($row->outbound_calls ?? 0);
        $inbound = (int) ($row->inbound_calls ?? 0);
        $missed = (int) ($row->missed_calls ?? 0);
        $voicemail = (int) ($row->voicemail_calls ?? 0);
        $other = (int) ($row->other_calls ?? 0);
        $callsMade = (int) ($row->calls_made ?? 0);
        $minutes = (int) ($row->minutes_on_call ?? 0);
        $outMin = (int) ($row->outbound_minutes ?? 0);
        $inMin = (int) ($row->inbound_minutes ?? 0);

        return [
            'minutes_on_call' => $minutes,
            'calls_made' => $callsMade,
            'outbound_calls' => $outbound,
            'inbound_calls' => $inbound,
            'missed_calls' => $missed,
            'voicemail_calls' => $voicemail,
            'other_calls' => $other,
            'outbound_minutes' => $outMin,
            'inbound_minutes' => $inMin,
            'lates' => (int) ($row->lates ?? 0),
            'no_shows' => (int) ($row->no_shows ?? 0),
            'leads' => (int) ($row->leads ?? 0),
            'hires' => (int) ($row->hires ?? 0),
            'loaded' => (int) ($row->loaded ?? 0),
            'follow_up' => (int) ($row->follow_up ?? 0),
            'rejected' => (int) ($row->rejected ?? 0),
            'ringcentral' => [
                'total_calls' => $callsMade,
                'outbound' => $outbound,
                'inbound' => $inbound,
                'missed' => $missed,
                'voicemail' => $voicemail,
                'other' => $other,
                'minutes_total' => $minutes,
                'minutes_outbound' => $outMin,
                'minutes_inbound' => $inMin,
            ],
            'date' => $row->date ?? null,
        ];
    }
}

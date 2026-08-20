<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClientPortalMetricsService
{
    public function __construct(
        private readonly EmployeeMetricsService $metrics,
    ) {}

    /**
     * Totals for authenticated employee only (Admin metrics + SMS counts).
     */
    public function totals(User $employee, string $from, string $to): array
    {
        $row = $this->metrics->aggregateForUsers([$employee->id], $from, $to)->get($employee->id);
        $base = $row
            ? $this->metrics->formatMetricsRow($row)
            : $this->metrics->emptyMetrics();

        $messages = $this->messageCounts($employee->id, $from, $to);

        return [
            'from' => $from,
            'to' => $to,
            'calls_made' => (int) ($base['calls_made'] ?? 0),
            'minutes_on_call' => (int) ($base['minutes_on_call'] ?? 0),
            'messages_total' => $messages['total'],
            'messages_inbound' => $messages['inbound'],
            'messages_outbound' => $messages['outbound'],
            'leads' => (int) ($base['leads'] ?? 0),
            'follow_up' => (int) ($base['follow_up'] ?? 0),
            'hires' => (int) ($base['hires'] ?? 0),
            'loaded' => (int) ($base['loaded'] ?? 0),
            'rejected' => (int) ($base['rejected'] ?? 0),
        ];
    }

    /**
     * Daily rows for authenticated employee (includes message counts per day).
     */
    public function daily(User $employee, string $from, string $to): array
    {
        $daily = $this->metrics->dailyForUser($employee->id, $from, $to);
        $byDate = collect($daily)->keyBy('date');

        $messageByDate = $this->messageCountsByDate($employee->id, $from, $to);

        $dates = collect($byDate->keys())
            ->merge($messageByDate->keys())
            ->unique()
            ->sort()
            ->values();

        return $dates->map(function (string $date) use ($byDate, $messageByDate) {
            $base = $byDate->get($date) ?? $this->metrics->emptyMetrics();
            $msgs = $messageByDate->get($date, ['total' => 0, 'inbound' => 0, 'outbound' => 0]);

            return [
                'date' => $date,
                'calls_made' => (int) ($base['calls_made'] ?? 0),
                'minutes_on_call' => (int) ($base['minutes_on_call'] ?? 0),
                'messages_total' => (int) $msgs['total'],
                'messages_inbound' => (int) $msgs['inbound'],
                'messages_outbound' => (int) $msgs['outbound'],
                'leads' => (int) ($base['leads'] ?? 0),
                'follow_up' => (int) ($base['follow_up'] ?? 0),
                'hires' => (int) ($base['hires'] ?? 0),
                'loaded' => (int) ($base['loaded'] ?? 0),
                'rejected' => (int) ($base['rejected'] ?? 0),
            ];
        })->all();
    }

    /**
     * Lead/pipeline items from monday_items for this employee only.
     */
    public function leads(User $employee, string $from, string $to): array
    {
        return DB::table('monday_items')
            ->where('user_id', $employee->id)
            ->whereBetween('metric_date', [$from, $to])
            ->orderByDesc('metric_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($item) => $this->formatLead($item))
            ->all();
    }

    private function formatLead(object $item): array
    {
        $status = match ($item->metric_type) {
            'leads' => 'new',
            'follow_up' => 'follow_up',
            'hires' => 'hired',
            'loaded' => 'loaded',
            'rejected' => 'rejected',
            default => (string) $item->metric_type,
        };

        $date = $item->metric_date
            ? (string) $item->metric_date
            : null;

        $notes = null;
        $companyName = null;
        $raw = is_string($item->raw ?? null)
            ? json_decode($item->raw, true)
            : ($item->raw ?? null);

        if (is_array($raw)) {
            foreach ($raw['column_values'] ?? [] as $column) {
                $type = strtolower((string) ($column['type'] ?? ''));
                $text = trim((string) ($column['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                if ($companyName === null && in_array($type, ['text', 'company'], true)
                    && str_contains(strtolower((string) ($column['id'] ?? '')), 'company')) {
                    $companyName = $text;
                }
            }

            // Common free-text note columns often include "need" / long status notes
            foreach ($raw['column_values'] ?? [] as $column) {
                $id = strtolower((string) ($column['id'] ?? ''));
                $text = trim((string) ($column['text'] ?? ''));
                if ($text !== '' && (str_contains($id, 'note') || str_contains($id, 'comment'))) {
                    $notes = $text;
                    break;
                }
            }
        }

        return [
            'id' => $item->external_id ?: $item->id,
            'title' => $item->item_name,
            'name' => $item->item_name,
            'company_name' => $companyName,
            'status' => $status,
            'follow_up_at' => $status === 'follow_up' ? $date : null,
            'hired_at' => $status === 'hired' ? $date : null,
            'loaded_at' => $status === 'loaded' ? $date : null,
            'rejected_at' => $status === 'rejected' ? $date : null,
            'created_at' => $date,
            'updated_at' => $item->updated_at
                ? \Carbon\Carbon::parse($item->updated_at)->toIso8601String()
                : null,
            'notes' => $notes,
        ];
    }

    /**
     * @return array{total:int,inbound:int,outbound:int}
     */
    private function messageCounts(int $userId, string $from, string $to): array
    {
        $row = DB::table('ringcentral_messages')
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as inbound,
                SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as outbound
            ")
            ->where('user_id', $userId)
            ->whereBetween('sent_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'inbound' => (int) ($row->inbound ?? 0),
            'outbound' => (int) ($row->outbound ?? 0),
        ];
    }

    private function messageCountsByDate(int $userId, string $from, string $to)
    {
        return DB::table('ringcentral_messages')
            ->selectRaw("
                DATE(sent_at) as date,
                COUNT(*) as total,
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as inbound,
                SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as outbound
            ")
            ->where('user_id', $userId)
            ->whereBetween('sent_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->groupBy(DB::raw('DATE(sent_at)'))
            ->get()
            ->keyBy(fn ($r) => (string) $r->date)
            ->map(fn ($r) => [
                'total' => (int) $r->total,
                'inbound' => (int) $r->inbound,
                'outbound' => (int) $r->outbound,
            ]);
    }
}

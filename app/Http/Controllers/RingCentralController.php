<?php

namespace App\Http\Controllers;

use App\Services\EmployeeScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RingCentralController extends Controller
{
    public function __construct(
        private readonly EmployeeScopeService $employeeScope,
    ) {}

    /**
     * RingCentral section summary + daily breakdown for one employee.
     * Data comes from our DB (call_logs), never live RC.
     */
    public function summary(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        if (! $this->userVisible($request, $id)) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $from = $validated['from'];
        $to = $validated['to'];

        $totals = DB::table('call_logs')
            ->selectRaw("
                COUNT(*) as total_calls,
                SUM(CASE WHEN call_type = 'outbound' THEN 1 ELSE 0 END) as outbound,
                SUM(CASE WHEN call_type = 'inbound' THEN 1 ELSE 0 END) as inbound,
                SUM(CASE WHEN call_type = 'missed' THEN 1 ELSE 0 END) as missed,
                SUM(CASE WHEN call_type = 'voicemail' THEN 1 ELSE 0 END) as voicemail,
                SUM(CASE WHEN call_type = 'other' OR call_type IS NULL THEN 1 ELSE 0 END) as other,
                COALESCE(SUM(duration_seconds), 0) as total_seconds,
                COALESCE(SUM(CASE WHEN call_type = 'outbound' THEN duration_seconds ELSE 0 END), 0) as outbound_seconds,
                COALESCE(SUM(CASE WHEN call_type = 'inbound' THEN duration_seconds ELSE 0 END), 0) as inbound_seconds
            ")
            ->where('user_id', $id)
            ->whereBetween('started_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->first();

        $daily = DB::table('call_logs')
            ->selectRaw("
                DATE(started_at) as date,
                COUNT(*) as total_calls,
                SUM(CASE WHEN call_type = 'outbound' THEN 1 ELSE 0 END) as outbound,
                SUM(CASE WHEN call_type = 'inbound' THEN 1 ELSE 0 END) as inbound,
                SUM(CASE WHEN call_type = 'missed' THEN 1 ELSE 0 END) as missed,
                SUM(CASE WHEN call_type = 'voicemail' THEN 1 ELSE 0 END) as voicemail,
                SUM(CASE WHEN call_type = 'other' OR call_type IS NULL THEN 1 ELSE 0 END) as other,
                COALESCE(SUM(duration_seconds), 0) as total_seconds,
                COALESCE(SUM(CASE WHEN call_type = 'outbound' THEN duration_seconds ELSE 0 END), 0) as outbound_seconds,
                COALESCE(SUM(CASE WHEN call_type = 'inbound' THEN duration_seconds ELSE 0 END), 0) as inbound_seconds
            ")
            ->where('user_id', $id)
            ->whereBetween('started_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->groupBy(DB::raw('DATE(started_at)'))
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => $this->formatSummary($row));

        return response()->json([
            'data' => [
                'user_id' => $id,
                'from' => $from,
                'to' => $to,
                'summary' => $this->formatSummary($totals),
                'daily' => $daily,
            ],
        ]);
    }

    /**
     * SMS conversations + summary for RingCentral Texts tab.
     * Data comes from ringcentral_messages (never live RC).
     */
    public function messageConversations(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        if (! $this->userVisible($request, $id)) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $from = $validated['from'];
        $to = $validated['to'];
        $range = ["{$from} 00:00:00", "{$to} 23:59:59"];

        $base = DB::table('ringcentral_messages')
            ->where('user_id', $id)
            ->whereBetween('sent_at', $range);

        $totals = (clone $base)
            ->selectRaw("
                COUNT(*) as total_messages,
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as inbound,
                SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as outbound,
                COUNT(DISTINCT conversation_id) as conversations_count
            ")
            ->first();

        $conversations = DB::select("
            WITH counts AS (
                SELECT
                    conversation_id,
                    COUNT(*)::int AS message_count,
                    MAX(sent_at) AS last_message_at
                FROM ringcentral_messages
                WHERE user_id = ?
                  AND sent_at BETWEEN ? AND ?
                GROUP BY conversation_id
            ),
            latest AS (
                SELECT DISTINCT ON (m.conversation_id)
                    m.conversation_id,
                    m.body,
                    m.direction,
                    m.peer_number,
                    m.peer_name,
                    m.from_number,
                    m.to_number
                FROM ringcentral_messages m
                INNER JOIN counts c ON c.conversation_id = m.conversation_id
                    AND c.last_message_at = m.sent_at
                WHERE m.user_id = ?
                  AND m.sent_at BETWEEN ? AND ?
                ORDER BY m.conversation_id, m.id DESC
            )
            SELECT
                c.conversation_id AS id,
                c.message_count,
                c.last_message_at,
                l.body,
                l.direction AS last_direction,
                l.peer_number,
                l.peer_name,
                l.from_number,
                l.to_number
            FROM counts c
            LEFT JOIN latest l ON l.conversation_id = c.conversation_id
            ORDER BY c.last_message_at DESC
        ", [$id, $range[0], $range[1], $id, $range[0], $range[1]]);

        $conversations = collect($conversations)->map(function ($row) {
            $peerNumber = $row->peer_number;
            if (! $peerNumber) {
                $peerNumber = ($row->last_direction ?? null) === 'outbound'
                    ? $row->to_number
                    : $row->from_number;
            }

            $preview = trim((string) ($row->body ?? ''));
            if (mb_strlen($preview) > 120) {
                $preview = mb_substr($preview, 0, 119).'…';
            }

            return [
                'id' => (string) $row->id,
                'peer_number' => $peerNumber,
                'peer_name' => $row->peer_name,
                'last_message_at' => $row->last_message_at
                    ? \Carbon\Carbon::parse($row->last_message_at)->toIso8601String()
                    : null,
                'last_preview' => $preview,
                'message_count' => (int) $row->message_count,
                'last_direction' => $row->last_direction,
            ];
        });

        return response()->json([
            'data' => $conversations,
            'summary' => [
                'total_messages' => (int) ($totals->total_messages ?? 0),
                'inbound' => (int) ($totals->inbound ?? 0),
                'outbound' => (int) ($totals->outbound ?? 0),
                'conversations_count' => (int) ($totals->conversations_count ?? 0),
            ],
            'meta' => [
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    /**
     * SMS messages in a conversation (paginated) for RingCentral Texts tab.
     */
    public function messages(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'conversation_id' => ['required', 'string'],
            'direction' => ['nullable', 'string', 'in:inbound,outbound'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        if (! $this->userVisible($request, $id)) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $perPage = $validated['per_page'] ?? 80;
        $page = $validated['page'] ?? 1;

        $query = DB::table('ringcentral_messages')
            ->where('user_id', $id)
            ->where('conversation_id', $validated['conversation_id'])
            ->whereBetween('sent_at', [
                "{$validated['from']} 00:00:00",
                "{$validated['to']} 23:59:59",
            ]);

        if (! empty($validated['direction'])) {
            $query->where('direction', $validated['direction']);
        }

        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc('sent_at')
            ->forPage($page, $perPage)
            ->get([
                'id',
                'external_id',
                'conversation_id',
                'direction',
                'body',
                'from_number',
                'to_number',
                'sent_at',
                'status',
            ])
            ->map(fn ($m) => [
                'id' => $m->id,
                'external_id' => $m->external_id,
                'conversation_id' => $m->conversation_id,
                'direction' => $m->direction,
                'body' => $m->body,
                'from_number' => $m->from_number,
                'to_number' => $m->to_number,
                'sent_at' => $m->sent_at
                    ? \Carbon\Carbon::parse($m->sent_at)->toIso8601String()
                    : null,
                'status' => $m->status,
            ]);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'from' => $validated['from'],
                'to' => $validated['to'],
                'conversation_id' => $validated['conversation_id'],
                'direction' => $validated['direction'] ?? null,
            ],
        ]);
    }

    /**
     * Individual call rows for RingCentral section (filterable by type).
     */
    public function calls(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'type' => ['nullable', 'string', 'in:outbound,inbound,missed,voicemail,other'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        if (! $this->userVisible($request, $id)) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $perPage = $validated['per_page'] ?? 50;
        $page = $validated['page'] ?? 1;

        $query = DB::table('call_logs')
            ->where('user_id', $id)
            ->whereBetween('started_at', [
                "{$validated['from']} 00:00:00",
                "{$validated['to']} 23:59:59",
            ]);

        if (! empty($validated['type'])) {
            if ($validated['type'] === 'other') {
                $query->where(function ($q) {
                    $q->where('call_type', 'other')->orWhereNull('call_type');
                });
            } else {
                $query->where('call_type', $validated['type']);
            }
        }

        $total = (clone $query)->count();

        $calls = $query
            ->orderByDesc('started_at')
            ->forPage($page, $perPage)
            ->get([
                'id',
                'external_id',
                'started_at',
                'duration_seconds',
                'direction',
                'result',
                'call_type',
                'action',
            ])
            ->map(fn ($c) => [
                'id' => $c->id,
                'external_id' => $c->external_id,
                'started_at' => $c->started_at,
                'duration_seconds' => (int) $c->duration_seconds,
                'duration_minutes' => round(((int) $c->duration_seconds) / 60, 2),
                'direction' => $c->direction,
                'result' => $c->result,
                'call_type' => $c->call_type ?? 'other',
                'action' => $c->action,
            ]);

        return response()->json([
            'data' => $calls,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'from' => $validated['from'],
                'to' => $validated['to'],
                'type' => $validated['type'] ?? null,
            ],
        ]);
    }

    private function userVisible(Request $request, int $id): bool
    {
        $query = DB::table('users')->where('id', $id);
        $query = $this->employeeScope->applyScope($query, $request->user());

        return $query->exists();
    }

    private function formatSummary(?object $row): array
    {
        if (! $row) {
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
                'date' => null,
            ];
        }

        return [
            'date' => $row->date ?? null,
            'total_calls' => (int) ($row->total_calls ?? 0),
            'outbound' => (int) ($row->outbound ?? 0),
            'inbound' => (int) ($row->inbound ?? 0),
            'missed' => (int) ($row->missed ?? 0),
            'voicemail' => (int) ($row->voicemail ?? 0),
            'other' => (int) ($row->other ?? 0),
            'minutes_total' => (int) round(((int) ($row->total_seconds ?? 0)) / 60),
            'minutes_outbound' => (int) round(((int) ($row->outbound_seconds ?? 0)) / 60),
            'minutes_inbound' => (int) round(((int) ($row->inbound_seconds ?? 0)) / 60),
        ];
    }
}

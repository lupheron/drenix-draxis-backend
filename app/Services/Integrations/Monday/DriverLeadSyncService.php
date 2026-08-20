<?php

namespace App\Services\Integrations\Monday;

use App\Models\DriverLead;
use App\Support\LeadNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DriverLeadSyncService
{
    public function sync(string $company = 'JM'): array
    {
        $company = strtoupper($company);
        $profile = config("integrations.companies.{$company}");
        $token = $profile['monday']['api_token'] ?? null;

        if (! $token) {
            return [
                'company' => $company,
                'status' => 'skipped',
                'reason' => 'MONDAY API token not configured',
            ];
        }

        // Neon/PgBouncer: discard prepared plans after schema changes
        try {
            DB::purge();
            DB::reconnect();
            DB::statement('DISCARD ALL');
        } catch (\Throwable) {
            // ignore — best effort
        }

        $client = new MondayClient($token);
        $boards = $client->listBoards();

        $summary = [
            'company' => $company,
            'status' => 'synced',
            'boards_seen' => count($boards),
            'boards_synced' => 0,
            'boards_skipped' => 0,
            'items_seen' => 0,
            'inserted' => 0,
            'updated' => 0,
            'duplicates_skipped' => 0,
            'errors' => [],
        ];

        $seenHashes = $this->withFreshConnection(fn () => DriverLead::query()
            ->where('company', $company)
            ->pluck('content_hash')
            ->flip()
            ->all());

        $seenItemIds = $this->withFreshConnection(fn () => DriverLead::query()
            ->where('company', $company)
            ->whereNotNull('monday_item_id')
            ->pluck('monday_item_id')
            ->flip()
            ->all());

        foreach ($boards as $board) {
            $boardId = (string) ($board['id'] ?? '');
            $boardName = (string) ($board['name'] ?? '');

            if ($boardId === '' || $this->shouldSkipBoard($board)) {
                $summary['boards_skipped']++;
                continue;
            }

            try {
                $columnMeta = $client->getBoardColumns($boardId);
                $titleById = [];
                foreach ($columnMeta as $col) {
                    $titleById[(string) ($col['id'] ?? '')] = trim((string) ($col['title'] ?? ''));
                }

                $items = $client->getBoardItems($boardId);
            } catch (\Throwable $e) {
                Log::warning('Driver lead sync board failed', [
                    'board_id' => $boardId,
                    'board_name' => $boardName,
                    'error' => $e->getMessage(),
                ]);
                $summary['errors'][] = [
                    'board_id' => $boardId,
                    'board_name' => $boardName,
                    'error' => Str::limit($e->getMessage(), 240),
                ];
                continue;
            }

            $summary['boards_synced']++;
            $summary['items_seen'] += count($items);

            foreach ($items as $item) {
                $row = $this->mapItem($company, $boardId, $boardName, $item, $titleById);
                if ($row === null) {
                    continue;
                }

                $itemId = (string) $row['monday_item_id'];
                $hash = (string) $row['content_hash'];

                try {
                    if (isset($seenItemIds[$itemId])) {
                        $existing = DriverLead::query()
                            ->where('monday_item_id', $itemId)
                            ->first();

                        if ($existing) {
                            if ($existing->content_hash !== $hash && isset($seenHashes[$hash])) {
                                $summary['duplicates_skipped']++;
                                continue;
                            }

                            unset($seenHashes[$existing->content_hash]);
                            $existing->fill($row)->save();
                            $seenHashes[$hash] = true;
                            $seenItemIds[$itemId] = true;
                            $summary['updated']++;
                            continue;
                        }
                    }

                    if (isset($seenHashes[$hash])) {
                        $summary['duplicates_skipped']++;
                        continue;
                    }

                    DriverLead::query()->create($row);
                    $seenHashes[$hash] = true;
                    $seenItemIds[$itemId] = true;
                    $summary['inserted']++;
                } catch (\Illuminate\Database\QueryException $e) {
                    // Unique race / duplicate page: treat as skip or update
                    if (str_contains($e->getMessage(), 'driver_leads_monday_item_id_unique')) {
                        $existing = DriverLead::query()->where('monday_item_id', $itemId)->first();
                        if ($existing) {
                            $existing->fill($row)->save();
                            $seenHashes[$hash] = true;
                            $seenItemIds[$itemId] = true;
                            $summary['updated']++;
                        } else {
                            $summary['duplicates_skipped']++;
                        }
                        continue;
                    }

                    if (str_contains($e->getMessage(), 'driver_leads_content_hash_unique')) {
                        $seenHashes[$hash] = true;
                        $summary['duplicates_skipped']++;
                        continue;
                    }

                    if (str_contains($e->getMessage(), 'cached plan must not change result type')) {
                        DB::purge();
                        DB::reconnect();
                        $summary['errors'][] = [
                            'warning' => 'Neon cached plan — reconnected, retrying item',
                            'monday_item_id' => $itemId,
                        ];

                        try {
                            $existing = DriverLead::query()->where('monday_item_id', $itemId)->first();
                            if ($existing) {
                                $existing->fill($row)->save();
                                $seenHashes[$hash] = true;
                                $seenItemIds[$itemId] = true;
                                $summary['updated']++;
                            } elseif (! isset($seenHashes[$hash])) {
                                DriverLead::query()->create($row);
                                $seenHashes[$hash] = true;
                                $seenItemIds[$itemId] = true;
                                $summary['inserted']++;
                            } else {
                                $summary['duplicates_skipped']++;
                            }
                        } catch (\Throwable $retry) {
                            $summary['errors'][] = [
                                'monday_item_id' => $itemId,
                                'error' => Str::limit($retry->getMessage(), 240),
                            ];
                        }

                        continue;
                    }

                    throw $e;
                }
            }
        }

        return $summary;
    }

    private function withFreshConnection(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'cached plan must not change result type')) {
                throw $e;
            }

            DB::purge();
            DB::reconnect();

            return $callback();
        }
    }

    private function shouldSkipBoard(array $board): bool
    {
        $name = Str::lower(trim((string) ($board['name'] ?? '')));

        if (str_contains($name, 'dashboard')) {
            return true;
        }

        return false;
    }

    private function mapItem(
        string $company,
        string $boardId,
        string $boardName,
        array $item,
        array $titleById,
    ): ?array {
        $itemId = (string) ($item['id'] ?? '');
        if ($itemId === '') {
            return null;
        }

        $name = trim((string) ($item['name'] ?? ''));
        $groupTitle = trim((string) ($item['group']['title'] ?? ''));
        $groupId = (string) ($item['group']['id'] ?? '');

        $columnsByTitle = [];
        $hashColumns = [];

        foreach ($item['column_values'] ?? [] as $column) {
            $colId = (string) ($column['id'] ?? '');
            if ($colId === '') {
                continue;
            }

            $text = trim((string) ($column['text'] ?? ''));
            $title = $titleById[$colId] ?? $colId;
            $columnsByTitle[$title] = $text;
            $hashColumns[$colId] = $text;
        }
        ksort($hashColumns);

        $phone = $this->pickByTitles($columnsByTitle, ['number', 'phone', 'mobile', 'cell'])
            ?? $this->pickByType($item, ['phone']);
        $email = $this->pickByTitles($columnsByTitle, ['email', 'e-mail'])
            ?? $this->pickByType($item, ['email']);
        $notes = $this->pickByTitles($columnsByTitle, ['notes', 'note', 'comment', 'reason']);
        $platform = $this->pickByTitles($columnsByTitle, ['platform', 'source']);
        $position = $this->pickByTitles($columnsByTitle, ['position', 'job']);
        $state = $this->pickByTitles($columnsByTitle, ['state']);
        $recruiter = $this->pickByTitles($columnsByTitle, ['recruiter']);
        $statusFromCol = $this->pickByTitles($columnsByTitle, ['status'])
            ?? $this->pickByType($item, ['status', 'color']);

        $statusLabel = $groupTitle !== '' ? $groupTitle : ($statusFromCol ?: 'Unknown');
        $contacted = $this->pickDateByTitles($item, $titleById, ['date contacted', 'contacted', 'date']);
        $applied = $this->parseDate($item['created_at'] ?? null) ?? $contacted;

        return [
            'company' => $company,
            'monday_item_id' => $itemId,
            'board_id' => $boardId,
            'board_name' => $boardName,
            'group_id' => $groupId !== '' ? $groupId : null,
            'group_title' => $groupTitle !== '' ? $groupTitle : null,
            'name' => $name !== '' ? $name : null,
            'phone' => $phone,
            'phone_normalized' => LeadNormalizer::normalizePhone($phone),
            'email' => $email,
            'email_normalized' => LeadNormalizer::normalizeEmail($email),
            'name_normalized' => LeadNormalizer::normalizeName($name),
            'status_label' => $statusLabel,
            'status_key' => LeadNormalizer::statusKey($statusLabel),
            'notes' => $notes,
            'platform' => $platform,
            'position' => $position,
            'state' => $state,
            'recruiter' => $recruiter,
            'applied_on' => $applied,
            'contacted_on' => $contacted,
            'content_hash' => LeadNormalizer::contentHash($name, $groupTitle, $hashColumns),
            'columns' => $columnsByTitle,
            'raw' => $item,
            'monday_created_at' => $this->parseDateTime($item['created_at'] ?? null),
            'monday_updated_at' => $this->parseDateTime($item['updated_at'] ?? null),
        ];
    }

    private function pickByTitles(array $columnsByTitle, array $needles): ?string
    {
        foreach ($columnsByTitle as $title => $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            $hay = Str::lower((string) $title);
            foreach ($needles as $needle) {
                if ($hay === Str::lower($needle) || str_contains($hay, Str::lower($needle))) {
                    return $text;
                }
            }
        }

        return null;
    }

    private function pickByType(array $item, array $types): ?string
    {
        foreach ($item['column_values'] ?? [] as $column) {
            $type = Str::lower((string) ($column['type'] ?? ''));
            if (! in_array($type, $types, true)) {
                continue;
            }
            $text = trim((string) ($column['text'] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function pickDateByTitles(array $item, array $titleById, array $needles): ?string
    {
        foreach ($item['column_values'] ?? [] as $column) {
            $colId = (string) ($column['id'] ?? '');
            $title = Str::lower($titleById[$colId] ?? $colId);
            $type = Str::lower((string) ($column['type'] ?? ''));
            $matched = $type === 'date';
            foreach ($needles as $needle) {
                if (str_contains($title, Str::lower($needle))) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                continue;
            }

            $text = trim((string) ($column['text'] ?? ''));
            if ($text !== '' && ($parsed = $this->parseDate($text))) {
                return $parsed;
            }

            $raw = $column['value'] ?? null;
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (is_array($decoded) && ! empty($decoded['date'])) {
                return $this->parseDate($decoded['date']);
            }
        }

        return null;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}

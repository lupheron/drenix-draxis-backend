<?php

namespace App\Services\Integrations\Monday;

use App\Models\ExternalIdMapping;
use App\Services\Integrations\MetricsAggregator;
use App\Services\Integrations\UserMatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MondaySyncService
{
    public function __construct(
        private readonly UserMatcher $matcher,
        private readonly MetricsAggregator $aggregator,
    ) {}

    /**
     * Pull Monday items → save each row into monday_items (like call_logs)
     * → rebuild employee_daily_metrics from monday_items.
     */
    public function sync(string $company): array
    {
        $company = strtoupper($company);
        $profile = config("integrations.companies.{$company}");

        if (! $profile) {
            throw new \InvalidArgumentException("No integration profile for company {$company}.");
        }

        $token = $profile['monday']['api_token'] ?? null;

        if (! $token) {
            return [
                'company' => $company,
                'status' => 'skipped',
                'reason' => 'MONDAY_JM_API_TOKEN / MONDAY_API_TOKEN not configured',
            ];
        }

        $client = new MondayClient($token);
        $hireLookback = (int) config('integrations.sync.monday_lookback_days', 400);
        $leadsLookback = (int) config('integrations.sync.monday_leads_lookback_days', 60);
        $hireFrom = now()->subDays($hireLookback)->toDateString();
        $leadsFrom = now()->subDays($leadsLookback)->toDateString();
        $toDate = now()->toDateString();
        $resetFrom = min($hireFrom, $leadsFrom);

        $sourceToUser = collect($profile['monday']['source_to_user'] ?? [])
            ->mapWithKeys(fn ($userName, $label) => [Str::lower(trim($label)) => $userName]);

        $usersBySource = [];
        foreach ($sourceToUser as $label => $whitelistName) {
            $user = $this->matcher->findUserByWhitelistName($company, $whitelistName);
            if ($user) {
                $usersBySource[$label] = $user;
            }
        }

        $usersByWhitelistName = [];
        foreach ($profile['whitelist'] as $entry) {
            if (! ($entry['monday'] ?? false)) {
                continue;
            }
            $user = $this->matcher->findUserByWhitelistName(
                $company,
                $entry['name'],
                $entry['match_aliases'] ?? [],
            );
            if ($user) {
                $usersByWhitelistName[$entry['name']] = $user;
            }
        }

        $touchedUserIds = collect($usersBySource)
            ->merge($usersByWhitelistName)
            ->pluck('id')
            ->unique()
            ->values()
            ->all();

        $rowsToInsert = [];

        $hireSummary = $this->collectHrProcessItems(
            $client,
            $profile,
            $company,
            $usersBySource,
            $hireFrom,
            $toDate,
            $rowsToInsert,
        );

        $leadsSummary = $this->collectLeadBoardItems(
            $client,
            $profile,
            $company,
            $usersByWhitelistName,
            $leadsFrom,
            $toDate,
            $rowsToInsert,
        );

        $inserted = $this->persistMondayItems($rowsToInsert);

        foreach ($touchedUserIds as $userId) {
            $this->aggregator->rebuildMondayMetricsFromItems($userId, $resetFrom, $toDate);
        }

        return [
            'company' => $company,
            'monday_items_upserted' => $inserted,
            'monday_items_total' => DB::table('monday_items')->where('company', $company)->count(),
            'hr_process' => $hireSummary,
            'leads_pipeline' => $leadsSummary,
            'users_rebuilt' => count($touchedUserIds),
        ];
    }

    private function collectHrProcessItems(
        MondayClient $client,
        array $profile,
        string $company,
        array $usersBySource,
        string $fromDate,
        string $toDate,
        array &$rowsToInsert,
    ): array {
        $boardId = (string) ($profile['monday']['hr_process_board_id'] ?? '');

        if ($boardId === '' || $usersBySource === []) {
            return ['status' => 'skipped', 'reason' => 'missing board or users'];
        }

        $allowedGroups = collect($profile['monday']['hr_process_groups'] ?? ['Hired', 'Loaded'])
            ->map(fn ($g) => Str::lower(trim($g)))
            ->all();

        $rejectedGroups = collect($profile['monday']['hr_process_rejected_groups'] ?? ['Rejected'])
            ->map(fn ($g) => Str::lower(trim($g)))
            ->all();

        $sourceTitles = collect($profile['monday']['source_column_titles'] ?? ['Source'])
            ->map(fn ($t) => Str::lower(trim($t)))
            ->all();

        $dateTitles = collect($profile['monday']['date_column_titles'] ?? ['Date'])
            ->map(fn ($t) => Str::lower(trim($t)))
            ->all();

        $columns = $client->getBoardColumns($boardId);
        $sourceColumnId = $this->findColumnIdByTitles($columns, $sourceTitles);
        $dateColumnId = $this->findColumnIdByTitles($columns, $dateTitles);

        if (! $sourceColumnId || ! $dateColumnId) {
            return [
                'status' => 'failed',
                'reason' => 'Could not find Source/Date columns on HR Process board',
            ];
        }

        $anchorUserId = collect($usersBySource)->first()->id;

        ExternalIdMapping::updateOrCreate(
            ['provider' => 'monday', 'external_id' => $boardId],
            [
                'user_id' => $anchorUserId,
                'metadata' => [
                    'company' => $company,
                    'board_name' => 'HR Process JDM',
                    'kind' => 'hr_process',
                ],
            ],
        );

        $items = $client->getBoardItems($boardId);
        $counted = 0;
        $skipped = ['wrong_group' => 0, 'no_source' => 0, 'unknown_source' => 0, 'no_date' => 0, 'out_of_range' => 0];

        foreach ($items as $item) {
            $groupTitle = Str::lower(trim((string) ($item['group']['title'] ?? '')));
            $isHireGroup = in_array($groupTitle, $allowedGroups, true);
            $isRejected = in_array($groupTitle, $rejectedGroups, true);

            if (! $isHireGroup && ! $isRejected) {
                $skipped['wrong_group']++;
                continue;
            }

            $sourceLabel = Str::lower(trim($this->columnText($item, $sourceColumnId)));
            if ($sourceLabel === '') {
                $skipped['no_source']++;
                continue;
            }

            $user = $usersBySource[$sourceLabel] ?? null;
            if (! $user) {
                $skipped['unknown_source']++;
                continue;
            }

            $metricDate = $this->extractDate($item, $dateColumnId)
                ?? $this->itemTimestampDate($item);

            if (! $metricDate) {
                $skipped['no_date']++;
                continue;
            }

            if ($metricDate < $fromDate || $metricDate > $toDate) {
                $skipped['out_of_range']++;
                continue;
            }

            $metricType = match (true) {
                $isRejected => 'rejected',
                $groupTitle === 'hired' => 'hires',
                $groupTitle === 'loaded' => 'loaded',
                default => null,
            };

            if ($metricType === null) {
                continue;
            }

            $rowsToInsert[] = $this->makeItemRow(
                userId: $user->id,
                company: $company,
                item: $item,
                boardId: $boardId,
                boardName: 'HR Process JDM',
                boardKind: 'hr_process',
                metricType: $metricType,
                metricDate: $metricDate,
                sourceLabel: $sourceLabel,
            );

            $counted++;
        }

        return [
            'status' => 'synced',
            'board_id' => $boardId,
            'items_processed' => count($items),
            'items_saved' => $counted,
            'skipped' => $skipped,
        ];
    }

    private function collectLeadBoardItems(
        MondayClient $client,
        array $profile,
        string $company,
        array $usersByWhitelistName,
        string $fromDate,
        string $toDate,
        array &$rowsToInsert,
    ): array {
        $boardMap = $profile['monday']['user_board_map'] ?? [];

        if ($boardMap === []) {
            return ['status' => 'skipped', 'reason' => 'no user_board_map'];
        }

        $boardsByName = collect($client->listBoards())
            ->keyBy(fn ($b) => Str::lower(trim($b['name'] ?? '')));

        $details = [];
        $boardsResolved = 0;
        $itemsSaved = 0;

        foreach ($boardMap as $whitelistName => $kinds) {
            $user = $usersByWhitelistName[$whitelistName] ?? null;

            if (! $user) {
                $details[] = [
                    'user' => $whitelistName,
                    'status' => 'no_draxis_user',
                ];
                continue;
            }

            $userTotals = ['leads' => 0, 'follow_up' => 0, 'rejected' => 0];

            foreach (['new_leads' => 'leads', 'follow_up' => 'follow_up'] as $kind => $metricKey) {
                foreach ($kinds[$kind] ?? [] as $boardName) {
                    $board = $boardsByName->get(Str::lower(trim($boardName)));

                    if (! $board) {
                        $details[] = [
                            'user' => $whitelistName,
                            'board' => $boardName,
                            'status' => 'board_not_found',
                        ];
                        continue;
                    }

                    $boardsResolved++;
                    $boardId = (string) $board['id'];

                    ExternalIdMapping::updateOrCreate(
                        [
                            'provider' => 'monday',
                            'external_id' => $boardId,
                        ],
                        [
                            'user_id' => $user->id,
                            'metadata' => [
                                'company' => $company,
                                'board_name' => $board['name'],
                                'kind' => $kind,
                            ],
                        ],
                    );

                    $items = $client->getBoardItems($boardId);

                    foreach ($items as $item) {
                        $metricDate = $this->itemTimestampDate($item) ?? now()->toDateString();

                        // Save ALL board items into monday_items (like call_logs).
                        // Date filter is applied later when rebuilding metrics / API from=&to=.
                        $status = $this->extractStatusText($item);
                        $type = str_contains($status, 'reject') ? 'rejected' : $metricKey;

                        $rowsToInsert[] = $this->makeItemRow(
                            userId: $user->id,
                            company: $company,
                            item: $item,
                            boardId: $boardId,
                            boardName: (string) ($board['name'] ?? $boardName),
                            boardKind: $kind,
                            metricType: $type,
                            metricDate: $metricDate,
                            sourceLabel: null,
                        );

                        $userTotals[$type] = ($userTotals[$type] ?? 0) + 1;
                        $itemsSaved++;
                    }
                }
            }

            $details[] = [
                'user' => $whitelistName,
                'user_id' => $user->id,
                'leads' => $userTotals['leads'],
                'follow_up' => $userTotals['follow_up'],
                'rejected' => $userTotals['rejected'],
                'status' => 'synced',
            ];
        }

        return [
            'status' => 'synced',
            'boards_resolved' => $boardsResolved,
            'items_saved' => $itemsSaved,
            'details' => $details,
        ];
    }

    private function makeItemRow(
        int $userId,
        string $company,
        array $item,
        string $boardId,
        string $boardName,
        string $boardKind,
        string $metricType,
        string $metricDate,
        ?string $sourceLabel,
    ): array {
        $now = now();

        return [
            'user_id' => $userId,
            'company' => $company,
            'external_id' => (string) ($item['id'] ?? ''),
            'board_id' => $boardId,
            'board_name' => $boardName,
            'board_kind' => $boardKind,
            'group_title' => $item['group']['title'] ?? null,
            'metric_type' => $metricType,
            'item_name' => $item['name'] ?? null,
            'source_label' => $sourceLabel,
            'metric_date' => $metricDate,
            'raw' => json_encode($item),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function persistMondayItems(array $rows): int
    {
        $rows = array_values(array_filter($rows, fn ($r) => ($r['external_id'] ?? '') !== ''));

        if ($rows === []) {
            return 0;
        }

        // Deduplicate by external_id within this batch
        $unique = [];
        foreach ($rows as $row) {
            $unique[$row['external_id']] = $row;
        }
        $rows = array_values($unique);

        $ids = array_column($rows, 'external_id');
        $existing = DB::table('monday_items')
            ->whereIn('external_id', $ids)
            ->pluck('external_id')
            ->all();
        $existingSet = array_flip($existing);

        $toInsert = [];
        $toUpdate = 0;

        foreach ($rows as $row) {
            if (isset($existingSet[$row['external_id']])) {
                DB::table('monday_items')
                    ->where('external_id', $row['external_id'])
                    ->update([
                        'user_id' => $row['user_id'],
                        'company' => $row['company'],
                        'board_id' => $row['board_id'],
                        'board_name' => $row['board_name'],
                        'board_kind' => $row['board_kind'],
                        'group_title' => $row['group_title'],
                        'metric_type' => $row['metric_type'],
                        'item_name' => $row['item_name'],
                        'source_label' => $row['source_label'],
                        'metric_date' => $row['metric_date'],
                        'raw' => $row['raw'],
                        'updated_at' => $row['updated_at'],
                    ]);
                $toUpdate++;
                continue;
            }

            $toInsert[] = $row;
        }

        foreach (array_chunk($toInsert, 100) as $chunk) {
            DB::table('monday_items')->insert($chunk);
        }

        return count($toInsert) + $toUpdate;
    }

    private function findColumnIdByTitles(array $columns, array $titlesLower): ?string
    {
        foreach ($columns as $column) {
            $title = Str::lower(trim((string) ($column['title'] ?? '')));
            if (in_array($title, $titlesLower, true)) {
                return (string) $column['id'];
            }
        }

        return null;
    }

    private function columnText(array $item, string $columnId): string
    {
        foreach ($item['column_values'] ?? [] as $column) {
            if ((string) ($column['id'] ?? '') === $columnId) {
                return (string) ($column['text'] ?? '');
            }
        }

        return '';
    }

    private function extractDate(array $item, string $dateColumnId): ?string
    {
        $text = trim($this->columnText($item, $dateColumnId));

        if ($text !== '') {
            try {
                return Carbon::parse($text)->toDateString();
            } catch (\Throwable) {
            }
        }

        foreach ($item['column_values'] ?? [] as $column) {
            if ((string) ($column['id'] ?? '') !== $dateColumnId) {
                continue;
            }

            $raw = $column['value'] ?? null;
            if (! $raw) {
                continue;
            }

            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            $date = $decoded['date'] ?? null;

            if ($date) {
                try {
                    return Carbon::parse($date)->toDateString();
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    private function itemTimestampDate(array $item): ?string
    {
        $raw = $item['created_at'] ?? $item['updated_at'] ?? null;

        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractStatusText(array $item): string
    {
        foreach ($item['column_values'] ?? [] as $column) {
            $type = strtolower((string) ($column['type'] ?? ''));
            if (in_array($type, ['status', 'color'], true)) {
                $text = strtolower(trim((string) ($column['text'] ?? '')));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }
}

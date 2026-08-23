<?php

namespace App\Services\Integrations\Attendance;

use App\Models\AttendanceEvent;
use App\Services\Integrations\GoogleSheets\GoogleSheetsClient;
use App\Services\Integrations\UserMatcher;
use App\Support\AttendanceNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AttendanceSyncService
{
    public function __construct(
        private readonly GoogleSheetsClient $sheets,
        private readonly UserMatcher $matcher,
        private readonly AttendanceDayBuilder $dayBuilder,
        private readonly AttendanceMetricsAggregator $metrics,
    ) {}

    public function sync(?string $companyFilter = null): array
    {
        if (! $this->sheets->isConfigured()) {
            return [
                'status' => 'skipped',
                'reason' => 'GOOGLE_SERVICE_ACCOUNT_JSON or GOOGLE_SERVICE_ACCOUNT_PATH not configured',
            ];
        }

        $spreadsheetId = config('integrations.attendance.spreadsheet_id');
        $tabs = config('integrations.attendance.tabs', []);
        $punchTimezone = config('integrations.attendance.punch_timezone', 'Asia/Tashkent');
        $businessTimezone = config('integrations.attendance.timezone', 'America/Chicago');

        $summary = [
            'status' => 'synced',
            'spreadsheet_id' => $spreadsheetId,
            'punch_timezone' => $punchTimezone,
            'business_timezone' => $businessTimezone,
            'tabs' => [],
            'events_inserted' => 0,
            'events_updated' => 0,
            'events_skipped' => 0,
            'days_rebuilt' => 0,
            'unmatched_names' => [],
        ];

        foreach ($tabs as $tabName => $company) {
            if ($companyFilter && strtoupper($companyFilter) !== strtoupper($company)) {
                continue;
            }

            try {
                $tabSummary = $this->syncTab($spreadsheetId, $tabName, $company, $punchTimezone);
                $summary['tabs'][$tabName] = $tabSummary;
                $summary['events_inserted'] += $tabSummary['events_inserted'];
                $summary['events_updated'] += $tabSummary['events_updated'];
                $summary['events_skipped'] += $tabSummary['events_skipped'];
                $summary['days_rebuilt'] += $tabSummary['days_rebuilt'];
                $summary['unmatched_names'] = array_merge(
                    $summary['unmatched_names'],
                    $tabSummary['unmatched_names'] ?? [],
                );
            } catch (\Throwable $e) {
                Log::error('Attendance sync tab failed', [
                    'tab' => $tabName,
                    'company' => $company,
                    'error' => $e->getMessage(),
                ]);
                $summary['tabs'][$tabName] = [
                    'status' => 'error',
                    'error' => Str::limit($e->getMessage(), 240),
                ];
            }
        }

        $summary['unmatched_names'] = array_values(array_unique($summary['unmatched_names']));

        return $summary;
    }

    private function syncTab(string $spreadsheetId, string $tabName, string $company, string $punchTimezone): array
    {
        $rows = $this->sheets->getTabValues($spreadsheetId, $tabName);
        if ($rows === []) {
            return ['status' => 'empty', 'events_inserted' => 0, 'events_updated' => 0, 'events_skipped' => 0, 'days_rebuilt' => 0];
        }

        $headers = array_map(fn ($h) => trim((string) $h), $rows[0]);
        $colMap = $this->mapColumns($headers);

        $users = DB::table('users')->where('company', $company)->get();
        $affected = [];

        $summary = [
            'status' => 'synced',
            'company' => $company,
            'rows_seen' => max(0, count($rows) - 1),
            'events_inserted' => 0,
            'events_updated' => 0,
            'events_skipped' => 0,
            'days_rebuilt' => 0,
            'unmatched_names' => [],
        ];

        foreach (array_slice($rows, 1) as $index => $row) {
            $sheetRow = $index + 2;
            $parsed = $this->parseRow($row, $colMap, $tabName, $company, $sheetRow, $punchTimezone);
            if ($parsed === null) {
                $summary['events_skipped']++;
                continue;
            }

            $user = $this->findUser($users, $parsed['employee_name'], $company);
            if (! $user) {
                $summary['unmatched_names'][] = $parsed['employee_name'];
                $summary['events_skipped']++;
                continue;
            }

            $parsed['user_id'] = $user->id;
            $externalKey = hash('sha256', implode('|', [
                $tabName,
                (string) $sheetRow,
                $parsed['external_key_seed'],
            ]));

            $existing = AttendanceEvent::query()->where('external_key', $externalKey)->first();
            $payload = array_merge($parsed, ['external_key' => $externalKey]);

            if ($existing) {
                $existing->fill($payload)->save();
                $summary['events_updated']++;
            } else {
                AttendanceEvent::query()->create($payload);
                $summary['events_inserted']++;
            }

            if ($parsed['shift_date']) {
                $affected[$user->id][$parsed['shift_date']] = true;
            }
        }

        foreach ($affected as $userId => $dates) {
            foreach (array_keys($dates) as $date) {
                $this->dayBuilder->rebuildForUserDate((int) $userId, $company, $date);
                $this->metrics->rebuildForUserDate((int) $userId, $date);
                $summary['days_rebuilt']++;
            }
        }

        $summary['unmatched_names'] = array_values(array_unique($summary['unmatched_names']));

        return $summary;
    }

    private function mapColumns(array $headers): array
    {
        $expected = config('integrations.attendance.headers', []);
        $map = [];

        foreach ($expected as $key => $label) {
            foreach ($headers as $idx => $header) {
                if (strcasecmp($header, $label) === 0) {
                    $map[$key] = $idx;
                    break;
                }
            }
        }

        return $map;
    }

    private function parseRow(
        array $row,
        array $colMap,
        string $tabName,
        string $company,
        int $sheetRow,
        string $punchTimezone,
    ): ?array {
        $get = fn (string $key) => isset($colMap[$key], $row[$colMap[$key]])
            ? trim((string) $row[$colMap[$key]])
            : '';

        $name = $get('employee_name');
        if ($name === '') {
            return null;
        }

        $timeLocal = $get('time_local');
        $shiftDateRaw = $get('shift_date');
        $shiftTime = $get('shift_time');
        $action = $get('action');
        $lateMinutes = (int) preg_replace('/\D+/', '', $get('late_minutes')) ?: 0;
        $notes = $get('notes') ?: null;
        $didntCome = $get('didnt_come');
        $statusRaw = $get('status') ?: null;

        // Time Local = Face ID wall clock in Tashkent → store UTC
        $occurredAt = $this->parseDateTime($timeLocal, $punchTimezone);
        // Shift Date from sheet column F only — overnight outs stay on that shift day
        $shiftDate = $this->parseShiftDate($shiftDateRaw);

        if (! $shiftDate) {
            return null;
        }

        return [
            'company' => $company,
            'sheet_tab' => $tabName,
            'sheet_row' => $sheetRow,
            'employee_sheet_id' => $get('employee_id') ?: null,
            'employee_name' => $name,
            'action' => $action ?: null,
            'event_type' => AttendanceNormalizer::parseEventType($action),
            'occurred_at' => $occurredAt,
            'shift_date' => $shiftDate,
            'shift_time' => $shiftTime ?: null,
            'late_minutes' => $lateMinutes ?: null,
            'status_raw' => $statusRaw,
            'notes' => $notes,
            'didnt_come' => $didntCome ?: null,
            'raw' => [
                'row' => $row,
                'col_map' => $colMap,
            ],
            'external_key_seed' => implode('|', [$name, $timeLocal, $action, $shiftDateRaw, $shiftTime]),
        ];
    }

    private function parseDateTime(?string $value, string $punchTimezone): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value, $punchTimezone)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseShiftDate(?string $raw): ?string
    {
        if (! $raw || trim($raw) === '') {
            return null;
        }

        try {
            // Calendar date only — do not reinterpret via Chicago midnight
            return Carbon::parse(trim($raw))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function findUser($users, string $sheetName, string $company): ?object
    {
        $sheetNorm = AttendanceNormalizer::normalizeName($sheetName);

        foreach ($users as $user) {
            $full = AttendanceNormalizer::normalizeName(trim("{$user->first_name} {$user->last_name}"));
            if ($full && $sheetNorm && $full === $sheetNorm) {
                return $user;
            }

            if ($this->matcher->ownerMatchesUser([$sheetName], $user)) {
                return $user;
            }
        }

        $whitelist = config("integrations.companies.{$company}.whitelist", []);
        foreach ($whitelist as $entry) {
            $aliases = array_filter(array_merge(
                [$entry['name'] ?? ''],
                $entry['match_aliases'] ?? [],
                $entry['source_labels'] ?? [],
            ));

            foreach ($aliases as $alias) {
                if (AttendanceNormalizer::normalizeName($alias) !== $sheetNorm) {
                    continue;
                }

                $matched = $this->matcher->findUserByWhitelistName(
                    $company,
                    $entry['name'],
                    $entry['match_aliases'] ?? [],
                );

                if ($matched) {
                    return $matched;
                }
            }
        }

        return null;
    }
}

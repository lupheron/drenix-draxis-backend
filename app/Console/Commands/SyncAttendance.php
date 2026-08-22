<?php

namespace App\Console\Commands;

use App\Services\Integrations\Attendance\AttendanceSyncService;
use Illuminate\Console\Command;

class SyncAttendance extends Command
{
    protected $signature = 'sync:attendance {company? : Optional company filter (JM, WF, BP)}';

    protected $description = 'Sync HikVision attendance from Google Sheets';

    public function handle(AttendanceSyncService $service): int
    {
        $company = $this->argument('company');
        $company = $company ? strtoupper($company) : null;

        $this->info('Syncing attendance'.($company ? " for {$company}" : ' (all tabs)').'...');

        try {
            $summary = $service->sync($company);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (($summary['status'] ?? null) === 'skipped') {
            $this->warn($summary['reason'] ?? 'Skipped');

            return self::SUCCESS;
        }

        $this->table(
            ['Metric', 'Value'],
            collect($summary)
                ->except(['tabs', 'unmatched_names'])
                ->map(fn ($v, $k) => [$k, is_scalar($v) ? $v : json_encode($v)])
                ->values()
                ->all(),
        );

        foreach ($summary['tabs'] ?? [] as $tab => $tabSummary) {
            $this->line("Tab {$tab}: ".json_encode($tabSummary));
        }

        if (! empty($summary['unmatched_names'])) {
            $this->warn('Unmatched names: '.implode(', ', array_slice($summary['unmatched_names'], 0, 20)));
        }

        return self::SUCCESS;
    }
}

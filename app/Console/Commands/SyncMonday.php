<?php

namespace App\Console\Commands;

use App\Services\Integrations\Monday\MondaySyncService;
use Illuminate\Console\Command;

class SyncMonday extends Command
{
    protected $signature = 'sync:monday {company=JM : Company code (JM, BP, WF)}';

    protected $description = 'Sync Monday.com board items into employee_daily_metrics';

    public function handle(MondaySyncService $service): int
    {
        $company = strtoupper($this->argument('company'));

        $this->info("Syncing Monday for {$company}...");

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
            collect($summary)->except('details')->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->values()->all(),
        );

        foreach ($summary['details'] ?? [] as $detail) {
            $this->line(json_encode($detail));
        }

        return self::SUCCESS;
    }
}

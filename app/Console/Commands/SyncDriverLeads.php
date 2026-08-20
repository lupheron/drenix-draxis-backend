<?php

namespace App\Console\Commands;

use App\Services\Integrations\Monday\DriverLeadSyncService;
use Illuminate\Console\Command;

class SyncDriverLeads extends Command
{
    protected $signature = 'sync:driver-leads
                            {company=JM : Company code (JM, BP, WF)}';

    protected $description = 'Sync ALL Monday boards into driver_leads with strict exact-duplicate skipping';

    public function handle(DriverLeadSyncService $service): int
    {
        $company = strtoupper($this->argument('company'));
        $this->info("Syncing driver leads for {$company} from all Monday boards...");

        try {
            $summary = $service->sync($company);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Metric', 'Value'],
            collect($summary)
                ->except('errors')
                ->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])
                ->values()
                ->all(),
        );

        foreach ($summary['errors'] ?? [] as $error) {
            $this->warn(json_encode($error));
        }

        return self::SUCCESS;
    }
}

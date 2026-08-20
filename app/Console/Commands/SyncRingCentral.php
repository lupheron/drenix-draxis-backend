<?php

namespace App\Console\Commands;

use App\Services\Integrations\RingCentral\RingCentralMessageSyncService;
use App\Services\Integrations\RingCentral\RingCentralSyncService;
use Illuminate\Console\Command;

class SyncRingCentral extends Command
{
    protected $signature = 'sync:ringcentral
                            {company=JM : Company code (JM, BP, WF)}
                            {--full : Re-fetch full lookback window instead of incremental}
                            {--calls-only : Sync call logs only}
                            {--sms-only : Sync SMS/message store only}';

    protected $description = 'Sync RingCentral call logs + SMS into DB (incremental by default)';

    public function handle(
        RingCentralSyncService $calls,
        RingCentralMessageSyncService $messages,
    ): int {
        $company = strtoupper($this->argument('company'));
        $full = (bool) $this->option('full');
        $callsOnly = (bool) $this->option('calls-only');
        $smsOnly = (bool) $this->option('sms-only');

        if ($callsOnly && $smsOnly) {
            $this->error('Use only one of --calls-only or --sms-only.');

            return self::FAILURE;
        }

        $syncCalls = ! $smsOnly;
        $syncSms = ! $callsOnly;
        $mode = $full ? 'full' : 'incremental';

        if ($syncCalls) {
            $this->info('Syncing RingCentral calls for '.$company.' ('.$mode.')...');

            try {
                $summary = $calls->sync($company, $full);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->table(
                ['Metric', 'Value'],
                collect($summary)->except('details')->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->values()->all(),
            );

            foreach ($summary['details'] ?? [] as $detail) {
                $this->line(json_encode($detail));
            }
        }

        if ($syncSms) {
            $this->info('Syncing RingCentral SMS for '.$company.' ('.$mode.')...');

            try {
                $summary = $messages->sync($company, $full);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->table(
                ['Metric', 'Value'],
                collect($summary)->except('details')->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->values()->all(),
            );

            foreach ($summary['details'] ?? [] as $detail) {
                $this->line(json_encode($detail));
            }
        }

        return self::SUCCESS;
    }
}

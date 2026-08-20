<?php

namespace App\Console\Commands;

use App\Services\AccessRequestService;
use Illuminate\Console\Command;

class ExpireAccessAccounts extends Command
{
    protected $signature = 'access:expire';

    protected $description = 'Expire approved access accounts past their 3-month limit';

    public function handle(AccessRequestService $service): int
    {
        $count = $service->expireDueAccounts();

        $this->info("Expired {$count} access account(s).");

        return self::SUCCESS;
    }
}

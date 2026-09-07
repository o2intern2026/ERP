<?php

namespace App\Modules\Transport\Console;

use App\Modules\Transport\Services\TrackingSyncService;
use Illuminate\Console\Command;

class SyncTrackingCommand extends Command
{
    protected $signature = 'transport:sync-tracking';

    protected $description = 'Poll active carrier bookings and persist transport tracking updates';

    public function handle(TrackingSyncService $tracking): int
    {
        $count = $tracking->syncActive();
        $this->info(__('transport.tracking.synced', ['count' => $count]));

        return self::SUCCESS;
    }
}

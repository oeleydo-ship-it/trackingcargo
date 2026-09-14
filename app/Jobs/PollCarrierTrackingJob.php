<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Tracking\CarrierTrackingPollService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduled every five minutes (routes/console.php). ShouldBeUnique guards
 * against overlap if a poll run happens to take longer than the schedule
 * interval — CarrierTrackingPollService itself already loops every company,
 * so a second concurrent run would just duplicate work, not corrupt data.
 */
final class PollCarrierTrackingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue('tracking');
    }

    public function handle(CarrierTrackingPollService $poller): void
    {
        $poller->pollAll();
    }
}

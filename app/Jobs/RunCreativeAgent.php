<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Services\Agent\CreativeAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunCreativeAgent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public AgentRun $run) {}

    public function handle(CreativeAgent $agent): void
    {
        set_time_limit(0);

        $agent->run($this->run);
    }
}

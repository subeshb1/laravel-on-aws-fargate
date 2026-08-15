<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Written by the scheduler service once a minute.
 *
 * A scheduler that silently stops is one of the more expensive failures in a
 * Laravel deployment, because nothing errors: the jobs just never run. Writing
 * a row every minute turns that into something the status page can see.
 */
class Heartbeat extends Command
{
    protected $signature = 'app:heartbeat';

    protected $description = 'Record that the scheduler is alive';

    public function handle(): int
    {
        DB::table('heartbeats')->insert([
            'ran_at' => now(),
            'source' => gethostname(),
        ]);

        DB::table('heartbeats')
            ->where('ran_at', '<', now()->subHours(6))
            ->delete();

        $this->info('heartbeat recorded');

        return self::SUCCESS;
    }
}

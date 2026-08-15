<?php

use Illuminate\Support\Facades\Schedule;

// Runs inside the dedicated scheduler service. See infra/lib/laravel-fargate-stack.ts.
Schedule::command('app:heartbeat')->everyMinute();

// Realistic housekeeping that a SaaS actually schedules.
Schedule::command('queue:prune-failed --hours=48')->daily();
Schedule::command('queue:prune-batches --hours=48')->daily();

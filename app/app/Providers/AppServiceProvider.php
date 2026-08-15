<?php

namespace App\Providers;

use App\Support\RuntimeFacts;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // First time this runs in a given process is when the Octane worker
        // boots the application. Every later request reuses that same booted
        // application, so the value stays put and the difference from now() is
        // this worker's age.
        if (RuntimeFacts::$bootedAt === 0.0) {
            RuntimeFacts::$bootedAt = microtime(true);
        }
    }

    public function boot(): void
    {
        //
    }
}

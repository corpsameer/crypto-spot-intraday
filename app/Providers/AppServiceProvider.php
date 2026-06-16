<?php

namespace App\Providers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound('files')) {
            $this->app->singleton('files', fn (): Filesystem => new Filesystem());
        }
    }

    public function boot(): void
    {
        //
    }
}

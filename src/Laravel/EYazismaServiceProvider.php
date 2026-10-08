<?php

namespace BahriCanli\EYazisma\Laravel;

use Illuminate\Support\ServiceProvider;

class EYazismaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/eyazisma.php', 'eyazisma');

        $this->app->singleton(EYazismaManager::class, fn ($app) => new EYazismaManager($app['config']->get('eyazisma', [])));
        $this->app->alias(EYazismaManager::class, 'eyazisma');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/eyazisma.php' => $this->app->configPath('eyazisma.php')], 'eyazisma-config');
        }
    }
}

<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class TelegramBotTtsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tts.php', 'tts');

        // The tts:prune schedule is declared in config/tg_modules.php
        // (schedule) and registered by the module engine, with
        // schedule-overrides.php user overrides applied.
        $this->app->singleton(Observability\TtsMetricsExporter::class);
        $this->app->singleton(Provider\AdapterSelectorContract::class, Provider\DefaultAdapterSelector::class);
        $this->app->singleton(Guard\GuardStoreContract::class, Guard\RedisGuardStore::class);

        // Artisan commands are declared in config/tg_modules.php (commands)
        // and registered by the module engine.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}

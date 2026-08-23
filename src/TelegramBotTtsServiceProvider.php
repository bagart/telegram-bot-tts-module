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

        $this->app->singleton(Observability\TtsMetricsExporter::class);
        $this->app->singleton(Provider\AdapterSelectorContract::class, Provider\DefaultAdapterSelector::class);
        $this->app->singleton(Guard\GuardStoreContract::class, Guard\RedisGuardStore::class);

        // Composer-installed module discovery (config/telegram.php contract)
        $providers = (array) Config::get('telegram.modules_providers', []);
        Config::set('telegram.modules_providers', array_values(array_unique(array_merge(
            $providers,
            [TtsModule::class],
        ))));

        $this->commands([
            Console\TtsPruneCommand::class,
            Console\TtsDoctorCommand::class,
            Console\TtsBenchCommand::class,
        ]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}

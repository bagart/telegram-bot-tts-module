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

        // Composer-installed module discovery (config/telegram.php contract)
        $providers = (array) Config::get('telegram.modules_providers', []);
        Config::set('telegram.modules_providers', array_values(array_unique(array_merge(
            $providers,
            [TtsModule::class],
        ))));

        $this->commands([
            Console\TtsPruneCommand::class,
            Console\TtsDoctorCommand::class,
        ]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}

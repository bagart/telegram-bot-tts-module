<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts;

use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBotTts\Access\AccessService;
use BAGArt\TelegramBotTts\Guard\ChatSemaphore;
use BAGArt\TelegramBotTts\Guard\GlobalConcurrencyLimiter;
use BAGArt\TelegramBotTts\Guard\GuardStoreContract;
use BAGArt\TelegramBotTts\Guard\ProviderBreaker;
use BAGArt\TelegramBotTts\Guard\QuotaCounter;
use BAGArt\TelegramBotTts\Guard\TtsMetrics;
use BAGArt\TelegramBotTts\Media\FfmpegConverter;
use BAGArt\TelegramBotTts\Media\MediaUploader;
use BAGArt\TelegramBotTts\Processing\SpeechPipeline;
use BAGArt\TelegramBotTts\Provider\AdapterSelectorContract;
use BAGArt\TelegramBotTts\Provider\ConfigResolver;
use BAGArt\TelegramBotTts\Provider\ProviderRegistry;
use BAGArt\TelegramBotTts\Settings\TtsSettingsService;
use BAGArt\TelegramBotTts\Support\AudioFileStore;
use BAGArt\TelegramBotTts\Support\SynthesisRecorder;
use BAGArt\TelegramBotTts\Ui\MenuRenderer;
use BAGArt\TelegramBotTts\Ui\PendingInputService;

/**
 * Service-graph builder for the module. Module components are stateless, so
 * they are constructed per use instead of hidden global bindings; container-
 * managed contracts (sender, API client, enablement) come from app().
 */
final class ModuleFactory
{
    public static function moduleId(): string
    {
        return TtsModuleId::ID;
    }

    public static function inLaravel(): bool
    {
        return \function_exists('app') && app()->bound(TgSenderContract::class);
    }

    public static function settings(): TtsSettingsService
    {
        return app(TtsSettingsService::class);
    }

    public static function access(): AccessService
    {
        return new AccessService(app(TgBotApiDTOClientContract::class));
    }

    public static function registry(): ProviderRegistry
    {
        return new ProviderRegistry;
    }

    public static function menu(): MenuRenderer
    {
        return new MenuRenderer(self::registry());
    }

    public static function pending(): PendingInputService
    {
        return new PendingInputService((int) config('tts.pending_input_ttl_seconds', 900));
    }

    public static function guardStore(): GuardStoreContract
    {
        return app(GuardStoreContract::class);
    }

    public static function quotaCounter(): QuotaCounter
    {
        return new QuotaCounter(self::guardStore());
    }

    public static function chatSemaphore(): ChatSemaphore
    {
        return new ChatSemaphore(self::guardStore());
    }

    public static function concurrencyLimiter(): GlobalConcurrencyLimiter
    {
        return new GlobalConcurrencyLimiter(
            self::guardStore(),
            max(1, (int) config('tts.global_concurrency', 4)),
        );
    }

    public static function breaker(): ProviderBreaker
    {
        return new ProviderBreaker(self::guardStore());
    }

    public static function metrics(): TtsMetrics
    {
        return new TtsMetrics(self::guardStore());
    }

    public static function fileStore(): AudioFileStore
    {
        return new AudioFileStore(
            (string) config('tts.storage_path', storage_path('framework/tts')),
        );
    }

    public static function ffmpeg(): FfmpegConverter
    {
        return new FfmpegConverter;
    }

    public static function recorder(): SynthesisRecorder
    {
        return new SynthesisRecorder;
    }

    public static function configResolver(): ConfigResolver
    {
        return new ConfigResolver(self::registry());
    }

    public static function adapterSelector(): AdapterSelectorContract
    {
        return app(AdapterSelectorContract::class);
    }

    public static function uploader(): MediaUploader
    {
        return new MediaUploader(self::concurrencyLimiter(), self::metrics());
    }

    /**
     * The /voice execution pipeline wired for the current webhook context.
     */
    public static function pipeline(BotProcessorContext $context): SpeechPipeline
    {
        return self::pipelineWithSender($context->tgSender);
    }

    /**
     * Same pipeline with an explicit sender — used outside the webhook path
     * (tests, future cron/CLI entry points).
     */
    public static function pipelineWithSender(TgSenderContract $sender): SpeechPipeline
    {
        return new SpeechPipeline(
            sender: $sender,
            settingsService: self::settings(),
            configResolver: self::configResolver(),
            adapters: self::adapterSelector(),
            quota: self::quotaCounter(),
            chatSemaphore: self::chatSemaphore(),
            concurrency: self::concurrencyLimiter(),
            breaker: self::breaker(),
            metrics: self::metrics(),
            recorder: self::recorder(),
            files: self::fileStore(),
            ffmpeg: self::ffmpeg(),
            uploader: self::uploader(),
            budgetSeconds: max(10, (int) config('tts.budget_seconds', 30)),
        );
    }
}

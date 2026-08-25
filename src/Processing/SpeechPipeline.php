<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Processing;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBotTts\Guard\ChatSemaphore;
use BAGArt\TelegramBotTts\Guard\GlobalConcurrencyLimiter;
use BAGArt\TelegramBotTts\Guard\ProviderBreaker;
use BAGArt\TelegramBotTts\Guard\QuotaCounter;
use BAGArt\TelegramBotTts\Guard\TtsMetrics;
use BAGArt\TelegramBotTts\I18n\Strings;
use BAGArt\TelegramBotTts\Media\FfmpegConverter;
use BAGArt\TelegramBotTts\Media\MediaUploader;
use BAGArt\TelegramBotTts\Media\MimePolicy;
use BAGArt\TelegramBotTts\Media\VoiceDelivery;
use BAGArt\TelegramBotTts\Provider\AdapterSelectorContract;
use BAGArt\TelegramBotTts\Provider\ConfigResolver;
use BAGArt\TelegramBotTts\Provider\Dto\TtsRequest;
use BAGArt\TelegramBotTts\Provider\ErrorCode;
use BAGArt\TelegramBotTts\Provider\ProviderException;
use BAGArt\TelegramBotTts\Settings\TtsSettings;
use BAGArt\TelegramBotTts\Settings\TtsSettingsService;
use BAGArt\TelegramBotTts\Support\AudioFileStore;
use BAGArt\TelegramBotTts\Support\SynthesisRecorder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The /voice execution step machine (§7):
 *
 *   1 normalize input            6 synth via adapter (budget-capped)
 *   2 char cap? else refuse      7 maybe convert to voice-qualified mime
 *   3 cache hit? → upload        8 recorder->storeOk / metrics
 *   4 quota allow? else refuse   9 MediaUploader → sendVoice/sendAudio
 *   5 semaphore acquire
 *   finally: release semaphores, unlink tmpfile
 */
class SpeechPipeline
{
    public function __construct(
        private readonly TgSenderContract $sender,
        private readonly TtsSettingsService $settingsService,
        private readonly ConfigResolver $configResolver,
        private readonly AdapterSelectorContract $adapters,
        private readonly QuotaCounter $quota,
        private readonly ChatSemaphore $chatSemaphore,
        private readonly GlobalConcurrencyLimiter $concurrency,
        private readonly ProviderBreaker $breaker,
        private readonly TtsMetrics $metrics,
        private readonly SynthesisRecorder $recorder,
        private readonly AudioFileStore $files,
        private readonly FfmpegConverter $ffmpeg,
        private readonly MediaUploader $uploader,
        private readonly int $budgetSeconds,
    ) {
    }

    /**
     * @param  string  $trigger  'command' | 'auto'
     */
    public function speak(
        TgBotConfig $botConfig,
        int $chatId,
        int $userTgId,
        string $rawText,
        bool $isPrivateChat,
        string $trigger = 'command',
    ): void {
        $botId = (string) $botConfig->botId;
        $settings = $this->settingsService->get($botId, $chatId);
        $strings = fn (string $key, array $repl = []): string => Strings::get($settings->locale, $key, $repl);

        // 1–2 normalize + char cap
        $text = trim($rawText);

        if ($text === '') {
            return;
        }

        if (mb_strlen($text) > $settings->maxChars) {
            $this->notify($botConfig, $chatId, $strings('too_long', ['max' => $settings->maxChars]));

            return;
        }

        try {
            $config = $this->configResolver->resolve($botId, $settings);
        } catch (ProviderException $e) {
            Log::warning('TTS: provider config unusable', ['code' => $e->errorCode->value]);
            $this->sendErrorSurface($botConfig, $chatId, ErrorCode::BadRequest, $settings, $isPrivateChat);

            return;
        }

        $voice = $settings->voice ?? $config->voice;
        $cacheKey = SynthesisRecorder::cacheKey($config->key, $voice, $text);

        // 3 cache hit — zero provider calls, quota not consumed
        if ($this->serveFromCache($botConfig, $chatId, $botId, $cacheKey, $text, $settings)) {
            return;
        }

        // 4 daily quota (fail-closed on Redis loss)
        if (! $this->quota->allow($botId, $chatId, $settings->dailyQuota)) {
            $this->metrics->recordQuotaBlocked($botId);
            $this->notify($botConfig, $chatId, $strings('quota', ['quota' => $settings->dailyQuota]));

            return;
        }

        // 5 semaphores (per-chat fail-open, global fail-open)
        if (! $this->chatSemaphore->acquire($botId, $chatId)) {
            $this->notify($botConfig, $chatId, $strings('busy'));

            return;
        }

        if (! $this->concurrency->acquire()) {
            $this->chatSemaphore->release($botId, $chatId);
            $this->notify($botConfig, $chatId, $strings('busy'));

            return;
        }

        $tmpPath = null;

        try {
            // Breaker gate before spending provider budget
            if (! $this->breaker->canPass($config->key)) {
                throw new ProviderException(ErrorCode::Unavailable, sprintf('Breaker open for "%s"', $config->key));
            }

            $startedAt = microtime(true);

            // 6 synthesis
            $result = $this->adapters->for($config)->synthesize(new TtsRequest(
                text: $text,
                config: $config,
                voice: $voice,
                languageHint: null,
            ));

            if ((microtime(true) - $startedAt) > $this->budgetSeconds) {
                throw new ProviderException(ErrorCode::Unavailable, 'Budget watchdog aborted the request');
            }

            // 7 persist bytes so repeats are served without provider calls
            $tmpPath = $this->files->path($botId, $cacheKey, MimePolicy::extensionFor($result->mimeType));
            $this->files->write($tmpPath, $result->binary);

            // 8 bookkeeping
            $this->recorder->storeOk($botId, $cacheKey, $config->key, $voice, mb_strlen($text), $result);
            $this->breaker->recordSuccess($config->key);
            $this->metrics->recordSynthesis($botId, $config->key, 'ok');
            $this->metrics->recordLatency($config->key, $result->latencyMs);

            if (! $settings->noticeShown && $trigger === 'command') {
                $this->announceNotice($botConfig, $chatId, $config->key, $settings);
            }

            // 9 upload with mime branching (voice / convert / audio)
            $this->deliverVoiceOrAudio($botConfig, $chatId, $tmpPath, $result->mimeType, $settings->captionFor($text));
            $tmpPath = null;
        } catch (ProviderException $e) {
            $this->metrics->recordSynthesis($botId, $config->key, $e->errorCode->value);
            $this->metrics->recordProviderFailure($config->key);
            $this->breaker->recordFailure($config->key);
            $this->sendErrorSurface($botConfig, $chatId, $e->errorCode, $settings, $isPrivateChat);
        } catch (Throwable $e) {
            Log::warning('TTS: pipeline failure', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'at' => $e->getFile().':'.$e->getLine(),
            ]);
            $this->metrics->recordSynthesis($botId, $config->key, 'error');
            $this->sendErrorSurface($botConfig, $chatId, ErrorCode::Unavailable, $settings, $isPrivateChat);
        } finally {
            if ($tmpPath !== null) {
                $this->files->delete($tmpPath);
            }

            $this->concurrency->release();
            $this->chatSemaphore->release($botId, $chatId);
        }
    }

    private function serveFromCache(
        TgBotConfig $botConfig,
        int $chatId,
        string $botId,
        string $cacheKey,
        string $text,
        TtsSettings $settings,
    ): bool {
        $row = $this->recorder->lookup($botId, $cacheKey);

        if ($row === null) {
            return false;
        }

        $path = $this->files->path($botId, $cacheKey, MimePolicy::extensionFor($row->mime));

        if (! $this->files->exists($path)) {
            // Binary evicted — fall through to a fresh synthesis.
            return false;
        }

        $this->deliverVoiceOrAudio($botConfig, $chatId, $path, $row->mime, $settings->captionFor($text));

        return true;
    }

    /**
     * Mime branching (§3.2): ogg/mpeg/mp4 → SendVoice as-is; convertible
     * mimes go through ffmpeg when available; anything else falls back to
     * SendAudio.
     */
    private function deliverVoiceOrAudio(
        TgBotConfig $botConfig,
        int $chatId,
        string $path,
        string $mimeType,
        ?string $caption,
    ): void {
        $delivery = MimePolicy::deliveryFor($mimeType);

        if ($delivery === VoiceDelivery::Voice) {
            $this->uploader->sendVoiceOrAudio($botConfig, $chatId, $path, $mimeType, $caption, asVoice: true);

            return;
        }

        if ($delivery === VoiceDelivery::Convert && $this->ffmpeg->isAvailable()) {
            $converted = $this->ffmpeg->convertToOggOpus($path);

            try {
                $this->uploader->sendVoiceOrAudio($botConfig, $chatId, $converted, 'audio/ogg', $caption, asVoice: true);

                return;
            } finally {
                $this->files->delete($converted);
            }
        }

        $this->uploader->sendVoiceOrAudio($botConfig, $chatId, $path, $mimeType, $caption, asVoice: false);
    }

    /**
     * Third-party notice once per chat (§10.1); suppressed by admins by
     * pre-setting notice_shown=true through the panel flow.
     */
    private function announceNotice(TgBotConfig $botConfig, int $chatId, string $providerKey, TtsSettings $settings): void
    {
        try {
            $this->settingsService->patch((string) $botConfig->botId, $chatId, ['notice_shown' => true]);
        } catch (Throwable $e) {
            Log::warning('TTS: could not persist notice_shown flag', ['exception' => $e::class]);

            return;
        }

        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: Strings::get($settings->locale, 'notice', ['provider' => $providerKey]),
        ));
    }

    /**
     * Failure surface per settings mode (§7): silent | emoji | message.
     */
    private function sendErrorSurface(
        TgBotConfig $botConfig,
        int $chatId,
        ErrorCode $code,
        TtsSettings $settings,
        bool $isPrivateChat,
    ): void {
        $mode = $settings->resolvedErrorMode($isPrivateChat);
        $text = match ($mode) {
            TtsSettings::ERROR_MODE_SILENT => null,
            TtsSettings::ERROR_MODE_EMOJI => '😕',
            default => Strings::get($settings->locale, 'err.'.$code->value),
        };

        if ($text !== null) {
            $this->sendText($botConfig, $chatId, $text);
        }
    }

    /** Plain informational refusal (too long / busy / quota). */
    private function notify(TgBotConfig $botConfig, int $chatId, string $text): void
    {
        $this->sendText($botConfig, $chatId, $text);
    }

    private function sendText(TgBotConfig $botConfig, int $chatId, string $text): void
    {
        try {
            $this->sender->send($botConfig, new SendMessageMethodDTO(
                chatId: (string) $chatId,
                text: $text,
            ));
        } catch (Throwable) {
            // Never let a courtesy notification break the pipeline.
        }
    }
}

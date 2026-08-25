<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Media;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\Exceptions\ApiCommunication\TgApiNetworkException;
use BAGArt\TelegramBot\Exceptions\ApiCommunication\TgApiRateLimitException;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendAudioMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendVoiceMethodDTO;
use BAGArt\TelegramBotTts\Guard\GlobalConcurrencyLimiter;
use BAGArt\TelegramBotTts\Guard\TtsMetrics;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Voice delivery through the standard transport (Track B, todo.tts.md §6).
 *
 * Builds a SendVoice/SendAudio method DTO whose media field carries the
 * synthesized tmpfile as a `file://` path; the core client splits it into
 * ASKHttpRequest::$files at the send point and the transport uploads it as
 * multipart/form-data. No direct HTTP posting happens in this module.
 *
 * Own discipline (kept from the former Track A bypass): global upload
 * semaphore shared with the synthesis budget, single attempt + one retry on
 * transient failures, 429 Retry-After honoring (capped 30 s), metrics.
 */
class MediaUploader
{
    private const MAX_ATTEMPTS = 2;

    private const RETRY_DELAY_MS = 1500;

    private const RETRY_AFTER_CAP_SECONDS = 30;

    private const UPLOAD_TIMEOUT_SECONDS = 8;

    public function __construct(
        private readonly GlobalConcurrencyLimiter $concurrency,
        private readonly TtsMetrics $metrics,
        private readonly TgBotApiDTOClientContract $api,
    ) {
    }

    /**
     * Upload local audio file as a voice note (or audio document fallback)
     * to the chat. Throws on final failure; the caller owns the tmpfile.
     */
    public function sendVoiceOrAudio(
        TgBotConfig $botConfig,
        int $chatId,
        string $filePath,
        string $mimeType,
        ?string $caption = null,
        bool $asVoice = true,
    ): void {
        if (! is_file($filePath)) {
            throw new RuntimeException('Audio tmpfile is missing before upload');
        }

        if (! $this->concurrency->acquire()) {
            $this->metrics->recordUpload($botConfig->botId, 'busy');

            throw new RuntimeException('Upload concurrency cap reached');
        }

        try {
            $this->uploadWithDiscipline($botConfig, $chatId, $filePath, $mimeType, $caption, $asVoice);
        } finally {
            $this->concurrency->release();
        }
    }

    private function uploadWithDiscipline(
        TgBotConfig $botConfig,
        int $chatId,
        string $filePath,
        string $mimeType,
        ?string $caption,
        bool $asVoice,
    ): void {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $this->requestDelivery($botConfig, $chatId, $filePath, $mimeType, $caption, $asVoice);
                $this->metrics->recordUpload($botConfig->botId, 'ok');

                return;
            } catch (RateLimitedAfter $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    $this->metrics->recordUpload($botConfig->botId, 'rate_limited');
                    $this->rethrow('Telegram rate limited the upload', $e);
                }

                Sleep::for(min($e->retryAfterSeconds, self::RETRY_AFTER_CAP_SECONDS))->seconds();
            } catch (TgApiNetworkException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    $this->metrics->recordUpload($botConfig->botId, 'transport_error');
                    $this->rethrow('Upload transport error', $e);
                }

                Sleep::for(self::RETRY_DELAY_MS)->milliseconds();
            } catch (Throwable $e) {
                $this->metrics->recordUpload($botConfig->botId, 'error');
                $this->rethrow('Upload failed', $e);
            }
        }
    }

    private function requestDelivery(
        TgBotConfig $botConfig,
        int $chatId,
        string $filePath,
        string $mimeType,
        ?string $caption,
        bool $asVoice,
    ): void {
        // The file:// scheme marks the field for the mapper's Track B split:
        // it leaves the JSON body and travels as a multipart upload.
        $media = 'file://'.$filePath;
        $clampedCaption = ($caption !== null && $caption !== '') ? mb_substr($caption, 0, 1024) : null;

        $dto = $asVoice
            ? new SendVoiceMethodDTO(chatId: (string) $chatId, voice: $media, caption: $clampedCaption)
            : new SendAudioMethodDTO(chatId: (string) $chatId, audio: $media, caption: $clampedCaption);

        try {
            $response = $this->api->request(botConfig: $botConfig, dto: $dto, timeout: self::UPLOAD_TIMEOUT_SECONDS);

            if (! $response->ok) {
                throw new RuntimeException('Telegram API rejected the upload (not-ok response)');
            }
        } catch (TgApiRateLimitException $e) {
            throw new RateLimitedAfter(max(1, $e->getRetryAfter() ?? 1));
        }
    }

    private function rethrow(string $message, Throwable $previous): never
    {
        throw new RuntimeException($message.': '.$previous->getMessage(), 0, $previous);
    }
}

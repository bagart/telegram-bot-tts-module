<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Media;

use BAGArt\TelegramBotTts\Guard\GlobalConcurrencyLimiter;
use BAGArt\TelegramBotTts\Guard\TtsMetrics;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * ============================================================================
 * TRACK A — FENCED BYPASS. DO NOT COPY THIS PATTERN ELSEWHERE.
 * ============================================================================
 * The platform transport is JSON-only (ASKHttpRequest has no files support,
 * see docs/tasks/todo.tts.md §6), so freshly synthesized audio cannot travel
 * through the outbound queue/pipeline. This class posts multipart/form-data
 * DIRECTLY to api.telegram.org, bypassing the queue, rate limiter and DLQ.
 *
 * Accepted deliberately because:
 *  - volume is human-conversational and quota-capped at the source;
 *  - this uploader enforces its own discipline: global upload semaphore
 *    shared with the synthesis budget, single attempt + one retry on
 *    transport error, 429 Retry-After honoring (capped 30 s), metrics.
 *
 * The bypass MUST stay fenced inside this one class (grep-guarded by
 * tests/Guard/TrackAFenceTest.php): no other module code may reference
 * SendVoice/SendAudio upload paths or api.telegram.org directly.
 *
 * TRACK B (post-MVP core-lib upgrade) will add `array $files` to
 * ASKHttpRequest + transports; afterwards this class migrates onto
 * TgBotApiDTOClientContract and the fence is deleted.
 * ============================================================================
 */
class MediaUploader
{
    private const MAX_ATTEMPTS = 2;

    private const RETRY_DELAY_MS = 1500;

    private const RETRY_AFTER_CAP_SECONDS = 30;

    private const UPLOAD_TIMEOUT_SECONDS = 8;

    private const TELEGRAM_API_BASE = 'https://api.telegram.org';

    public function __construct(
        private readonly GlobalConcurrencyLimiter $concurrency,
        private readonly TtsMetrics $metrics,
    ) {}

    /**
     * Upload local audio file as a voice note (or audio document fallback)
     * to the chat. Throws on final failure; the caller owns the tmpfile.
     */
    public function sendVoiceOrAudio(
        string $botToken,
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
            $this->metrics->recordUpload((string) explode(':', $botToken)[0], 'busy');

            throw new RuntimeException('Upload concurrency cap reached');
        }

        try {
            $this->uploadWithDiscipline($botToken, $chatId, $filePath, $mimeType, $caption, $asVoice);
        } finally {
            $this->concurrency->release();
        }
    }

    private function uploadWithDiscipline(
        string $botToken,
        int $chatId,
        string $filePath,
        string $mimeType,
        ?string $caption,
        bool $asVoice,
    ): void {
        $botId = explode(':', $botToken)[0];
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $this->postMultipart($botToken, $chatId, $filePath, $mimeType, $caption, $asVoice);
                $this->metrics->recordUpload($botId, 'ok');

                return;
            } catch (RateLimitedAfter $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    $this->metrics->recordUpload($botId, 'rate_limited');
                    $this->rethrow('Telegram rate limited the upload', $e);
                }

                Sleep::for(min($e->retryAfterSeconds, self::RETRY_AFTER_CAP_SECONDS))->seconds();
            } catch (ConnectionException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    $this->metrics->recordUpload($botId, 'transport_error');
                    $this->rethrow('Upload transport error', $e);
                }

                Sleep::for(self::RETRY_DELAY_MS)->milliseconds();
            } catch (\Throwable $e) {
                $this->metrics->recordUpload($botId, 'error');
                $this->rethrow('Upload failed', $e);
            }
        }
    }

    private function postMultipart(
        string $botToken,
        int $chatId,
        string $filePath,
        string $mimeType,
        ?string $caption,
        bool $asVoice,
    ): void {
        $field = $asVoice ? 'voice' : 'audio';
        $method = $asVoice ? 'sendVoice' : 'sendAudio';
        $fileName = 'tts.'.MimePolicy::extensionFor($mimeType);
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Cannot open audio tmpfile for upload');
        }

        try {
            $request = Http::baseUrl(self::TELEGRAM_API_BASE)
                ->connectTimeout(10)
                ->timeout(self::UPLOAD_TIMEOUT_SECONDS)
                ->attach($field, $handle, $fileName, ['Content-Type' => $mimeType]);

            $payload = ['chat_id' => (string) $chatId];

            if ($caption !== null && $caption !== '') {
                $payload['caption'] = mb_substr($caption, 0, 1024);
            }

            $response = $request->post(sprintf('/bot%s/%s', $botToken, $method), $payload);

            if ($response->status() === 429) {
                throw new RateLimitedAfter(max(1, (int) $response->header('retry-after')));
            }

            if ($response->failed()) {
                // Bytes are never logged; status only.
                Log::warning('TTS upload failed', [
                    'method' => $method,
                    'status' => $response->status(),
                    'bot_id' => explode(':', $botToken)[0],
                ]);

                throw new RuntimeException('Telegram API rejected the upload (HTTP '.$response->status().')');
            }

            $body = json_decode((string) $response->body(), true);

            if (($body['ok'] ?? null) !== true) {
                throw new RuntimeException('Telegram API returned not-ok for '.$method);
            }
        } finally {
            fclose($handle);
        }
    }

    private function rethrow(string $message, \Throwable $previous): neve
    {
        throw new RuntimeException($message.': '.$previous->getMessage(), 0, $previous);
    }
}

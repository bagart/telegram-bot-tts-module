<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider\Adapter;

use BAGArt\TelegramBotTts\Provider\Dto\TtsRequest;
use BAGArt\TelegramBotTts\Provider\Dto\TtsResult;
use BAGArt\TelegramBotTts\Provider\ErrorCode;
use BAGArt\TelegramBotTts\Provider\ProviderException;
use BAGArt\TelegramBotTts\Provider\TtsProviderContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * OpenAI Text-to-speech dialect driver: POST {base}/audio/speech with
 * {model,input,voice,response_format,speed}, 200 = binary audio body.
 *
 * HTTP discipline (LlmClient precedent): MAX_ATTEMPTS=2, Retry-After cap
 * 30 s, connect timeout 10 s, size-capped body, token never logged.
 */
class OpenAiCompatibleTts implements TtsProviderContract
{
    private const MAX_ATTEMPTS = 2;

    private const RETRY_DELAY_MS = 1500;

    private const RETRY_AFTER_CAP_SECONDS = 30;

    public function synthesize(TtsRequest $request): TtsResult
    {
        $config = $request->config;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->attempt($request);
            } catch (RateLimitedException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new ProviderException(ErrorCode::RateLimited, 'TTS provider rate limited', $e);
                }

                Sleep::for(min($e->retryAfterSeconds, self::RETRY_AFTER_CAP_SECONDS))->seconds();
            } catch (ConnectionException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new ProviderException(ErrorCode::Unavailable, 'TTS connection failed', $e);
                }

                Sleep::for(self::RETRY_DELAY_MS)->milliseconds();
            } catch (ProviderException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new ProviderException(ErrorCode::Unavailable, 'TTS call failed: '.$e::class, $e);
            }
        }
    }

    private function attempt(TtsRequest $request): TtsResult
    {
        $config = $request->config;

        if ($config->model === null || $config->model === '') {
            throw new ProviderException(ErrorCode::BadRequest, 'Model is not configured for the selected provider');
        }

        $http = Http::baseUrl($config->baseUrl)
            ->connectTimeout($config->connectTimeoutSec)
            ->timeout($config->timeoutSec)
            ->asJson();

        if ($config->token !== null && $config->token !== '') {
            $http = $http->withToken($config->token);
        }

        $startedAt = microtime(true);
        $response = $http->post('/audio/speech', [
            'model' => $config->model,
            'input' => $request->text,
            'voice' => $request->voice ?? $config->voice ?? 'alloy',
            'response_format' => 'mp3',
        ]);
        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ProviderException(ErrorCode::Auth, 'TTS provider rejected the key');
        }

        if ($response->status() === 429) {
            throw new RateLimitedException(max(1, (int) $response->header('retry-after')));
        }

        if ($response->status() === 400 || $response->status() === 422) {
            throw new ProviderException(ErrorCode::UnsupportedInput, 'TTS provider rejected the request body');
        }

        if ($response->status() === 404) {
            throw new ProviderException(ErrorCode::BadRequest, 'TTS endpoint not found — check base_url/model');
        }

        if ($response->failed()) {
            throw new ProviderException(ErrorCode::Unavailable, 'TTS provider error HTTP '.$response->status());
        }

        $body = (string) $response->body();

        if ($body === '') {
            throw new ProviderException(ErrorCode::EmptyResult, 'TTS provider returned an empty body');
        }

        if (strlen($body) > $config->maxResponseBytes) {
            throw new ProviderException(ErrorCode::PayloadTooLarge, 'TTS response exceeds size limit');
        }

        return new TtsResult(
            binary: $body,
            mimeType: self::normalizeMime((string) $response->header('Content-Type')),
            providerKey: $config->key,
            latencyMs: $latencyMs,
        );
    }

    public static function normalizeMime(string $contentType): string
    {
        $mime = strtolower(trim(explode(';', $contentType)[0]));

        return $mime === '' ? 'application/octet-stream' : $mime;
    }
}

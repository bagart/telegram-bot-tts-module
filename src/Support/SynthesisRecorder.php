<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Support;

use BAGArt\TelegramBotTts\Models\TtsAudioCache;
use BAGArt\TelegramBotTts\Provider\Dto\TtsResult;

/**
 * Synthesis cache read/write (§7: repeat synthesis served from
 * tts_audio_cache so identical /voice costs zero provider calls). Rows hold
 * metadata only — binaries are re-synthesized when evicted.
 */
class SynthesisRecorder
{
    public static function cacheKey(string $providerKey, ?string $voice, string $normalizedText): string
    {
        return sha1($providerKey.'|'.($voice ?? '').'|'.$normalizedText);
    }

    /**
     * @return TtsAudioCache|null cached metadata row (mime/size) on hit
     */
    public function lookup(string $botId, string $cacheKey): ?TtsAudioCache
    {
        $row = TtsAudioCache::query()
            ->where('bot_id', $botId)
            ->where('cache_key', $cacheKey)
            ->first();

        if ($row === null) {
            return null;
        }

        $row->use_count = (int) $row->use_count + 1;
        $row->last_used_at = now();
        $row->save();

        return $row;
    }

    public function storeOk(string $botId, string $cacheKey, string $providerKey, ?string $voice, int $chars, TtsResult $result): void
    {
        try {
            TtsAudioCache::query()->updateOrCreate(
                ['bot_id' => $botId, 'cache_key' => $cacheKey],
                [
                    'provider_key' => $providerKey,
                    'voice' => $voice,
                    'chars' => $chars,
                    'mime' => $result->mimeType,
                    'size_bytes' => strlen($result->binary),
                    'latency_ms' => $result->latencyMs,
                    'last_used_at' => now(),
                ],
            );
        } catch (\Throwable) {
            // Cache persistence must never fail the request path.
        }
    }

    /**
     * Prune rows untouched for longer than the retention window; returns
     * deleted row count.
     */
    public function prune(int $retentionDays): int
    {
        return TtsAudioCache::query()
            ->where('last_used_at', '<', now()->subDays(max(1, $retentionDays)))
            ->delete();
    }
}

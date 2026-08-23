<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Observability;

use BAGArt\TelegramBotTts\ModuleFactory;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Bridges module-local Redis counters into the host /health/metrics
 * exposition (RFC §12). Counters live under well-known key layouts written
 * by TtsMetrics; this class scans them and renders Prometheus text samples
 * with bot_id/provider labels. Redis loss yields an empty series — never an
 * error for the scrape target.
 */
class TtsMetricsExporter
{
    /** @var list<string> */
    private const SCAN_PATTERNS = [
        'tts:stat:*',
        'tts:lat:*',
        'tts:qblocked:*',
        'tts:up:*',
    ];

    /**
     * @return list<string> Prometheus text-format lines (possibly empty)
     */
    public function prometheusLines(): array
    {
        try {
            $counters = $this->scanCounters();
            $breakerPhases = $this->breakerPhases();
        } catch (Throwable) {
            return [];
        }

        return self::buildLines($counters, $breakerPhases);
    }

    /**
     * Pure renderer (unit-tested): counter map + breaker phases → lines.
     *
     * Counter keys follow TtsMetrics layouts:
     *   tts:stat:{botId}:{providerKey}:{status}
     *   tts:stat:failures:{providerKey}
     *   tts:lat:{providerKey}:{bucket}
     *   tts:qblocked:{botId}
     *   tts:up:{botId}:{status}
     *
     * @param  array<string, int>  $counters
     * @param  array<string, int>  $breakerPhases  providerKey → 0|1|2
     * @return list<string>
     */
    public static function buildLines(array $counters, array $breakerPhases): array
    {
        $lines = [];

        $emit = function (string $name, string $help, string $type, string $labels, int $value) use (&$lines): void {
            if (! isset($lines['#'.$name])) {
                $lines['#'.$name] = "# HELP {$name} {$help}\n# TYPE {$name} {$type}";
            }

            $lines[] = "{$name}{$labels} {$value}";
        };

        foreach ($counters as $key => $value) {
            $parts = explode(':', $key);

            if (str_starts_with($key, 'tts:stat:') && count($parts) === 5 && $parts[2] !== 'failures') {
                [, , $botId, $providerKey, $status] = $parts;
                $emit(
                    'tts_total',
                    'TTS synthesis attempts by outcome.',
                    'counter',
                    sprintf('{bot_id="%s",provider="%s",status="%s"}', self::escape($botId), self::escape($providerKey), self::escape($status)),
                    $value,
                );

                continue;
            }

            if (str_starts_with($key, 'tts:stat:failures:')) {
                $providerKey = $parts[3] ?? '';
                $emit(
                    'tts_provider_failures_last24h',
                    'Aggregated provider failures in the last 24h.',
                    'gauge',
                    sprintf('{provider="%s"}', self::escape($providerKey)),
                    $value,
                );

                continue;
            }

            if (str_starts_with($key, 'tts:lat:')) {
                $providerKey = $parts[2] ?? '';
                $bucket = $parts[3] ?? '';
                $emit(
                    'tts_latency_bucket',
                    'Synthesis latency distribution (coarse buckets).',
                    'counter',
                    sprintf('{provider="%s",le="%s"}', self::escape($providerKey), self::escape($bucket)),
                    $value,
                );

                continue;
            }

            if (str_starts_with($key, 'tts:qblocked:')) {
                $emit(
                    'tts_quota_blocked_total',
                    'Requests refused by the daily chat quota.',
                    'counter',
                    sprintf('{bot_id="%s"}', self::escape($parts[2] ?? '')),
                    $value,
                );

                continue;
            }

            if (str_starts_with($key, 'tts:up:')) {
                $emit(
                    'tts_upload_total',
                    'Track A multipart upload outcomes.',
                    'counter',
                    sprintf('{bot_id="%s",status="%s"}', self::escape($parts[2] ?? ''), self::escape($parts[3] ?? '')),
                    $value,
                );
            }
        }

        $phaseNames = [0 => 'closed', 1 => 'open', 2 => 'half_open'];

        foreach ($breakerPhases as $providerKey => $phase) {
            $emit(
                'tts_breaker',
                'Provider circuit-breaker phase (closed/open/half_open).',
                'gauge',
                sprintf('{provider="%s",phase="%s"}', self::escape((string) $providerKey), $phaseNames[$phase] ?? 'unknown'),
                $phase,
            );
        }

        // Reorder so each metric's HELP/TYPE block precedes its samples.
        $ordered = [];
        $seen = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }

            $name = (string) strstr($line, '{', true);

            if ($name === '') {
                $name = strtok($line, ' ');
            }

            $metaKey = '#'.$name;

            if (! isset($seen[$metaKey]) && isset($lines[$metaKey])) {
                $ordered[] = $lines[$metaKey];
                $seen[$metaKey] = true;
            }

            $ordered[] = $line;
        }

        return $ordered;
    }

    /**
     * @return array<string, int>
     */
    private function scanCounters(): array
    {
        $redis = Redis::connection();
        $counters = [];

        foreach (self::SCAN_PATTERNS as $pattern) {
            $iterator = null;

            do {
                $batch = $redis->scan($iterator, ['MATCH' => $pattern, 'COUNT' => 200]);

                if ($batch !== false) {
                    foreach ($batch as $key) {
                        $raw = $redis->get($key);

                        if ($raw !== false && $raw !== null) {
                            $counters[(string) $key] = (int) $raw;
                        }
                    }
                }
            } while ($iterator > 0);
        }

        ksort($counters);

        return $counters;
    }

    /**
     * @return array<string, int>
     */
    private function breakerPhases(): array
    {
        $phases = [];
        $breaker = ModuleFactory::breaker();

        foreach (array_keys(ModuleFactory::registry()->all()) as $presetKey) {
            $phases[$presetKey] = $breaker->phase($presetKey);
        }

        return $phases;
    }

    private static function escape(string $labelValue): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $labelValue);
    }
}

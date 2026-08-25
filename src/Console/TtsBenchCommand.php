<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Console;

use BAGArt\TelegramBotTts\ModuleFactory;
use BAGArt\TelegramBotTts\Provider\Adapter\EdgeTts;
use BAGArt\TelegramBotTts\Provider\Adapter\OpenAiCompatibleTts;
use BAGArt\TelegramBotTts\Provider\Dto\TtsRequest;
use BAGArt\TelegramBotTts\Provider\TtsApiStyle;
use BAGArt\TelegramBotTts\Settings\TtsSettings;
use BAGArt\TelegramBotTts\TtsModuleId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Latency profile bench (§16 Phase 4): N direct synthesis calls against the
 * resolved provider, bucket distribution + p50/p95. No Telegram upload —
 * measures provider+driver only. Reads the effective chat config so the
 * bench reflects what real users get; override with --provider.
 */
class TtsBenchCommand extends Command
{
    protected $signature = 'tts:bench
        {--bot= : bot_id whose settings/vault to resolve}
        {--chat=0 : chat_id whose settings to resolve}
        {--provider= : override provider key}
        {--count=10 : number of synthesis calls}
        {--text= : custom text (default: sentence with Cyrillic+Latin)}';

    protected $description = 'Benchmark TTS provider latency (no Telegram upload)';

    public function handle(): int
    {
        $botId = (string) $this->option('bot');

        if ($botId === '' || ! DB::table('tg_bots')->where('bot_id', $botId)->exists()) {
            $this->error('--bot must reference an existing tg_bots row');

            return self::FAILURE;
        }

        // A synthetic TgBotConfig is not needed for direct adapter calls:
        // ConfigResolver only needs botId + settings + vault.
        $settings = ModuleFactory::settings()->get($botId, (int) $this->option('chat'));

        if (($override = (string) $this->option('provider')) !== '') {
            if (! ModuleFactory::registry()->has($override)) {
                $this->error("Unknown provider \"{$override}\"");

                return self::FAILURE;
            }

            $settings = new TtsSettings(
                autoSpeak: $settings->autoSpeak,
                providerKey: $override,
                voice: $settings->voice,
                caption: $settings->caption,
                maxChars: $settings->maxChars,
                onError: $settings->onError,
                dailyQuota: 0,
                locale: $settings->locale,
                noticeShown: true,
                customProvider: $override === 'custom' ? $settings->customProvider : null,
            );
        }

        try {
            $config = ModuleFactory::configResolver()->resolve($botId, $settings);
        } catch (\Throwable $e) {
            $this->error('Config resolve failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $adapter = match ($config->apiStyle) {
            TtsApiStyle::EdgeTts => new EdgeTts(),
            TtsApiStyle::OpenaiTts => new OpenAiCompatibleTts(),
        };

        $text = (string) ($this->option('text') ?: 'Проверка задержки синтеза речи, benchmark sample 123.');
        $count = max(1, min(100, (int) $this->option('count')));

        $this->info(sprintf(
            'bench %s → %s [%s] model=%s voice=%s x%d',
            TtsModuleId::ID,
            $config->baseUrl,
            $config->apiStyle->value,
            $config->model ?? '-',
            $config->voice ?? '-',
            $count,
        ));

        $latencies = [];
        $failures = 0;

        for ($i = 1; $i <= $count; $i++) {
            $startedAt = microtime(true);

            try {
                $result = $adapter->synthesize(new TtsRequest(text: $text, config: $config));
                $latencies[] = (int) ((microtime(true) - $startedAt) * 1000);
                $this->line(sprintf('#%02d ok    %5d ms  %6.1f KB %s', $i, $result->latencyMs, strlen($result->binary) / 1024, $result->mimeType));
            } catch (\Throwable $e) {
                $failures++;
                $this->line(sprintf('#%02d FAIL  %s', $i, $e->getMessage()));
            }
        }

        if ($latencies === []) {
            $this->error('All calls failed — nothing to profile');

            return self::FAILURE;
        }

        sort($latencies);
        $p = fn (float $q): int => (int) $latencies[min(count($latencies) - 1, (int) floor($q * count($latencies)))];

        $buckets = ['≤250' => 0, '251–1000' => 0, '1001–5000' => 0, '5001–15000' => 0, '>15000' => 0];

        foreach ($latencies as $ms) {
            $buckets[match (true) {
                $ms <= 250 => '≤250',
                $ms <= 1000 => '251–1000',
                $ms <= 5000 => '1001–5000',
                $ms <= 15000 => '5001–15000',
                default => '>15000',
            }]++;
        }

        $this->table(['metric', 'value'], [
            ['calls ok/total', count($latencies).'/'.$count],
            ['failures', (string) $failures],
            ['min ms', (string) $latencies[0]],
            ['p50 ms', (string) $p(0.5)],
            ['p95 ms', (string) $p(0.95)],
            ['max ms', (string) $latencies[count($latencies) - 1]],
            ...array_map(fn (string $k, int $v): array => ["bucket {$k}", (string) $v], array_keys($buckets), $buckets),
        ]);

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}

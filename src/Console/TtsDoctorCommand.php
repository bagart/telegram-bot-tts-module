<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Console;

use BAGArt\TelegramBotTts\Guard\RedisGuardStore;
use BAGArt\TelegramBotTts\Media\FfmpegConverter;
use BAGArt\TelegramBotTts\Models\TtsToken;
use BAGArt\TelegramBotTts\ModuleFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Operational doctor (§12): migrations, ffmpeg, presets sanity, vault
 * tokens, breaker phases, Redis reachability, last-24h failure counts and
 * the Track A fence grep-guard. Exit codes follow the CLI contract
 * (0 ok … 5 policy failure).
 */
class TtsDoctorCommand extends Command
{
    protected $signature = 'tts:doctor {--bot= : bot_id to check vault tokens for}';

    protected $description = 'Health check for the TTS module';

    private const EXIT_POLICY_FAILURE = 5;

    public function handle(FfmpegConverter $ffmpeg): int
    {
        $problems = 0;
        $registry = ModuleFactory::registry();

        // Migrations
        foreach (['tts_tokens', 'tts_audio_cache'] as $table) {
            if (Schema::hasTable($table)) {
                $this->line("✔ table {$table} present");
            } else {
                $this->error("✖ table {$table} missing — run php artisan migrate");
                $problems++;
            }
        }

        // ffmpeg
        $version = $ffmpeg->version();

        if ($version !== null) {
            $this->line("✔ ffmpeg: {$version}");
        } else {
            $this->warn('! ffmpeg not found — non-voice mimes will be sent as audio documents');
        }

        // Presets sanity
        foreach ($registry->all() as $preset) {
            $this->line(sprintf(
                '✔ preset %s → %s [%s]',
                $preset->key,
                $preset->baseUrl,
                $preset->apiStyle->value,
            ));
        }

        // Redis + breaker states + failures
        try {
            app(RedisGuardStore::class)->get('tts:doctor:probe');
            $this->line('✔ redis reachable');

            $breaker = ModuleFactory::breaker();
            $phaseNames = [0 => 'closed', 1 => 'open', 2 => 'half-open'];

            foreach ($registry->all() as $preset) {
                $phase = $breaker->phase($preset->key);
                $this->line(sprintf('✔ breaker %s: %s', $preset->key, $phaseNames[$phase]));
            }

            $failures = ModuleFactory::metrics()->failuresByProvider(array_keys($registry->all()));

            foreach ($failures as $providerKey => $count) {
                if ($count > 0) {
                    $this->warn("! provider {$providerKey}: {$count} recent failure(s)");
                }
            }
        } catch (RuntimeException $e) {
            $this->warn('! redis unreachable — quotas fail closed, breakers read closed');
        }

        // Vault tokens for the selected bot
        $botId = (string) $this->option('bot');

        if ($botId !== '') {
            $keys = TtsToken::query()
                ->where('bot_id', $botId)
                ->pluck('provider_key')
                ->all();

            if ($keys === []) {
                $this->warn("! no vault tokens for bot {$botId} (paid presets will fail with AUTH)");
            } else {
                $this->line('✔ vault tokens for: '.implode(', ', $keys));
            }
        }

        // Track A fence (§6): send paths must live only inside MediaUploade
        $fence = $this->checkFence();

        if ($fence === []) {
            $this->line('✔ Track A fence intact');
        } else {
            $this->error('✖ Track A fence violated by: '.implode(', ', $fence));
            $problems++;
        }

        if ($problems > 0) {
            $this->error("tts:doctor — {$problems} problem(s)");

            return self::EXIT_POLICY_FAILURE;
        }

        $this->info('tts:doctor — all checks passed');

        return self::SUCCESS;
    }

    /**
     * Grep-guard (Track B): no src/ class may reference Telegram send-voice
     * upload literals — delivery goes through the core DTO client, which
     * splits `file://` media fields into the transport's multipart body.
     * Provider HTTP calls are unaffected.
     *
     * @return list<string> offending class names
     */
    private function checkFence(): array
    {
        $srcDir = dirname(__DIR__, 2).'/src';
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // This command contains the needle literals themselves.
            if ($file->getBasename() === basename(__FILE__)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (str_contains($contents, "'sendVoice'")
                || str_contains($contents, '"sendVoice"')
                || str_contains($contents, 'api.telegram.org')
                || str_contains($contents, 'Http::attach')
            ) {
                $offenders[] = $file->getBasename();
            }
        }

        return $offenders;
    }
}

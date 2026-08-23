<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Console;

use BAGArt\TelegramBotTts\Support\AudioFileStore;
use BAGArt\TelegramBotTts\Support\SynthesisRecorder;
use Illuminate\Console\Command;

/**
 * Retention sweep (§10.5): cache rows and disk files untouched longer than
 * the retention window. Schedule from the host routes/console.php.
 */
class TtsPruneCommand extends Command
{
    protected $signature = 'tts:prune';

    protected $description = 'Prune expired TTS audio cache rows, disk files and stale runtime state';

    public function handle(SynthesisRecorder $recorder, AudioFileStore $files): int
    {
        $retentionDays = max(1, (int) config('tts.retention_days', 30));

        $rows = $recorder->prune($retentionDays);
        $removed = $files->prune($retentionDays);

        $this->info(sprintf(
            'tts:prune — removed %d cache row(s), %d file(s), retention %d day(s)',
            $rows,
            $removed,
            $retentionDays,
        ));

        return self::SUCCESS;
    }
}

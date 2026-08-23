<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Support;

use RuntimeException;

/**
 * Disk home of synthesized audio: storage/framework/tts/{botId}/{cacheKey}.{ext},
 * mode 0600, swept by mtime during tts:prune (§10.5). The DB cache rows hold
 * metadata only — this store owns the bytes.
 */
class AudioFileStore
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    public function path(string $botId, string $cacheKey, string $extension): string
    {
        return sprintf('%s/%s/%s.%s', $this->basePath, $botId, preg_replace('/[^a-f0-9]/', '', $cacheKey), $extension);
    }

    public function exists(string $path): bool
    {
        return is_file($path);
    }

    public function read(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException('Cannot read cached audio: file is missing');
        }

        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new RuntimeException('Cannot read cached audio');
        }

        // Touch so retention counts from last use, not first synthesis.
        @touch($path);

        return $bytes;
    }

    public function write(string $path, string $binary): void
    {
        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0770, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create TTS storage directory');
        }

        // Atomic publish: concurrent identical requests may synthesize in
        // parallel; rename guarantees readers never see a half-written file.
        $tmpPath = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($tmpPath, $binary) === false) {
            throw new RuntimeException('Cannot persist audio file');
        }

        chmod($tmpPath, 0600);

        if (! @rename($tmpPath, $path)) {
            @unlink($tmpPath);

            throw new RuntimeException('Cannot publish audio file');
        }
    }

    public function delete(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Sweep files untouched longer than the retention window. */
    public function prune(int $retentionDays): int
    {
        if (! is_dir($this->basePath)) {
            return 0;
        }

        $cutoff = time() - max(1, $retentionDays) * 86400;
        $removed = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            if ($file->getMTime() < $cutoff && @unlink($file->getPathname())) {
                $removed++;
            }
        }

        return $removed;
    }
}

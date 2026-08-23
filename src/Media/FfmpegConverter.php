<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Optional ffmpeg binary wrapper (capability-gated): converts non-voice
 * audio (WAV/FLAC…) into OGG/OPUS so it can travel as a voice note.
 * Availability is detected lazily from config('tts.ffmpeg_path') or PATH;
 * when absent the pipeline falls back to SendAudio.
 */
class FfmpegConverter
{
    private const CONVERT_TIMEOUT_SECONDS = 5;

    private ?bool $available = null;

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $configured = trim((string) config('tts.ffmpeg_path', ''));

        if ($configured !== '') {
            return $this->available = is_executable($configured);
        }

        foreach (['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $candidate) {
            $resolved = trim((string) shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));

            if ($resolved !== '' && is_executable($resolved)) {
                return $this->available = true;
            }
        }

        return $this->available = false;
    }

    /**
     * Convert an audio file into OGG/OPUS (voice-qualified). Returns the new
     * tmpfile path; the caller owns both files and must unlink them.
     */
    public function convertToOggOpus(string $sourcePath): string
    {
        $binary = $this->resolveBinary();

        if ($binary === null) {
            throw new RuntimeException('ffmpeg is not available');
        }

        $targetPath = $sourcePath.'.ogg';

        $result = Process::timeout(self::CONVERT_TIMEOUT_SECONDS)->run([
            $binary,
            '-y',
            '-loglevel', 'error',
            '-i', $sourcePath,
            '-c:a', 'libopus',
            '-b:a', '64k',
            '-vn',
            $targetPath,
        ]);

        if (! $result->successful() || ! is_file($targetPath)) {
            Log::warning('TTS: ffmpeg conversion failed', ['exit' => $result->exitCode()]);

            throw new RuntimeException('ffmpeg conversion failed');
        }

        chmod($targetPath, 0600);

        return $targetPath;
    }

    public function version(): ?string
    {
        $binary = $this->resolveBinary();

        if ($binary === null) {
            return null;
        }

        $result = Process::timeout(5)->run([$binary, '-version']);

        if (! $result->successful()) {
            return null;
        }

        $line = strtok((string) $result->output(), "\n");

        return $line === false ? null : trim((string) $line);
    }

    private function resolveBinary(): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $configured = trim((string) config('tts.ffmpeg_path', ''));

        if ($configured !== '') {
            return $configured;
        }

        foreach (['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $candidate) {
            $resolved = trim((string) shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));

            if ($resolved !== '' && is_executable($resolved)) {
                return $resolved;
            }
        }

        return null;
    }
}

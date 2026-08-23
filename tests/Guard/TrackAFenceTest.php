<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Tests\Guard;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Track A fence (RFC §6): the JSON-only transport bypass lives in exactly
 * one class. If any other src/ file starts posting to api.telegram.org o
 * referencing the send-voice/upload path directly, CI fails here.
 */
final class TrackAFenceTest extends TestCase
{
    public function test_multipart_bypass_is_fenced_inside_media_uploader(): void
    {
        $srcDir = dirname(__DIR__, 2).'/src';
        $offenders = [];

        foreach ($this->phpFiles($srcDir) as $file) {
            if (in_array(basename($file), ['MediaUploader.php', 'TtsDoctorCommand.php'], true)) {
                // MediaUploader = the fence itself; TtsDoctorCommand = the
                // runtime detector running the same scan in production.
                continue;
            }

            $contents = (string) file_get_contents($file);

            foreach (['api.telegram.org', "'sendVoice'", '"sendVoice"', 'Http::attach'] as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = basename($file).' contains '.$needle;

                    break;
                }
            }
        }

        self::assertSame([], $offenders, 'Track A bypass leaked out of MediaUploader');
    }

    public function test_media_uploader_documents_the_fence(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/src/Media/MediaUploader.php');

        self::assertStringContainsString('TRACK A', $doc);
        self::assertStringContainsString('TRACK B', $doc);
        self::assertStringContainsString('ASKHttpRequest', $doc);
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}

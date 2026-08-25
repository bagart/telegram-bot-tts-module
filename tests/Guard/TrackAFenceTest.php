<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Tests\Guard;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Standard-transport anti-regression (Track B, todo.tts.md §6): the former
 * Track A bypass (direct multipart posts to api.telegram.org) is deleted.
 * Voice delivery must go through the core DTO client — if any src/ file
 * starts referencing the raw upload path again, CI fails here.
 */
final class TrackAFenceTest extends TestCase
{
    public function test_no_src_file_posts_to_telegram_api_directly(): void
    {
        $srcDir = dirname(__DIR__, 2).'/src';
        $offenders = [];

        foreach ($this->phpFiles($srcDir) as $file) {
            // TtsDoctorCommand runs the same scan at runtime and necessarily
            // contains the needle literals.
            if (basename($file) === 'TtsDoctorCommand.php') {
                continue;
            }

            $contents = (string) file_get_contents($file);

            foreach ([
                // Direct Telegram API posting markers (provider HTTP calls
                // are fine — only api.telegram.org access is fenced).
                'api.telegram.org',
                'Http::attach',
                "'sendVoice'",
                '"sendVoice"',
                "'sendAudio'",
                '"sendAudio"',
            ] as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = basename($file).' contains '.$needle;

                    break;
                }
            }
        }

        self::assertSame([], $offenders, 'Direct Telegram API access reappeared in module src/');
    }

    public function test_media_uploader_uses_the_core_dto_client(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/src/Media/MediaUploader.php');

        self::assertStringContainsString('TgBotApiDTOClientContract', $doc);
        self::assertStringContainsString('SendVoiceMethodDTO', $doc);
        self::assertStringContainsString('file://', $doc);
        self::assertStringNotContainsString('Http::', $doc);
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

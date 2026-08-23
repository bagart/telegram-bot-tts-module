<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Tests\Unit;

use BAGArt\TelegramBotTts\Support\AudioFileStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AudioFileStoreTest extends TestCase
{
    private string $tmpDir;

    private AudioFileStore $store;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/tts-test-'.bin2hex(random_bytes(4));
        $this->store = new AudioFileStore($this->tmpDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }

            @rmdir($this->tmpDir);
        }
    }

    public function test_paths_are_bot_scoped_and_sanitized(): void
    {
        $path = $this->store->path('12345', sha1('x'), 'ogg');

        self::assertStringStartsWith($this->tmpDir.'/12345/', $path);
        self::assertStringEndsWith('.ogg', $path);
        self::assertDoesNotMatchRegularExpression('/[^a-f0-9.\/]/', str_replace($this->tmpDir.'/', '', dirname($path).'/'.basename($path, '.ogg')));
    }

    public function test_write_read_roundtrip_and_mode(): void
    {
        $path = $this->store->path('b1', str_repeat('a', 40), 'mp3');

        $this->store->write($path, 'BINARY');

        self::assertTrue($this->store->exists($path));
        self::assertSame('BINARY', $this->store->read($path));
        self::assertSame(0600, fileperms($path) & 0777);
    }

    public function test_delete_removes_only_existing_files_safely(): void
    {
        $path = $this->store->path('b1', str_repeat('b', 40), 'ogg');

        // Deleting a missing file must not throw.
        $this->store->delete($path);

        $this->store->write($path, 'X');
        $this->store->delete($path);

        self::assertFalse($this->store->exists($path));
    }

    public function test_prune_removes_stale_files_by_mtime(): void
    {
        $fresh = $this->store->path('b1', str_repeat('c', 40), 'ogg');
        $stale = $this->store->path('b1', str_repeat('d', 40), 'ogg');

        $this->store->write($fresh, 'F');
        $this->store->write($stale, 'S');
        touch($stale, time() - 86400 * 40);

        $removed = $this->store->prune(30);

        self::assertSame(1, $removed);
        self::assertTrue($this->store->exists($fresh));
        self::assertFalse($this->store->exists($stale));
    }

    public function test_prune_on_missing_directory_is_harmless(): void
    {
        $store = new AudioFileStore($this->tmpDir.'/definitely-missing');

        self::assertSame(0, $store->prune(30));
    }

    public function test_read_of_missing_file_throws_checked_exception(): void
    {
        $this->expectException(RuntimeException::class);

        $this->store->read($this->tmpDir.'/nope.bin');
    }
}

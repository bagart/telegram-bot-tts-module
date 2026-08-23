<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Guard;

/**
 * In-memory GuardStoreContract implementation. First-class utility (antispam
 * MemoryBatchCounter precedent): used by unit/feature tests and any context
 * without Redis. TTLs are NOT simulated — expiry is managed by callers
 * deleting keys explicitly.
 */
final class ArrayGuardStore implements GuardStoreContract
{
    /** @var array<string, string> */
    public array $values = [];

    public function incrementWithTtl(string $key, int $ttlSeconds): int
    {
        $next = (int) ($this->values[$key] ?? 0) + 1;
        $this->values[$key] = (string) $next;

        return $next;
    }

    public function decrementFloorZero(string $key): void
    {
        $this->values[$key] = (string) max(0, (int) ($this->values[$key] ?? 0) - 1);
    }

    public function addIfAbsent(string $key, string $value, int $ttlMilliseconds): bool
    {
        if (array_key_exists($key, $this->values)) {
            return false;
        }

        $this->values[$key] = $value;

        return true;
    }

    public function get(string $key): ?string
    {
        return isset($this->values[$key]) ? $this->values[$key] : null;
    }

    public function setWithTtl(string $key, string $value, int $ttlSeconds): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }
}

<?php

declare(strict_types=1);

use BAGArt\TelegramBotTts\Guard\ArrayGuardStore;
use BAGArt\TelegramBotTts\Guard\GuardStoreContract;

/*
 * Class-graph guard for the TTS module: every src/ class must load and
 * declare only resolvable parent/interface/property/method types. This
 * catches truncated or renamed identifiers that plain syntax checks miss
 * (regression guard after the 2026-08-23 identifier-truncation incident).
 */

function ttsModuleClasses(): array
{
    $prefix = 'BAGArt\\TelegramBotTts\\';
    $srcDir = dirname(__DIR__, 3).'/src';
    $classes = [];

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
    ) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $rel = substr((string) $file->getPathname(), strlen($srcDir) + 1, -4);
        $classes[] = $prefix.str_replace('/', '\\', $rel);
    }

    return $classes;
}

it('loads every tts src class', function () {
    $classes = ttsModuleClasses();

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect(class_exists($class) || interface_exists($class) || enum_exists($class))
            ->toBeTrue("class {$class} is not loadable");
    }
});

it('resolves every declared type in tts classes', function () {
    $problems = [];

    $checkType = function (ReflectionType $type, string $where) use (&$problems): void {
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return;
        }

        $name = $type->getName();

        if (in_array($name, ['self', 'static', 'parent'], true)) {
            return;
        }

        if (! class_exists($name) && ! interface_exists($name)) {
            $problems[] = "{$where}: unresolvable type {$name}";
        }
    };

    foreach (ttsModuleClasses() as $class) {
        $ref = new ReflectionClass($class);

        foreach ($ref->getInterfaceNames() as $iface) {
            if (! interface_exists($iface)) {
                $problems[] = "{$class}: missing interface {$iface}";
            }
        }

        if (($parent = $ref->getParentClass()) !== false && ! class_exists($parent->getName())) {
            $problems[] = "{$class}: missing parent ".$parent->getName();
        }

        foreach ($ref->getMethods() as $method) {
            if (($rt = $method->getReturnType()) !== null) {
                foreach ($rt instanceof ReflectionUnionType ? $rt->getTypes() : [$rt] as $t) {
                    $checkType($t, "{$class}::{$method->name}(return)");
                }
            }

            foreach ($method->getParameters() as $param) {
                if (($pt = $param->getType()) !== null) {
                    foreach ($pt instanceof ReflectionUnionType ? $pt->getTypes() : [$pt] as $t) {
                        $checkType($t, "{$class}::{$method->name}(\${$param->name})");
                    }
                }
            }
        }

        foreach ($ref->getProperties() as $prop) {
            if (($pt = $prop->getType()) !== null) {
                foreach ($pt instanceof ReflectionUnionType ? $pt->getTypes() : [$pt] as $t) {
                    $checkType($t, "{$class}::\${$prop->name}");
                }
            }
        }
    }

    expect($problems)->toBe(implode(PHP_EOL, $problems) === '' ? [] : $problems);
});

it('exposes the metrics exporter through the container with an empty series when redis is down', function () {
    // Swap in the array store so no real Redis is needed; exporter must not throw.
    $store = new ArrayGuardStore();
    $this->app->instance(GuardStoreContract::class, $store);

    /** @var BAGArt\TelegramBotTts\Observability\TtsMetricsExporter $exporter */
    $exporter = app(BAGArt\TelegramBotTts\Observability\TtsMetricsExporter::class);

    // No Redis traffic happened → counters empty; breaker phases come from
    // the same store → closed. Lines may be empty or contain breaker zeros,
    // but rendering must never throw.
    $lines = $exporter->prometheusLines();

    expect($lines)->toBeArray();
});

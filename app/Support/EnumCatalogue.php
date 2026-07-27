<?php

declare(strict_types=1);

namespace App\Support;

use BackedEnum;
use ReflectionEnum;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every backed enum in the platform, with its Persian labels — the payload
 * behind `GET /meta/enums` (docs/05-api/02-endpoints.md §2.17).
 *
 * WHY THIS LIVES OUTSIDE app/Modules
 * ----------------------------------
 * The catalogue spans every module by definition, and no module is allowed to
 * import every other one (see the graph in tests/Architecture/ArchitectureTest).
 * Putting it in Shared would be worse still: Shared may depend on nothing. So
 * it sits in the application layer, which is above the module graph and is the
 * one place a cross-cutting read like this legitimately belongs.
 *
 * Enums are DISCOVERED rather than listed. A hard-coded list is a list that
 * goes stale the first time somebody adds a status, and the client's whole
 * reason for calling this endpoint is to avoid shipping a new build when that
 * happens.
 */
final class EnumCatalogue
{
    private const MODULES_ROOT = __DIR__.'/../Modules';

    /** Directories inside a module that may contain domain enums. */
    private const ENUM_DIRECTORIES = ['Domain', 'Contracts', 'Aml'];

    /** @var array<string, array<int, array<string, string>>>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, list<array{value: string, label: string, label_en: string}>>
     *   keyed by snake_case enum name, e.g. "order_status"
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        /** @var array<string, list<array{value: string, label: string, label_en: string}>> $catalogue */
        $catalogue = [];
        /** @var array<string, string> $owners */
        $owners = [];

        foreach (self::enumClasses() as $module => $classes) {
            foreach ($classes as $class) {
                $key = self::snake(self::shortName($class));
                $cases = self::describe($class);

                if ($cases === []) {
                    continue;
                }

                if (! isset($catalogue[$key])) {
                    $catalogue[$key] = $cases;
                    $owners[$key] = $module;

                    continue;
                }

                // Two modules named the same enum. If they agree on the case
                // set, one entry serves both; if they disagree, publish both
                // under module-qualified keys so a client cannot silently
                // validate against the wrong one.
                if ($catalogue[$key] === $cases) {
                    continue;
                }

                $catalogue[self::snake($owners[$key]).'_'.$key] = $catalogue[$key];
                $catalogue[self::snake($module).'_'.$key] = $cases;
                unset($catalogue[$key]);
            }
        }

        ksort($catalogue);

        return self::$cache = $catalogue;
    }

    /** A stable fingerprint of the catalogue, for ETag and cache busting. */
    public static function version(): string
    {
        return substr(hash('sha256', json_encode(self::all(), JSON_UNESCAPED_UNICODE) ?: ''), 0, 16);
    }

    /**
     * @param  class-string  $class
     * @return list<array{value: string, label: string, label_en: string}>
     */
    private static function describe(string $class): array
    {
        if (! enum_exists($class)) {
            return [];
        }

        $reflection = new ReflectionEnum($class);

        if (! $reflection->isBacked()) {
            return [];
        }

        $hasLabel = $reflection->hasMethod('label')
            && $reflection->getMethod('label')->getNumberOfRequiredParameters() === 0;

        $entries = [];

        /** @var BackedEnum $case */
        foreach ($class::cases() as $case) {
            $entries[] = [
                'value' => (string) $case->value,
                // Persian label when the enum defines one; several enums do not
                // (Kyc's statuses, for instance), and the endpoint says so by
                // falling back to the humanised name rather than inventing a
                // translation.
                'label' => $hasLabel ? (string) $case->label() : self::humanise($case->name),
                'label_en' => self::humanise($case->name),
            ];
        }

        return $entries;
    }

    /** @return array<string, list<class-string>> module => enum FQCNs */
    private static function enumClasses(): array
    {
        $found = [];

        if (! is_dir(self::MODULES_ROOT)) {
            return $found;
        }

        foreach (scandir(self::MODULES_ROOT) ?: [] as $module) {
            if ($module === '.' || $module === '..' || ! is_dir(self::MODULES_ROOT.'/'.$module)) {
                continue;
            }

            foreach (self::ENUM_DIRECTORIES as $directory) {
                $path = self::MODULES_ROOT.'/'.$module.'/'.$directory;

                if (! is_dir($path)) {
                    continue;
                }

                foreach (self::phpFiles($path) as $file) {
                    $class = self::classFor($file);

                    if ($class !== null && enum_exists($class)) {
                        $found[$module][] = $class;
                    }
                }
            }
        }

        return $found;
    }

    /** @return list<SplFileInfo> */
    private static function phpFiles(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = clone $file;
            }
        }

        return $files;
    }

    /** @return class-string|null */
    private static function classFor(SplFileInfo $file): ?string
    {
        $real = $file->getRealPath();
        $root = realpath(self::MODULES_ROOT);

        if ($real === false || $root === false || ! str_starts_with($real, $root)) {
            return null;
        }

        $relative = substr($real, strlen($root) + 1, -4);
        $class = 'App\\Modules\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        /** @var class-string */
        return $class;
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    private static function snake(string $studly): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $studly));
    }

    /** ORDER_BOOK_VIEW -> "Order Book View". */
    private static function humanise(string $caseName): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $caseName)));
    }
}

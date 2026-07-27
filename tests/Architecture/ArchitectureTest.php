<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Enforces the architectural rules that would otherwise erode silently.
 *
 * These replace deptrac and a custom PHPStan rule, neither of which can be
 * installed in this environment (the package proxy refuses GitHub API auth).
 * The checks themselves are the point, not the tool, so they live here where
 * they run on every test invocation.
 */
#[Group('architecture')]
final class ArchitectureTest extends TestCase
{
    /**
     * The dependency graph from docs/02-architecture/02-modules.md §2.2.
     * A module may depend only on the modules listed for it.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'Shared' => [],
        'Identity' => ['Shared'],
        'Kyc' => ['Shared', 'Identity'],
        'Custody' => ['Shared', 'Identity'],
        'Ledger' => ['Shared', 'Identity', 'Custody'],
        'Pricing' => ['Shared'],
        'Risk' => ['Shared', 'Identity', 'Ledger', 'Pricing'],
        'Trading' => ['Shared', 'Identity', 'Ledger', 'Risk', 'Pricing', 'Custody'],
        'Settlement' => ['Shared', 'Identity', 'Ledger', 'Trading', 'Custody', 'Risk'],
        'Counterparty' => ['Shared', 'Identity'],
        'Accounting' => ['Shared', 'Identity', 'Ledger', 'Trading', 'Settlement'],
        'Dispute' => ['Shared', 'Identity', 'Trading', 'Settlement', 'Ledger', 'Custody'],
        'Reputation' => ['Shared', 'Identity'],
        'Notification' => ['Shared', 'Identity'],
        'Reporting' => ['Shared', 'Identity'],
    ];

    /** Namespaces where floating-point arithmetic is forbidden outright. */
    private const FINANCIAL_PATHS = [
        'Shared/ValueObjects',
        'Shared/Calculation',
        'Shared/Support',
        'Ledger',
        'Settlement',
        'Trading',
        'Accounting',
    ];

    private const MODULES_ROOT = __DIR__.'/../../app/Modules';

    #[Test]
    public function modules_only_depend_on_permitted_modules(): void
    {
        $violations = [];

        foreach ($this->modulePhpFiles() as $module => $files) {
            $allowed = self::ALLOWED[$module] ?? [];

            foreach ($files as $file) {
                $source = file_get_contents($file->getPathname());

                preg_match_all(
                    '/(?:use|^\s*\\\\?)App\\\\Modules\\\\([A-Za-z]+)\\\\/m',
                    $source,
                    $matches,
                );

                foreach (array_unique($matches[1]) as $referenced) {
                    if ($referenced === $module || in_array($referenced, $allowed, true)) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s -> %s in %s',
                        $module,
                        $referenced,
                        $this->relativePath($file),
                    );
                }
            }
        }

        self::assertSame([], $violations, "Illegal cross-module dependencies:\n".implode("\n", $violations));
    }

    #[Test]
    public function modules_reach_other_modules_only_through_contracts_or_events(): void
    {
        $violations = [];

        foreach ($this->modulePhpFiles() as $module => $files) {
            foreach ($files as $file) {
                $source = file_get_contents($file->getPathname());

                preg_match_all(
                    '/use App\\\\Modules\\\\([A-Za-z]+)\\\\([A-Za-z]+)\\\\/',
                    $source,
                    $matches,
                    PREG_SET_ORDER,
                );

                foreach ($matches as [, $referenced, $segment]) {
                    if ($referenced === $module || $referenced === 'Shared') {
                        continue;
                    }

                    // Only the public surface of another module is importable.
                    if (in_array($segment, ['Contracts', 'Events', 'Domain'], true)) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s imports %s\\%s (not Contracts/Events/Domain) in %s',
                        $module,
                        $referenced,
                        $segment,
                        $this->relativePath($file),
                    );
                }
            }
        }

        self::assertSame([], $violations, "Module encapsulation violations:\n".implode("\n", $violations));
    }

    #[Test]
    public function financial_code_contains_no_floating_point_arithmetic(): void
    {
        $violations = [];

        foreach (self::FINANCIAL_PATHS as $path) {
            $dir = self::MODULES_ROOT.'/'.$path;

            if (! is_dir($dir)) {
                continue;
            }

            foreach ($this->phpFilesIn($dir) as $file) {
                if (str_contains($file->getPathname(), '/Tests/')) {
                    continue;
                }

                $source = $this->stripCommentsAndStrings(file_get_contents($file->getPathname()));

                // float/double casts and type declarations
                if (preg_match('/\(\s*(?:float|double)\s*\)/', $source)) {
                    $violations[] = 'float cast in '.$this->relativePath($file);
                }

                if (preg_match('/(?::\s*\??(?:float|double)\b)|(?:\b(?:float|double)\s+\$)/', $source)) {
                    $violations[] = 'float type declaration in '.$this->relativePath($file);
                }

                foreach (['floatval', 'round', 'floor', 'ceil', 'fdiv'] as $fn) {
                    if (preg_match('/(?<![\w>$])'.$fn.'\s*\(/', $source)) {
                        $violations[] = sprintf('%s() in %s', $fn, $this->relativePath($file));
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Floating-point arithmetic in a financial path:\n".implode("\n", $violations),
        );
    }

    #[Test]
    public function every_module_file_declares_strict_types(): void
    {
        $violations = [];

        foreach ($this->modulePhpFiles() as $files) {
            foreach ($files as $file) {
                $source = file_get_contents($file->getPathname());

                if (! str_contains($source, 'declare(strict_types=1)')) {
                    $violations[] = $this->relativePath($file);
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Files missing declare(strict_types=1):\n".implode("\n", $violations),
        );
    }

    #[Test]
    public function ledger_and_audit_tables_are_never_updated_or_deleted(): void
    {
        $violations = [];
        $forbidden = ['ledger_entries', 'audit_logs', 'lot_lineage'];

        foreach ($this->modulePhpFiles() as $files) {
            foreach ($files as $file) {
                if (str_contains($file->getPathname(), '/Tests/')
                    || str_contains($file->getPathname(), '/Migrations/')) {
                    continue;
                }

                $source = $this->stripCommentsAndStrings(file_get_contents($file->getPathname()));

                foreach ($forbidden as $table) {
                    if (preg_match(
                        '/(?:table|from)\([\'"]'.$table.'[\'"]\)[^;]*->(?:update|delete|truncate)\s*\(/s',
                        $source,
                    )) {
                        $violations[] = sprintf(
                            'mutation of %s in %s',
                            $table,
                            $this->relativePath($file),
                        );
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Append-only tables must never be mutated:\n".implode("\n", $violations),
        );
    }

    /** @return array<string, list<SplFileInfo>> */
    private function modulePhpFiles(): array
    {
        $result = [];

        if (! is_dir(self::MODULES_ROOT)) {
            return $result;
        }

        foreach (scandir(self::MODULES_ROOT) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $dir = self::MODULES_ROOT.'/'.$entry;

            if (is_dir($dir)) {
                $result[$entry] = $this->phpFilesIn($dir);
            }
        }

        return $result;
    }

    /** @return list<SplFileInfo> */
    private function phpFilesIn(string $dir): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = clone $file;
            }
        }

        return $files;
    }

    /** Avoids false positives from the word "float" appearing in a doc block. */
    private function stripCommentsAndStrings(string $source): string
    {
        $result = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
                    continue;
                }
                $result .= $token[1];
            } else {
                $result .= $token;
            }
        }

        return $result;
    }

    private function relativePath(SplFileInfo $file): string
    {
        return str_replace(realpath(self::MODULES_ROOT).'/', '', $file->getRealPath() ?: $file->getPathname());
    }
}

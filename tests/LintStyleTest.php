<?php

/**
 * RZ-42 — tools/lint-style.php checks docs/STYLE.md on framework code, and the
 * module lint's RZ-018 / RZ-019 fixtures stay green.
 *
 * Every case writes a throwaway file and runs the real tool on it.
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
class LintStyleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/razy-lint-style-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            \unlink($file);
        }
        \rmdir($this->dir);
    }

    public function testCleanFilePasses(): void
    {
        $this->write('Clean.php', $this->classWith("    public function doWork(): int\n    {\n        return 1;\n    }\n"));

        $this->assertSame([], $this->lint()['violations']);
    }

    public function testPascalCaseMethodIsReported(): void
    {
        $this->write('Named.php', $this->classWith("    public static function ParseValue(): void\n    {\n    }\n\n    public function __onInit(): void\n    {\n    }\n"));

        $this->assertSame(['STY-NAME' => ['Named::ParseValue']], $this->keys());
    }

    public function testSizeCapsCountTheDeclarationNotClosuresOrAnonymousClasses(): void
    {
        $long = "    public function long(): void\n    {\n" . \str_repeat("        \$x = 1;\n", 59) . "    }\n";
        $closure = "    public function short(): \\Closure\n    {\n        return function () {\n" . \str_repeat("            \$y = 2;\n", 70) . "        };\n    }\n";
        $anonymous = "    public function make(): object\n    {\n        return new class () {\n        };\n    }\n";
        $this->write('Sized.php', $this->classWith($long . "\n" . $closure . "\n" . $anonymous));

        $keys = $this->keys();
        $this->assertSame(['Sized::long', 'Sized::short'], $keys['STY-FUNC'], 'a named method counts its closure lines; the closure itself is not a function');
        $this->assertArrayNotHasKey('STY-CLASS', $keys, 'class body under 400 lines');
    }

    public function testMissingStrictTypesIsReported(): void
    {
        $this->write('Loose.php', "<?php\n\nnamespace Fixture;\n\nfunction helper(): void\n{\n}\n");

        $this->assertSame(['STY-STRICT' => [$this->dir . '/Loose.php']], $this->keys());
    }

    public function testValueGluedIntoSyntaxNeedsAWrittenReason(): void
    {
        $body = "    public function find(\$db, \$id): void\n    {\n"
            . "        \$db->prepare()->where('id=' . \$id);\n"
            . "        // lint-allow: RZ-019\n"
            . "        \$db->prepare()->where('id=' . \$id);\n"
            . "        // lint-allow: RZ-019 the model's own primary key\n"
            . "        \$db->prepare()->where(\$pk . '=:id');\n"
            . "        \$db->prepare()->where('id=:id')->assign(['id' => \$id]);\n    }\n";
        $this->write('Sql.php', $this->classWith($body));

        $lines = \array_map(static fn (array $v) => $v['line'], \array_filter($this->lint()['violations'], static fn (array $v) => $v['rule'] === 'RZ-019'));
        $this->assertSame([11, 13], \array_values($lines), 'a bare lint-allow does not count');
    }

    public function testBaselineAllowsTodayButNotGrowthOrNewCode(): void
    {
        $method = static fn (int $n) => "    public function big(): void\n    {\n" . \str_repeat("        \$x = 1;\n", $n) . "    }\n";
        $this->write('Debt.php', $this->classWith($method(70)));
        $baseline = $this->dir . '/baseline.json';
        $this->tool([$this->dir, '--generate-baseline', '--baseline=' . $baseline]);
        $this->assertSame([], $this->lint($baseline)['violations'], 'baselined debt passes');

        $this->write('Debt.php', $this->classWith($method(71)));
        $report = $this->lint($baseline);
        $this->assertSame(['STY-FUNC'], \array_column($report['violations'], 'rule'), 'a baselined size may not grow');
        $this->assertStringContainsString('grew past its baseline of 73', $report['violations'][0]['message']);

        $this->write('Debt.php', $this->classWith("    public function big(): void\n    {\n    }\n"));
        $this->assertSame(['STY-FUNC ' . $this->dir . '/Debt.php::Debt::big'], $this->lint($baseline)['stale_baseline'], 'fixed debt is reported so the baseline shrinks');
    }

    public function testModuleLintFixturesPass(): void
    {
        $out = (string) \shell_exec(\escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg(SYSTEM_ROOT . '/tools/lint-module-discipline.php') . ' --self-test');
        $this->assertStringContainsString('(RZ-018)', $out);
        $this->assertStringContainsString('(RZ-019)', $out);
        $this->assertStringNotContainsString('FAIL', $out, $out);
    }

    private function classWith(string $body): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture;\n\nclass CLASSNAME\n{\n" . $body . "}\n";
    }

    private function write(string $name, string $code): void
    {
        \file_put_contents($this->dir . '/' . $name, \str_replace('CLASSNAME', \basename($name, '.php'), $code));
    }

    /**
     * @param list<string> $args
     */
    private function tool(array $args): string
    {
        $cmd = \escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg(SYSTEM_ROOT . '/tools/lint-style.php');
        foreach ($args as $arg) {
            $cmd .= ' ' . \escapeshellarg($arg);
        }

        return (string) \shell_exec($cmd);
    }

    /**
     * @return array{violations: list<array<string, mixed>>, stale_baseline: list<string>}
     */
    private function lint(?string $baseline = null): array
    {
        $out = $this->tool([$this->dir, '--format=json', '--baseline=' . ($baseline ?? $this->dir . '/none.json')]);
        $report = \json_decode($out, true);
        $this->assertIsArray($report, $out);

        return $report;
    }

    /**
     * Findings grouped by rule, keyed without the directory prefix (classes) or with it (files).
     *
     * @return array<string, list<string>>
     */
    private function keys(): array
    {
        $grouped = [];
        foreach ($this->lint()['violations'] as $v) {
            $key = \str_contains($v['key'], '::') ? \substr($v['key'], \strpos($v['key'], '::') + 2) : $v['key'];
            $grouped[$v['rule']][] = $key;
        }

        return $grouped;
    }
}

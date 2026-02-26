<?php

/**
 * Unit tests for Razy\Terminal.
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\Terminal;

#[CoversClass(Terminal::class)]
class TerminalTest extends TestCase
{
    // ══════════════════════════════════════════════════════
    // Constructor
    // ══════════════════════════════════════════════════════

    #[Test]
    public function constructorSetsCode(): void
    {
        $terminal = new Terminal('test-code');
        $this->assertSame('test-code', $terminal->getCode());
    }

    #[Test]
    public function constructorTrimsWhitespace(): void
    {
        $terminal = new Terminal('  my-code  ');
        $this->assertSame('my-code', $terminal->getCode());
    }

    #[Test]
    public function constructorThrowsOnEmptyCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The terminal code is required.');
        new Terminal('');
    }

    #[Test]
    public function constructorThrowsOnWhitespaceOnlyCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Terminal('   ');
    }

    #[Test]
    public function constructorAcceptsParent(): void
    {
        $parent = new Terminal('parent');
        $child = new Terminal('child', $parent);
        $this->assertSame($parent, $child->getParent());
    }

    #[Test]
    public function constructorDefaultsParentToNull(): void
    {
        $terminal = new Terminal('solo');
        $this->assertNull($terminal->getParent());
    }

    // ══════════════════════════════════════════════════════
    // getCode()
    // ══════════════════════════════════════════════════════

    #[Test]
    public function getCodeReturnsCode(): void
    {
        $terminal = new Terminal('alpha');
        $this->assertSame('alpha', $terminal->getCode());
    }

    // ══════════════════════════════════════════════════════
    // getParent()
    // ══════════════════════════════════════════════════════

    #[Test]
    public function getParentReturnsNullWithoutParent(): void
    {
        $terminal = new Terminal('root');
        $this->assertNull($terminal->getParent());
    }

    #[Test]
    public function getParentReturnsParentTerminal(): void
    {
        $parent = new Terminal('parent');
        $child = new Terminal('child', $parent);
        $this->assertSame($parent, $child->getParent());
    }

    // ══════════════════════════════════════════════════════
    // getParameters()
    // ══════════════════════════════════════════════════════

    #[Test]
    public function getParametersDefaultsToEmptyArray(): void
    {
        $terminal = new Terminal('test');
        $this->assertSame([], $terminal->getParameters());
    }

    // ══════════════════════════════════════════════════════
    // ANSI Color Constants
    // ══════════════════════════════════════════════════════

    #[Test]
    public function colorConstantsAreDefined(): void
    {
        $this->assertSame("\033[39m", Terminal::COLOR_DEFAULT);
        $this->assertSame("\033[30m", Terminal::COLOR_BLACK);
        $this->assertSame("\033[31m", Terminal::COLOR_RED);
        $this->assertSame("\033[32m", Terminal::COLOR_GREEN);
        $this->assertSame("\033[33m", Terminal::COLOR_YELLOW);
        $this->assertSame("\033[34m", Terminal::COLOR_BLUE);
        $this->assertSame("\033[35m", Terminal::COLOR_MAGENTA);
        $this->assertSame("\033[36m", Terminal::COLOR_CYAN);
        $this->assertSame("\033[37m", Terminal::COLOR_LIGHTGRAY);
        $this->assertSame("\033[90m", Terminal::COLOR_DARKGRAY);
        $this->assertSame("\033[91m", Terminal::COLOR_LIGHTRED);
        $this->assertSame("\033[92m", Terminal::COLOR_LIGHTGREEN);
        $this->assertSame("\033[93m", Terminal::COLOR_LIGHTYELLOW);
        $this->assertSame("\033[94m", Terminal::COLOR_LIGHTBLUE);
        $this->assertSame("\033[95m", Terminal::COLOR_LIGHTMAGENTA);
        $this->assertSame("\033[96m", Terminal::COLOR_LIGHTCYAN);
        $this->assertSame("\033[97m", Terminal::COLOR_WHITE);
    }

    #[Test]
    public function backgroundColorConstantsAreDefined(): void
    {
        $this->assertSame("\033[40m", Terminal::BACKGROUND_BLACK);
        $this->assertSame("\033[41m", Terminal::BACKGROUND_RED);
        $this->assertSame("\033[42m", Terminal::BACKGROUND_GREEN);
        $this->assertSame("\033[43m", Terminal::BACKGROUND_YELLOW);
        $this->assertSame("\033[44m", Terminal::BACKGROUND_BLUE);
        $this->assertSame("\033[45m", Terminal::BACKGROUND_MAGENTA);
        $this->assertSame("\033[46m", Terminal::BACKGROUND_CYAN);
        $this->assertSame("\033[47m", Terminal::BACKGROUND_LIGHTGRAYE);
    }

    #[Test]
    public function controlConstantsAreDefined(): void
    {
        $this->assertSame("\033[0G\033[2K", Terminal::CLEAR_LINE);
        $this->assertSame("\n", Terminal::NEWLINE);
        $this->assertSame("\033[5m", Terminal::TEXT_BLINK);
        $this->assertSame("\033[0m", Terminal::RESET_STLYE);
    }

    // ══════════════════════════════════════════════════════
    // Format() — static method
    // ══════════════════════════════════════════════════════

    #[Test]
    public function formatReturnsEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', Terminal::Format(''));
    }

    #[Test]
    public function formatPassesThroughPlainText(): void
    {
        $this->assertSame('Hello World', Terminal::Format('Hello World'));
    }

    #[Test]
    public function formatProcessesFontColorTag(): void
    {
        $result = Terminal::Format('{@c:red}');
        $this->assertSame("\033[31m", $result);
    }

    #[Test]
    public function formatProcessesMultipleFontColors(): void
    {
        $result = Terminal::Format('{@c:green}OK{@c:default}');
        $this->assertSame("\033[32mOK\033[39m", $result);
    }

    #[Test]
    public function formatProcessesBackgroundColorTag(): void
    {
        $result = Terminal::Format('{@b:blue}');
        $this->assertSame("\033[44m", $result);
    }

    #[Test]
    public function formatProcessesCombinedColorAndBackground(): void
    {
        $result = Terminal::Format('{@c:white,b:red}');
        $this->assertSame("\033[97m\033[41m", $result);
    }

    #[Test]
    public function formatProcessesResetCode(): void
    {
        $result = Terminal::Format('{@reset}');
        $this->assertSame("\033[0m", $result);
    }

    #[Test]
    public function formatProcessesClearCode(): void
    {
        $result = Terminal::Format('{@clear}');
        $this->assertSame("\033[0G\033[2K", $result);
    }

    #[Test]
    public function formatProcessesNewlineCode(): void
    {
        $result = Terminal::Format('{@nl}');
        $this->assertSame(PHP_EOL, $result);
    }

    #[Test]
    public function formatProcessesCombinedControlCodes(): void
    {
        $result = Terminal::Format('{@reset|nl}');
        $this->assertSame("\033[0m" . PHP_EOL, $result);
    }

    #[Test]
    public function formatDeduplicatesCombinedControlCodes(): void
    {
        // Repeated codes should be deduplicated via array_flip
        $result = Terminal::Format('{@reset|reset|nl}');
        $this->assertSame("\033[0m" . PHP_EOL, $result);
    }

    #[Test]
    public function formatProcessesBoldStyle(): void
    {
        $result = Terminal::Format('{@s:b}');
        $this->assertSame("\e[1m", $result);
    }

    #[Test]
    public function formatProcessesItalicStyle(): void
    {
        $result = Terminal::Format('{@s:i}');
        $this->assertSame("\e[3m", $result);
    }

    #[Test]
    public function formatProcessesUnderlineStyle(): void
    {
        $result = Terminal::Format('{@s:u}');
        $this->assertSame("\e[4m", $result);
    }

    #[Test]
    public function formatProcessesStrikethroughStyle(): void
    {
        $result = Terminal::Format('{@s:s}');
        $this->assertSame("\e[9m", $result);
    }

    #[Test]
    public function formatProcessesBlinkStyle(): void
    {
        $result = Terminal::Format('{@s:k}');
        $this->assertSame("\e[5m", $result);
    }

    #[Test]
    public function formatProcessesCombinedStyles(): void
    {
        $result = Terminal::Format('{@s:biu}');
        // bold + italic + underline
        $this->assertSame("\e[1m\e[3m\e[4m", $result);
    }

    #[Test]
    public function formatStyleDeduplicatesRepeatedCodes(): void
    {
        // "bbb" should produce only one bold
        $result = Terminal::Format('{@s:bbb}');
        $this->assertSame("\e[1m", $result);
    }

    #[Test]
    public function formatProcessesMixedColorAndStyle(): void
    {
        $result = Terminal::Format('{@c:red,s:b}');
        $this->assertSame("\033[31m\e[1m", $result);
    }

    #[Test]
    public function formatProcessesFullStyleCombo(): void
    {
        $result = Terminal::Format('{@c:cyan,b:black,s:u}');
        $this->assertSame("\033[36m\033[40m\e[4m", $result);
    }

    #[Test]
    public function formatIgnoresUndefinedColor(): void
    {
        // An unknown color constant simply produces nothing for that part
        $result = Terminal::Format('{@c:nonexistent}');
        $this->assertSame('', $result);
    }

    #[Test]
    public function formatIgnoresUnknownStyleCode(): void
    {
        // 'z' is not a recognized style character
        $result = Terminal::Format('{@s:z}');
        $this->assertSame('', $result);
    }

    #[Test]
    public function formatSurroundsTextWithStyles(): void
    {
        $result = Terminal::Format('{@c:green}Success{@reset}');
        $this->assertSame("\033[32mSuccess\033[0m", $result);
    }

    #[Test]
    public function formatMultipleTagsInMessage(): void
    {
        $result = Terminal::Format('Hello {@c:red}World{@reset} and {@c:blue}Sky{@reset}');
        $expected = "Hello \033[31mWorld\033[0m and \033[34mSky\033[0m";
        $this->assertSame($expected, $result);
    }

    #[Test]
    public function formatLeavesNonMatchingBracesAlone(): void
    {
        $result = Terminal::Format('No {tags} here');
        $this->assertSame('No {tags} here', $result);
    }

    #[Test]
    public function formatLeavesPartialTagSyntaxAlone(): void
    {
        $result = Terminal::Format('Use {@invalid} tag');
        $this->assertSame('Use {@invalid} tag', $result);
    }

    // ══════════════════════════════════════════════════════
    // WriteLine() — static method (captures output)
    // ══════════════════════════════════════════════════════

    #[Test]
    public function writeLinePrintsFormattedMessage(): void
    {
        \ob_start();
        Terminal::WriteLine('Hello');
        $output = \ob_get_clean();
        $this->assertSame('Hello' . PHP_EOL, $output);
    }

    #[Test]
    public function writeLineAppliesFormat(): void
    {
        \ob_start();
        Terminal::WriteLine('{@c:red}Error{@reset}');
        $output = \ob_get_clean();
        $this->assertSame("\033[31mError\033[0m" . PHP_EOL, $output);
    }

    #[Test]
    public function writeLineAppliesResetStyle(): void
    {
        \ob_start();
        Terminal::WriteLine('{@c:red}Error', true);
        $output = \ob_get_clean();
        $this->assertSame("\033[31mError\033[0m" . PHP_EOL, $output);
    }

    #[Test]
    public function writeLineWithoutResetStyle(): void
    {
        \ob_start();
        Terminal::WriteLine('{@c:red}Error', false);
        $output = \ob_get_clean();
        $this->assertSame("\033[31mError" . PHP_EOL, $output);
    }

    #[Test]
    public function writeLineAppliesSprintfFormat(): void
    {
        \ob_start();
        Terminal::WriteLine('test', false, '[%s]');
        $output = \ob_get_clean();
        $this->assertSame('[test]' . PHP_EOL, $output);
    }

    #[Test]
    public function writeLineReplacesTabsWithSpaces(): void
    {
        \ob_start();
        Terminal::WriteLine("a\tb");
        $output = \ob_get_clean();
        $this->assertSame('a    b' . PHP_EOL, $output);
    }

    #[Test]
    public function writeLineWithEmptyFormatUsesMessageDirectly(): void
    {
        \ob_start();
        Terminal::WriteLine('plain', false, '   ');
        $output = \ob_get_clean();
        // Empty/whitespace-only format string is trimmed to '', so no sprintf applied
        $this->assertSame('plain' . PHP_EOL, $output);
    }

    // ══════════════════════════════════════════════════════
    // length() — visible text length
    // ══════════════════════════════════════════════════════

    #[Test]
    public function lengthReturnsCorrectForPlainText(): void
    {
        $terminal = new Terminal('test');
        $this->assertSame(5, $terminal->length('Hello'));
    }

    #[Test]
    public function lengthExcludesAnsiEscapeSequences(): void
    {
        $terminal = new Terminal('test');
        $text = "\033[31mHello\033[0m";
        $escaped = 0;
        $len = $terminal->length($text, $escaped);
        // "Hello" = 5 visible chars; escape sequences are excluded
        $this->assertSame(5, $len);
        $this->assertGreaterThan(0, $escaped);
    }

    #[Test]
    public function lengthReturnsZeroForEmptyString(): void
    {
        $terminal = new Terminal('test');
        $this->assertSame(0, $terminal->length(''));
    }

    #[Test]
    public function lengthCountsEscapedSequenceBytes(): void
    {
        $terminal = new Terminal('test');
        $escaped = 0;
        // \033[31m is 5 bytes, \033[0m is 4 bytes = 9 total escape bytes
        $text = "\033[31mRed\033[0m";
        $terminal->length($text, $escaped);
        $this->assertSame(9, $escaped);
    }

    #[Test]
    public function lengthHandlesMultipleEscapeSequences(): void
    {
        $terminal = new Terminal('test');
        $escaped = 0;
        $text = "\033[31m\033[1mBold Red\033[0m";
        $len = $terminal->length($text, $escaped);
        $this->assertSame(8, $len); // "Bold Red"
    }

    #[Test]
    public function lengthHandlesArrowKeyEscapeSequences(): void
    {
        $terminal = new Terminal('test');
        $escaped = 0;
        $text = "\033[AHello\033[B";
        $len = $terminal->length($text, $escaped);
        // Arrow key sequences (\033[A, \033[B) are 3 bytes each = 6 escape bytes
        $this->assertSame(5, $len);
        $this->assertSame(6, $escaped);
    }

    // ══════════════════════════════════════════════════════
    // logging() / addLog()
    // ══════════════════════════════════════════════════════

    #[Test]
    public function loggingReturnsSelf(): void
    {
        $terminal = new Terminal('test');
        $result = $terminal->logging(true);
        $this->assertSame($terminal, $result);
    }

    #[Test]
    public function addLogReturnsSelf(): void
    {
        $terminal = new Terminal('test');
        $result = $terminal->addLog('test message');
        $this->assertSame($terminal, $result);
    }

    // ══════════════════════════════════════════════════════
    // writeLineLogging()
    // ══════════════════════════════════════════════════════

    #[Test]
    public function writeLineLoggingOutputsAndReturnsSelf(): void
    {
        $terminal = new Terminal('test');
        $terminal->logging(true);
        \ob_start();
        $result = $terminal->writeLineLogging('Logged message');
        \ob_get_clean();
        $this->assertSame($terminal, $result);
    }

    #[Test]
    public function writeLineLoggingOutputsFormattedText(): void
    {
        $terminal = new Terminal('test');
        \ob_start();
        $terminal->writeLineLogging('Test output');
        $output = \ob_get_clean();
        $this->assertSame('Test output' . PHP_EOL, $output);
    }

    #[Test]
    public function writeLineLoggingWithResetStyle(): void
    {
        $terminal = new Terminal('test');
        \ob_start();
        $terminal->writeLineLogging('{@c:red}Error', true);
        $output = \ob_get_clean();
        $this->assertSame("\033[31mError\033[0m" . PHP_EOL, $output);
    }

    // ══════════════════════════════════════════════════════
    // run()
    // ══════════════════════════════════════════════════════

    #[Test]
    public function runExecutesCallbackBoundToTerminal(): void
    {
        $terminal = new Terminal('runner');
        $captured = null;
        \ob_start();
        $result = $terminal->run(function () use (&$captured) {
            $captured = $this->getCode();
        });
        \ob_get_clean();
        $this->assertSame('runner', $captured);
        $this->assertSame($terminal, $result);
    }

    #[Test]
    public function runPassesArguments(): void
    {
        $terminal = new Terminal('runner');
        $captured = null;
        \ob_start();
        $terminal->run(function (string $a, string $b) use (&$captured) {
            $captured = $a . '-' . $b;
        }, ['hello', 'world']);
        \ob_get_clean();
        $this->assertSame('hello-world', $captured);
    }

    #[Test]
    public function runSetsParameters(): void
    {
        $terminal = new Terminal('runner');
        $params = ['key' => 'value', 'num' => 42];
        \ob_start();
        $terminal->run(function () {
        }, [], $params);
        \ob_get_clean();
        $this->assertSame($params, $terminal->getParameters());
    }

    // ══════════════════════════════════════════════════════
    // displayHeader()
    // ══════════════════════════════════════════════════════

    #[Test]
    public function displayHeaderReturnsSelf(): void
    {
        $terminal = new Terminal('test');
        $result = $terminal->displayHeader('Test Header');
        $this->assertSame($terminal, $result);
    }

    #[Test]
    public function displayHeaderAcceptsEmptyMessage(): void
    {
        $terminal = new Terminal('test');
        $result = $terminal->displayHeader('');
        $this->assertSame($terminal, $result);
    }

    #[Test]
    public function displayHeaderAcceptsCustomLength(): void
    {
        $terminal = new Terminal('test');
        $result = $terminal->displayHeader('Header', 50);
        $this->assertSame($terminal, $result);
    }

    // ══════════════════════════════════════════════════════
    // saveLog() — writes to filesystem
    // ══════════════════════════════════════════════════════

    #[Test]
    public function saveLogWritesLogFile(): void
    {
        $terminal = new Terminal('logtest');
        $terminal->logging(true);
        $terminal->addLog('Entry one');
        $terminal->addLog('Entry two');

        $tmpDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_terminal_test_' . \uniqid();
        $result = $terminal->saveLog($tmpDir . '/');

        $this->assertTrue($result);
        // Verify a file was created in the directory
        $files = \glob($tmpDir . '/*.txt');
        $this->assertNotEmpty($files);

        // Verify content
        $content = \file_get_contents($files[0]);
        $this->assertStringContainsString('Entry one', $content);
        $this->assertStringContainsString('Entry two', $content);

        // Cleanup
        foreach ($files as $f) {
            @\unlink($f);
        }
        @\rmdir($tmpDir);
    }

    #[Test]
    public function saveLogWithExplicitFileName(): void
    {
        $terminal = new Terminal('logtest');
        $terminal->addLog('Custom file entry');

        $tmpDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_terminal_test2_' . \uniqid();
        $result = $terminal->saveLog($tmpDir . '/custom.log');

        $this->assertTrue($result);
        $this->assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'custom.log');

        $content = \file_get_contents($tmpDir . DIRECTORY_SEPARATOR . 'custom.log');
        $this->assertStringContainsString('Custom file entry', $content);

        // Cleanup
        @\unlink($tmpDir . DIRECTORY_SEPARATOR . 'custom.log');
        @\rmdir($tmpDir);
    }

    // ══════════════════════════════════════════════════════
    // Edge Cases
    // ══════════════════════════════════════════════════════

    #[Test]
    public function formatCaseInsensitiveColorNames(): void
    {
        // Color names are uppercased in the Format method
        $result = Terminal::Format('{@c:RED}');
        $this->assertSame("\033[31m", $result);
    }

    #[Test]
    public function formatHandlesMultipleTagsOnSameLine(): void
    {
        $result = Terminal::Format('{@c:red}A{@c:blue}B{@c:green}C{@reset}');
        $expected = "\033[31mA\033[34mB\033[32mC\033[0m";
        $this->assertSame($expected, $result);
    }

    #[Test]
    public function parentChildChain(): void
    {
        $root = new Terminal('root');
        $mid = new Terminal('mid', $root);
        $leaf = new Terminal('leaf', $mid);

        $this->assertNull($root->getParent());
        $this->assertSame($root, $mid->getParent());
        $this->assertSame($mid, $leaf->getParent());
        $this->assertSame('leaf', $leaf->getCode());
    }

    #[Test]
    public function formatWithAllThreeControlCodes(): void
    {
        $result = Terminal::Format('{@clear|reset|nl}');
        $this->assertStringContainsString("\033[0G\033[2K", $result);
        $this->assertStringContainsString("\033[0m", $result);
        $this->assertStringContainsString(PHP_EOL, $result);
    }

    #[Test]
    public function formatWithAllFiveStyles(): void
    {
        $result = Terminal::Format('{@s:biusk}');
        $this->assertStringContainsString("\e[1m", $result); // bold
        $this->assertStringContainsString("\e[3m", $result); // italic
        $this->assertStringContainsString("\e[4m", $result); // underline
        $this->assertStringContainsString("\e[9m", $result); // strikethrough
        $this->assertStringContainsString("\e[5m", $result); // blink
    }

    #[Test]
    public function lengthWithOnlyEscapeSequences(): void
    {
        $terminal = new Terminal('test');
        $escaped = 0;
        // String is purely ANSI escapes — visible length = 0
        $len = $terminal->length("\033[31m\033[0m", $escaped);
        $this->assertSame(0, $len);
        $this->assertSame(9, $escaped); // 5 + 4
    }
}

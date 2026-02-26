<?php

/**
 * Unit tests for Razy\FileReader.
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\Exception\FileException;
use Razy\FileReader;

#[CoversClass(FileReader::class)]
class FileReaderTest extends TestCase
{
    private string $tempDir;

    /** @var string[] Temp files to clean up */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (\file_exists($file)) {
                \unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    // ══════════════════════════════════════════════════════
    // Constructor
    // ══════════════════════════════════════════════════════

    #[Test]
    public function constructorWithValidFile(): void
    {
        $path = $this->createTempFile('hello');
        $reader = new FileReader($path);
        $this->assertInstanceOf(FileReader::class, $reader);
    }

    #[Test]
    public function constructorWithNonexistentFileThrowsFileException(): void
    {
        $this->expectException(FileException::class);
        new FileReader($this->tempDir . '/nonexistent_' . \uniqid() . '.txt');
    }

    // ══════════════════════════════════════════════════════
    // fetch() — single file, no trailing newline
    // ══════════════════════════════════════════════════════

    #[Test]
    public function fetchReadsSingleLineFileNoTrailingNewline(): void
    {
        $path = $this->createTempFile('single line');
        $reader = new FileReader($path);

        $this->assertSame('single line', $reader->fetch());
        $this->assertNull($reader->fetch());
    }

    #[Test]
    public function fetchReadsMultipleLinesNoTrailingNewline(): void
    {
        $path = $this->createTempFile("line1\nline2\nline3");
        $reader = new FileReader($path);

        $this->assertSame("line1\n", $reader->fetch());
        $this->assertSame("line2\n", $reader->fetch());
        $this->assertSame('line3', $reader->fetch());
        $this->assertNull($reader->fetch());
    }

    #[Test]
    public function fetchReturnsNullWhenExhausted(): void
    {
        $path = $this->createTempFile('only');
        $reader = new FileReader($path);

        $reader->fetch(); // consume the line
        $this->assertNull($reader->fetch());
    }

    #[Test]
    public function fetchReturnsNullAfterExhaustion(): void
    {
        $path = $this->createTempFile('x');
        $reader = new FileReader($path);

        $reader->fetch();
        $this->assertNull($reader->fetch());
        // Note: calling fetch() again after null will error because
        // FileReader::fetch() does not guard against an empty generator
        // array at entry. This is a known limitation of the current API.
    }

    // ══════════════════════════════════════════════════════
    // fetch() — single file with trailing newline
    // (SplFileObject returns an extra '' after the last \n)
    // ══════════════════════════════════════════════════════

    #[Test]
    public function fetchWithTrailingNewlineYieldsExtraEmptyString(): void
    {
        $path = $this->createTempFile("hello\n");
        $reader = new FileReader($path);

        $lines = $this->drainAll($reader);
        $this->assertSame("hello\n", $lines[0]);
        // SplFileObject produces an empty string after the final newline
        $this->assertCount(2, $lines);
        $this->assertSame('', $lines[1]);
    }

    // ══════════════════════════════════════════════════════
    // Empty file
    // ══════════════════════════════════════════════════════

    #[Test]
    public function fetchOnEmptyFileEventuallyReturnsNull(): void
    {
        $path = $this->createTempFile('');
        $reader = new FileReader($path);

        $lines = $this->drainAll($reader);
        // Empty file may yield a single '' or nothing at all — both are acceptable
        foreach ($lines as $l) {
            $this->assertSame('', $l);
        }
    }

    // ══════════════════════════════════════════════════════
    // append()
    // ══════════════════════════════════════════════════════

    #[Test]
    public function appendReturnsSelfForFluency(): void
    {
        $path1 = $this->createTempFile('A');
        $path2 = $this->createTempFile('B');

        $reader = new FileReader($path1);
        $result = $reader->append($path2);

        $this->assertSame($reader, $result);
    }

    #[Test]
    public function appendAddsFileToEndOfQueue(): void
    {
        // Use files without trailing newline to avoid extra empty-string lines
        $path1 = $this->createTempFile('A');
        $path2 = $this->createTempFile('B');

        $reader = new FileReader($path1);
        $reader->append($path2);

        $lines = $this->drainAll($reader);
        $this->assertContains('A', $lines);
        $this->assertContains('B', $lines);

        // A must come before B
        $posA = \array_search('A', $lines, true);
        $posB = \array_search('B', $lines, true);
        $this->assertLessThan($posB, $posA);
    }

    #[Test]
    public function appendWithNonexistentFileThrowsFileException(): void
    {
        $path = $this->createTempFile('ok');
        $reader = new FileReader($path);

        $this->expectException(FileException::class);
        $reader->append($this->tempDir . '/nonexistent_' . \uniqid() . '.txt');
    }

    #[Test]
    public function appendMultipleFiles(): void
    {
        $path1 = $this->createTempFile('1');
        $path2 = $this->createTempFile('2');
        $path3 = $this->createTempFile('3');

        $reader = new FileReader($path1);
        $reader->append($path2)->append($path3);

        $lines = $this->drainAll($reader);
        // Filter out any empty strings from SplFileObject boundary effects
        $meaningful = \array_values(\array_filter($lines, fn ($l) => $l !== ''));
        $this->assertSame(['1', '2', '3'], $meaningful);
    }

    // ══════════════════════════════════════════════════════
    // prepend()
    // ══════════════════════════════════════════════════════

    #[Test]
    public function prependReturnsSelfForFluency(): void
    {
        $path1 = $this->createTempFile('A');
        $path2 = $this->createTempFile('B');

        $reader = new FileReader($path1);
        $result = $reader->prepend($path2);

        $this->assertSame($reader, $result);
    }

    #[Test]
    public function prependAddsFileToFrontOfQueue(): void
    {
        $path1 = $this->createTempFile('A');
        $path2 = $this->createTempFile('B');

        $reader = new FileReader($path1);
        $reader->prepend($path2);

        $lines = $this->drainAll($reader);
        $meaningful = \array_values(\array_filter($lines, fn ($l) => $l !== ''));

        // B was prepended, so it comes first
        $this->assertSame(['B', 'A'], $meaningful);
    }

    #[Test]
    public function prependWithNonexistentFileThrowsFileException(): void
    {
        $path = $this->createTempFile('ok');
        $reader = new FileReader($path);

        $this->expectException(FileException::class);
        $reader->prepend($this->tempDir . '/nonexistent_' . \uniqid() . '.txt');
    }

    #[Test]
    public function prependMultipleFiles(): void
    {
        $path1 = $this->createTempFile('original');
        $path2 = $this->createTempFile('first_prepend');
        $path3 = $this->createTempFile('second_prepend');

        $reader = new FileReader($path1);
        $reader->prepend($path2);
        $reader->prepend($path3);

        $lines = $this->drainAll($reader);
        $meaningful = \array_values(\array_filter($lines, fn ($l) => $l !== ''));

        // second_prepend was prepended last, so it's at the front
        $this->assertSame(['second_prepend', 'first_prepend', 'original'], $meaningful);
    }

    // ══════════════════════════════════════════════════════
    // Mixed append + prepend
    // ══════════════════════════════════════════════════════

    #[Test]
    public function mixedAppendAndPrepend(): void
    {
        $pathA = $this->createTempFile('A');
        $pathB = $this->createTempFile('B');
        $pathC = $this->createTempFile('C');

        $reader = new FileReader($pathA);
        $reader->append($pathC);   // queue: A, C
        $reader->prepend($pathB);  // queue: B, A, C

        $lines = $this->drainAll($reader);
        $meaningful = \array_values(\array_filter($lines, fn ($l) => $l !== ''));

        $this->assertSame(['B', 'A', 'C'], $meaningful);
    }

    // ══════════════════════════════════════════════════════
    // Multi-line files across queue
    // ══════════════════════════════════════════════════════

    #[Test]
    public function fetchAcrossMultiLineFiles(): void
    {
        // No trailing newlines to keep output predictable
        $path1 = $this->createTempFile("L1a\nL1b");
        $path2 = $this->createTempFile("L2a\nL2b\nL2c");

        $reader = new FileReader($path1);
        $reader->append($path2);

        $lines = $this->drainAll($reader);
        $meaningful = \array_values(\array_filter($lines, fn ($l) => $l !== ''));

        $this->assertSame([
            "L1a\n",
            'L1b',
            "L2a\n",
            "L2b\n",
            'L2c',
        ], $meaningful);
    }

    // ══════════════════════════════════════════════════════
    // Fluent chaining
    // ══════════════════════════════════════════════════════

    #[Test]
    public function fluentChainingWorksForAppendAndPrepend(): void
    {
        $p1 = $this->createTempFile('1');
        $p2 = $this->createTempFile('2');
        $p3 = $this->createTempFile('3');
        $p4 = $this->createTempFile('4');

        $reader = new FileReader($p1);
        $reader->append($p2)->append($p3)->prepend($p4);

        // queue: 4, 1, 2, 3
        $lines = $this->drainAll($reader);
        $meaningful = \array_values(\array_filter($lines, fn ($l) => $l !== ''));

        $this->assertSame(['4', '1', '2', '3'], $meaningful);
    }

    // ══════════════════════════════════════════════════════
    // Edge: file without trailing newline
    // ══════════════════════════════════════════════════════

    #[Test]
    public function fetchFileWithoutTrailingNewline(): void
    {
        $path = $this->createTempFile('no newline at end');
        $reader = new FileReader($path);

        $line = $reader->fetch();
        $this->assertSame('no newline at end', $line);
        $this->assertNull($reader->fetch());
    }

    // ══════════════════════════════════════════════════════
    // Edge: file with only newlines
    // ══════════════════════════════════════════════════════

    #[Test]
    public function fetchFileWithOnlyNewlines(): void
    {
        $path = $this->createTempFile("\n\n\n");
        $reader = new FileReader($path);

        $lines = $this->drainAll($reader);
        // Three newlines = three "\n" lines + one trailing empty string
        $newlineLines = \array_filter($lines, fn ($l) => $l === "\n");
        $this->assertCount(3, $newlineLines);
    }

    // ══════════════════════════════════════════════════════
    // Edge: large number of appended files
    // ══════════════════════════════════════════════════════

    #[Test]
    public function manyAppendedFiles(): void
    {
        $firstPath = $this->createTempFile('file0');
        $reader = new FileReader($firstPath);

        for ($i = 1; $i <= 20; $i++) {
            $path = $this->createTempFile("file{$i}");
            $reader->append($path);
        }

        $lines = $this->drainAll($reader);
        $meaningful = \array_values(\array_filter($lines, fn ($l) => $l !== ''));

        $this->assertCount(21, $meaningful);
        $this->assertSame('file0', $meaningful[0]);
        $this->assertSame('file20', $meaningful[20]);
    }

    // ══════════════════════════════════════════════════════
    // Edge: constructor exception message includes path
    // ══════════════════════════════════════════════════════

    #[Test]
    public function exceptionMessageContainsFilePath(): void
    {
        $fakePath = $this->tempDir . '/nonexistent_' . \uniqid() . '.txt';

        try {
            new FileReader($fakePath);
            $this->fail('Expected FileException was not thrown');
        } catch (FileException $e) {
            $this->assertStringContainsString($fakePath, $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════
    // Edge: append exception includes path
    // ══════════════════════════════════════════════════════

    #[Test]
    public function appendExceptionMessageContainsFilePath(): void
    {
        $validPath = $this->createTempFile('ok');
        $fakePath = $this->tempDir . '/no_such_file_' . \uniqid() . '.txt';
        $reader = new FileReader($validPath);

        try {
            $reader->append($fakePath);
            $this->fail('Expected FileException was not thrown');
        } catch (FileException $e) {
            $this->assertStringContainsString($fakePath, $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════
    // Edge: prepend exception includes path
    // ══════════════════════════════════════════════════════

    #[Test]
    public function prependExceptionMessageContainsFilePath(): void
    {
        $validPath = $this->createTempFile('ok');
        $fakePath = $this->tempDir . '/no_such_file_' . \uniqid() . '.txt';
        $reader = new FileReader($validPath);

        try {
            $reader->prepend($fakePath);
            $this->fail('Expected FileException was not thrown');
        } catch (FileException $e) {
            $this->assertStringContainsString($fakePath, $e->getMessage());
        }
    }

    /**
     * Create a temporary file with the given content and register it for cleanup.
     */
    private function createTempFile(string $content, string $prefix = 'fr_test_'): string
    {
        $path = \tempnam($this->tempDir, $prefix);
        \file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Drain all lines from the reader until null is returned.
     *
     * @return string[]
     */
    private function drainAll(FileReader $reader): array
    {
        $lines = [];
        while (($line = $reader->fetch()) !== null) {
            $lines[] = $line;
        }

        return $lines;
    }
}

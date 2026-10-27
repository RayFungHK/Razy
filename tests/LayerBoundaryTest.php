<?php

// phpcs.ignore

/**
 * RZ-21: Razy never names one of its consumer applications.
 *
 * The framework once carried its dogfood app's SPA envelope label, tenant
 * names and module vendor in code, comments, docs and tests. This guard
 * fails if the app's name comes back anywhere in the shipped tree.
 *
 * @license MIT
 */

class LayerBoundaryTest extends PHPUnit\Framework\TestCase
{
    private const SCANNED = ['src', 'modules', 'manual', 'changelog', 'tests', 'documentation', 'readme.md'];

    public function testNoConsumerAppNameInFrameworkTree(): void
    {
        $needle = 'oa' . 'ao';
        $root = \dirname(__DIR__);
        $hits = [];

        foreach (self::SCANNED as $entry) {
            $path = $root . '/' . $entry;
            if (\is_file($path)) {
                $files = [$path];
            } elseif (\is_dir($path)) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            } else {
                continue;
            }
            foreach ($files as $file) {
                $file = (string) $file;
                if (!\preg_match('/\.(php|md|tpl|js|json|neon|txt|html)$/', $file)) {
                    continue;
                }
                foreach (\file($file) ?: [] as $i => $line) {
                    if (\stripos($line, $needle) !== false) {
                        $hits[] = \substr($file, \strlen($root) + 1) . ':' . ($i + 1);
                    }
                }
            }
        }

        $this->assertSame([], $hits, "consumer app name found in the framework tree:\n" . \implode("\n", $hits));
    }
}

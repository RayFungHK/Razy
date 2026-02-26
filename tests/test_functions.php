<?php

/**
 * Test helper functions - defines Razy namespace functions needed for testing.
 *
 * These mirror the utility functions defined in bootstrap.inc.php but without
 * the side effects (header_remove, exception handlers, constant definitions)
 * that make bootstrap.inc.php unsuitable for direct inclusion in tests.
 */

namespace Razy;

use Exception;

if (!\function_exists('Razy\\tidy')) {
    /**
     * Tidy the path, remove duplicated slash or backslash.
     */
    function tidy(string $path, bool $ending = false, string $separator = DIRECTORY_SEPARATOR): string
    {
        return \preg_replace('/(^\w+:\/\/\/?(*SKIP)(*FAIL))|[\/\\\\]+/', $separator, $path . ($ending ? $separator : ''));
    }
}

if (!\function_exists('Razy\\append')) {
    /**
     * Append additional path segments.
     */
    function append(string $path, ...$extra): string
    {
        $separator = DIRECTORY_SEPARATOR;
        $protocol = '';
        if (\preg_match('/^(https?:\/\/)(.*)/', $path, $matches)) {
            $protocol = $matches[1];
            $path = $matches[2];
            $separator = '/';
        }

        foreach ($extra as $pathToAppend) {
            if (\is_array($pathToAppend) && \count($pathToAppend)) {
                $path .= $separator . \implode($separator, $pathToAppend);
            } elseif (\is_scalar($pathToAppend) && \strlen($pathToAppend)) {
                $path .= $separator . $pathToAppend;
            }
        }

        return $protocol . tidy($path, false, $separator);
    }
}

if (!\function_exists('Razy\\fix_path')) {
    /**
     * Fix the string of the relative path.
     */
    function fix_path(string $path, string $separator = DIRECTORY_SEPARATOR, bool $relative = false): bool|string
    {
        $path = \trim($path);
        $isDirectory = false;
        if (isDirPath($path)) {
            $isDirectory = true;
        } elseif (\preg_match('/^\.\.?$/', $path)) {
            $isDirectory = true;
        }

        $clips = \explode($separator, \rtrim(tidy($path, false, $separator), $separator));
        $pathAry = [];
        foreach ($clips as $index => $clip) {
            if ($index > 0) {
                if ('..' == $clip) {
                    if ('..' == \end($pathAry)) {
                        $pathAry[] = '..';
                    } elseif ('.' == \end($pathAry)) {
                        $pathAry[0] = '..';
                    } else {
                        \array_pop($pathAry);
                    }
                } elseif ('.' != $clip) {
                    $pathAry[] = $clip;
                }
            } else {
                $pathAry[] = $clip;
            }
        }

        $fixedPath = \implode($separator, $pathAry) . ($isDirectory ? $separator : '');

        if ($relative && !\str_starts_with($fixedPath, $path)) {
            return false;
        }

        return $fixedPath;
    }
}

if (!\function_exists('Razy\\is_dir_path')) {
    /**
     * Check if path ends with a directory separator.
     */
    function isDirPath(string $path): bool
    {
        return $path && \preg_match('/[\\\\\/]/', \substr($path, -1));
    }
}

if (!\function_exists('Razy\\sort_path_level')) {
    /**
     * Sort the route by its folder level, deepest is priority.
     */
    function sortPathLevel(array &$routes): void
    {
        \uksort($routes, function ($path_a, $path_b) {
            $count_a = \substr_count(tidy($path_a, true, '/'), '/');
            $count_b = \substr_count(tidy($path_b, true, '/'), '/');
            if ($count_a === $count_b) {
                return 0;
            }

            return ($count_a < $count_b) ? 1 : -1;
        });
    }
}

if (!\function_exists('Razy\\is_fqdn')) {
    /**
     * Check if the string is a valid FQDN.
     */
    function isFqdn(string $domain, bool $withPort = false): bool
    {
        return 1 === \preg_match('/^(?:(?:(?:[a-z\d[\w\-*]*(?<![-_]))\.)*[a-z*]{2,}|((?:2[0-4]|1\d|[1-9])?\d|25[0-5])(?:\.(?-1)){3})' . ($withPort ? '(?::\d+)?' : '') . '$/', $domain);
    }
}

if (!\function_exists('Razy\\format_fqdn')) {
    /**
     * Format the FQDN string.
     */
    function formatFqdn(string $domain): string
    {
        return \trim(\ltrim($domain, '.'));
    }
}

if (!\function_exists('Razy\\versionStandardize')) {
    /**
     * Standardize the version code.
     */
    function standardize(string $version, bool $wildcard = false): false|string
    {
        $pattern = ($wildcard) ? '/^(\d+)(?:\.(?:\d+|\*)){0,3}$/' : '/^(\d+)(?:\.\d+){0,3}$/';
        if (!\preg_match($pattern, $version)) {
            return false;
        }

        $versions = [];
        $clips = \explode('.', $version);
        for ($i = 0; $i < 4; ++$i) {
            $clip = $clips[$i] ?? 0;
            $versions[] = ('*' == $clip) ? $clip : (int) $clip;
        }

        return \implode('.', $versions);
    }
}

if (!\function_exists('Razy\\guid')) {
    /**
     * Generate the GUID by give length.
     */
    function guid(int $length = 4): string
    {
        $length = \max(1, $length);
        $pattern = '%04X';
        if ($length > 1) {
            $pattern .= \str_repeat('-%04X', $length - 1);
        }

        $args = \array_fill(1, $length, '');
        \array_walk($args, function (&$item) {
            $item = \mt_rand(0, 65535);
        });
        \array_unshift($args, $pattern);

        return \strtolower(\call_user_func_array('sprintf', $args));
    }
}

if (!\function_exists('Razy\\getRelativePath')) {
    /**
     * Return the relative path between two paths.
     */
    function getRelativePath(string $path, string $root): string
    {
        $path = tidy($path);
        $root = tidy($root);

        $relativePath = \preg_replace('/^' . \preg_quote($root, '/\\') . '/', '', $path);
        return $relativePath ?? '';
    }
}

if (!\function_exists('Razy\\comparison')) {
    /**
     * Compare two values by provided comparison operator.
     */
    function comparison(mixed $valueA = null, mixed $valueB = null, string $operator = '=', bool $strict = false): bool
    {
        if (!$strict) {
            $valueA = (\is_scalar($valueA)) ? (string) $valueA : $valueA;
            $valueB = (\is_scalar($valueB)) ? (string) $valueB : $valueB;
        }

        if ('=' === $operator) {
            return $valueA === $valueB;
        }
        if ('!=' === $operator) {
            return $valueA !== $valueB;
        }
        if ('>' === $operator) {
            return $valueA > $valueB;
        }
        if ('>=' === $operator) {
            return $valueA >= $valueB;
        }
        if ('<' === $operator) {
            return $valueA < $valueB;
        }
        if ('<=' === $operator) {
            return $valueA <= $valueB;
        }
        if ('|=' === $operator) {
            if (!\is_scalar($valueA) || !\is_array($valueB)) {
                return false;
            }
            return \in_array($valueA, $valueB, true);
        }

        if ('^=' === $operator) {
            $valueB = '/^.*' . \preg_quote($valueB) . '/';
        } elseif ('$=' === $operator) {
            $valueB = '/' . \preg_quote($valueB) . '.*$/';
        } elseif ('*=' === $operator) {
            $valueB = '/' . \preg_quote($valueB) . '/';
        }

        return (bool) \preg_match($valueB, $valueA);
    }
}

if (!\function_exists('Razy\\autoload')) {
    /**
     * Autoloader.
     */
    function autoload(string $className, string $path = ''): bool
    {
        if (\is_dir($path)) {
            $libraryPath = append($path, $className . '.php');

            if (!\is_file($libraryPath)) {
                $splits = \explode('\\', $className);
                $libraryPath = append($path, $className, \end($splits) . '.php');
                if (!\is_file($libraryPath)) {
                    if (\str_contains($className, '_')) {
                        $splits = \explode('_', $className);
                        $classFolder = append($path, \reset($splits));
                        if (\is_dir($classFolder)) {
                            $libraryPath = append($classFolder, \implode(DIRECTORY_SEPARATOR, $splits) . '.php');
                        }
                    }
                }
            }

            if (\is_file($libraryPath)) {
                try {
                    include $libraryPath;
                    return \class_exists($className);
                } catch (Exception) {
                    return false;
                }
            }
        }

        return false;
    }
}

if (!\function_exists('Razy\\collect')) {
    /**
     * Convert the data into a Collection object.
     */
    function collect($data): Collection
    {
        return new Collection($data);
    }
}

if (!\function_exists('Razy\\is_ssl')) {
    /**
     * Check if SSL is used.
     */
    function isSsl(): bool
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO']) {
            return true;
        }
        if (!empty($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS'] || 443 === ($_SERVER['SERVER_PORT'] ?? 0)) {
            return true;
        }
        return false;
    }
}

if (!\function_exists('Razy\\env')) {
    /**
     * Retrieve an environment variable with an optional default value.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

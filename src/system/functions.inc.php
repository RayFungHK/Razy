<?php

namespace Razy;

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use DateTime;
use Exception;

/**
 * Check if the string is a valid FQDN.
 *
 * @param string $domain The FQDN string to be checked
 *
 * @return bool Return TRUE if the string is a FQDN
 */
function is_fqdn(string $domain, bool $withPort = false): bool
{
    return 1 === preg_match('/^(?:(?:(?:[a-z\d[\w\-*]*(?<![-_]))\.)*[a-z*]{2,}|((25[0-5]|2[0-4]\d1]?\d)(\.|$)){4})' . ($withPort ? '(?::\d+)?' : '') . '$/', $domain);
}

/**
 * Format the FQDN string, trim whitespace and remove any dot (.) at the beginning of the string.
 *
 * @param string $domain The FQDN string to be formatted
 *
 * @return string The formatted FQDN string
 */
function format_fqdn(string $domain): string
{
    return trim(ltrim($domain, '.'));
}

/**
 * Tidy the path, remove duplicated slash or backslash.
 *
 * @param string $path The original path
 * @param bool $ending Add a directory separator at the end of the path
 * @param string $separator The separator will be replaced in, default as directory separator
 *
 * @return string The tidied path
 */
function tidy(string $path, bool $ending = false, string $separator = DIRECTORY_SEPARATOR): string
{
    return preg_replace('/(^\w+:\/\/\/?(*SKIP)(*FAIL))|[\/\\\\]+/', $separator, $path . ($ending ? $separator : ''));
}

/**
 * Append additional path.
 *
 * @param string $path The original path
 *
 * @return string The path appended extra path
 */
function append(string $path, ...$extra): string
{
    $separator = DIRECTORY_SEPARATOR;
    $protocol  = '';
    if (preg_match('/^(https?:\/\/)(.*)/', $path, $matches)) {
        $protocol  = $matches[1];
        $path      = $matches[2];
        $separator = '/';
    }

    foreach ($extra as $pathToAppend) {
        if (is_array($pathToAppend) && count($pathToAppend)) {
            $path .= $separator . implode($separator, $pathToAppend);
        } elseif (is_scalar($pathToAppend) && strlen($pathToAppend)) {
            $path .= $separator . $pathToAppend;
        }
    }

    return $protocol . tidy($path, false, $separator);
}

/**
 * Sort the route by its folder level, deepest is priority.
 *
 * @param array &$routes An array contains the routing path
 */
function sort_path_level(array &$routes)
{
    uksort($routes, function ($path_a, $path_b) {
        $count_a = substr_count(tidy($path_a, true, '/'), '/');
        $count_b = substr_count(tidy($path_b, true, '/'), '/');
        if ($count_a === $count_b) {
            return 0;
        }

        return ($count_a < $count_b) ? 1 : -1;
    });
}

/**
 * Standardize the version code.
 *
 * @param string $version
 * @param bool $wildcard
 *
 * @return false|string
 */
function versionStandardize(string $version, bool $wildcard = false)
{
    $pattern = ($wildcard) ? '/^(\d+)(?:\.(?:\d+|\*)){0,3}$/' : '/^(\d+)(?:\.\d+){0,3}$/';
    if (!preg_match($pattern, $version)) {
        return false;
    }

    $versions = [];
    $clips    = explode('.', $version);
    for ($i = 0; $i < 4; ++$i) {
        $clip       = $clips[$i] ?? 0;
        $versions[] = ('*' == $clip) ? $clip : (int)$clip;
    }

    return implode('.', $versions);
}

/**
 * Version compare.
 *
 * @param string $requirement A string of required version
 * @param string $version The version number
 *
 * @return bool Return true if the version is meet requirement
 * @throws Error
 *
 */
function vc(string $requirement, string $version): bool
{
    $version = trim($version);
    if (($version = versionStandardize($version)) === false) {
        return false;
    }

    // Standardize the logical OR/AND character, support composer version
    $requirement = trim($requirement);
    $requirement = preg_replace('/\s*\|\|\s*/', '|', $requirement);
    $requirement = preg_replace('/\s*-\s*(*SKIP)(*FAIL)|\s*,\s*|\s+/', ',', $requirement);

    $clips  = SimpleSyntax::ParseSyntax($requirement);
    $parser = function (array &$extracted) use (&$parser, $version) {
        $result = false;

        while ($clip = array_shift($extracted)) {
            if (is_array($clip)) {
                $result = $parser($clip);
            } else {
                $clip = trim($clip);
                if (preg_match('/^((\d+)(?:\.\d+){0,3})\s*-\s*((\d+)(?:\.\d+){0,3})$/', $clip, $matches)) {
                    // Version Range
                    $min    = versionStandardize($matches[1]);
                    $max    = versionStandardize($matches[3]);
                    $result = version_compare($version, $min, '>=') && version_compare($version, $max, '<');
                } elseif (preg_match('/^(!=?|~|\^|>=?|<=?)((\d+)(?:\.\d+){0,3})$/', $clip, $matches)) {
                    $major      = (int)$matches[3];
                    $constraint = $matches[1] ?? '';
                    $vs         = versionStandardize($matches[2]);

                    if ('^' == $constraint) {
                        // Caret Version Range
                        if (0 == $major) {
                            $splits  = explode('.', $vs);
                            $compare = '0.' . $splits[1] . '.' . $splits[2] . '.' . $splits[3];
                        } else {
                            $compare = ($major + 1) . '.0.0.0';
                        }
                        $result = version_compare($version, $vs, '>=') && version_compare($version, $compare, '<');
                    } elseif ('~' == $constraint) {
                        // Tilde Version Range
                        $splits = explode('.', $vs);
                        while (count($splits) && 0 == end($splits)) {
                            unset($splits[count($splits) - 1]);
                        }
                        if (count($splits) <= 1) {
                            return false;
                        }
                        unset($splits[count($splits) - 1]);

                        if (1 == count($splits)) {
                            $compare = ($major + 1) . '.0.0.0';
                        } else {
                            ++$splits[count($splits) - 1];
                            $compare = versionStandardize(implode('.', $splits));
                        }
                        $result = version_compare($version, $vs, '>=') && version_compare($version, $compare, '<');
                    } else {
                        // Common version compare
                        if ('!' == $constraint || '!=' == $constraint) {
                            $operator = '<>';
                        } else {
                            $operator = $matches[1];
                        }
                        $result = version_compare($version, $vs, $operator);
                    }

                    // Check if logical character is exists
                    if (count($extracted)) {
                        $logical = array_shift($extracted);
                        if (!preg_match('/^[|,]$/', $logical)) {
                            return false;
                        }

                        if ('|' == $logical && $result) {
                            return true;
                        }
                        if (',' == $logical && !$result) {
                            return false;
                        }
                    }
                } elseif (preg_match('/^((\d+)(?:\.(?:\d+|\*)){0,3})$/', $clip, $matches)) {
                    $compare = versionStandardize($clip, true);
                    if (false !== strpos($compare, '*')) {
                        $compare = str_replace(['*', '.'], ['\d+', '\\.'], $compare);
                        $result  = preg_match('/^' . $compare . '$/', $version);
                    } else {
                        $result = $compare == $version;
                    }
                } else {
                    return false;
                }
            }
        }

        return $result;
    };

    return $parser($clips);
}

/**
 * Check If the SSL is used.
 *
 * @return bool [description]
 */
function is_ssl(): bool
{
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO']) {
        return true;
    }
    if (!empty($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS'] || 443 === $_SERVER['SERVER_PORT']) {
        return true;
    }

    return false;
}

/**
 * Generate the file size with the unit.
 *
 * @param float $size The file size
 * @param int $decPoint sets the number of decimal points
 * @param bool $upperCase Convert the unit into uppercase
 * @param string $separator The separator between the size and unit
 *
 * @return string The formatted file size
 */
function getFilesizeString(float $size, int $decPoint = 2, bool $upperCase = false, string $separator = ''): string
{
    $unitScale = ['byte', 'kb', 'mb', 'gb', 'tb', 'pb', 'eb', 'zb', 'yb'];
    $unit      = 'byte';
    $scale     = 0;
    $decPoint  = ($decPoint < 1) ? 0 : $decPoint;

    while ($size >= 1024 && isset($unitScale[$scale + 1])) {
        $size /= 1024;
        $unit = $unitScale[++$scale];
    }

    $size = ($decPoint) ? number_format($size, $decPoint) : (int)$size;

    if ($upperCase) {
        $unit = strtoupper($unit);
    }

    return $size . $separator . $unit;
}

/**
 * Get the visitor IP.
 *
 * @return string The ip address
 */
function getIP(): string
{
    if (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
        $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
    } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
        $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
        $ipaddress = $_SERVER['HTTP_FORWARDED'];
    } elseif (isset($_SERVER['REMOTE_ADDR'])) {
        $ipaddress = $_SERVER['REMOTE_ADDR'];
    } else {
        $ipaddress = 'UNKNOWN';
    }

    return $ipaddress;
}

/**
 * Check the IP is in the range
 *
 * @param string $ip
 * @param string $cidr
 * @return bool
 */
function ipInRange(string $ip, string $cidr): bool
{
    if (!preg_match('/^(([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.){3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])$/', $ip)) {
        return false;
    }

    if (!preg_match('/^(([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.){3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])(\/([0-9]|[1-2][0-9]|3[0-2]))?$/', $cidr)) {
        return false;
    }

    if (strpos($cidr, '/') === false) {
        $cidr .= '/32';
    }

    [$range, $netmask] = explode('/', $cidr, 2);
    $rangeDecimal          = ip2long($range);
    $ipDecimal             = ip2long($ip);
    $wildcardDecimal       = pow(2, (32 - $netmask)) - 1;
    $netmaskDecimal        = ~$wildcardDecimal;
    return (($ipDecimal & $netmaskDecimal) === ($rangeDecimal & $netmask_decimal));
}

/**
 * Merge one or more arrays recursively by following the structure
 *
 * @param array $structure
 * @param array ...$sources
 * @return array
 */
function construct(array $structure, array ...$sources): array
{
    $recursive = function ($source, $structure) use (&$recursive) {
        foreach ($structure as $key => $value) {
            if (is_array($value) && !empty($value)) {
                $structure[$key] = $recursive($source[$key], $value);
            } else {
                $structure[$key] = $source[$key] ?? $value;
            }
        }

        return $structure;
    };

    foreach ($sources as $source) {
        $structure = $recursive($structure, $source);
    }

    return $structure;
}

/**
 * Refactor an array of data into a new data set by given key set.
 *
 * @param array $source An array of data
 * @param string ...$keys An array of key to extract
 *
 * @return array An array of refactored data set
 */
function refactor(array $source, string ...$keys): array
{
    $result = [];
    $kvp    = array_keys($source);
    if (count($keys)) {
        $kvp = array_intersect($kvp, $keys);
    }

    while ($key = array_shift($kvp)) {
        foreach ($source[$key] as $index => $value) {
            if (!isset($result[$index])) {
                $result[$index] = [];
            }
            $result[$index][$key] = $value;
        }
    }

    return $result;
}

/**
 * Refactor an array of data into a new data set by given key set using a user-defined processor function.
 *
 * @param array $source An array of data
 * @param string ...$keys An array of key to extract
 *
 * @return array An array of refactored data set
 */
function urefactor(array &$source, callable $callback, string ...$keys): array
{
    $result = [];
    $kvp    = array_keys($source);
    if (count($keys)) {
        $kvp = array_intersect($kvp, $keys);
    }

    foreach ($kvp as $key) {
        foreach ($source[$key] as $index => $value) {
            if (!isset($result[$index])) {
                $result[$index] = [];
            }
            $result[$index][$key] = &$source[$key][$index];
        }
    }

    $remove = [];
    foreach ($result as $key => $value) {
        if (!$callback($key, $value)) {
            $remove[] = $key;
        }
    }

    foreach ($kvp as $key) {
        foreach ($remove as $keyToRemove) {
            unset($source[$key][$keyToRemove]);
        }
    }

    return $result;
}

/**
 * Compare two value by provided comparison operator.
 *
 * @param mixed $valueA The value of A
 * @param mixed $valueB The value of B
 * @param string $operator The comparison operator
 * @param bool $strict if the strict is set to TRUE it will also check the types of the both values
 *
 * @return bool Return the comparison result
 */
function comparison($valueA = null, $valueB = null, string $operator = '=', bool $strict = false): bool
{
    if (!$strict) {
        $valueA = (is_scalar($valueA)) ? (string)$valueA : $valueA;
        $valueB = (is_scalar($valueB)) ? (string)$valueB : $valueB;
    }

    // Equal
    if ('=' === $operator) {
        return $valueA === $valueB;
    }

    // Not equal
    if ('!=' === $operator) {
        return $valueA !== $valueB;
    }

    // Greater than
    if ('>' === $operator) {
        return $valueA > $valueB;
    }

    // Greater than and eqaul with
    if ('>=' === $operator) {
        return $valueA >= $valueB;
    }

    // Less than
    if ('<' === $operator) {
        return $valueA < $valueB;
    }

    // Less than and equal with
    if ('<=' === $operator) {
        return $valueA <= $valueB;
    }

    // Includes in
    if ('|=' === $operator) {
        if (!is_scalar($valueA) || !is_array($valueB)) {
            return false;
        }

        return in_array($valueA, $valueB, true);
    }

    if ('^=' === $operator) {
        // Beginning with
        $valueB = '/^.*' . preg_quote($valueB) . '/';
    } elseif ('$=' === $operator) {
        // End with
        $valueB = '/' . preg_quote($valueB) . '.*$/';
    } elseif ('*=' === $operator) {
        // Include
        $valueB = '/' . preg_quote($valueB) . '/';
    }

    return (bool)preg_match($valueB, $valueA);
}

/**
 * Generate the GUID by give length.
 *
 * @param int $length The length of the guid clip, each clip has 4 characters. Default value: 4
 *
 * @return string Return The GUID
 */
function guid(int $length = 4): string
{
    $length  = max(1, $length);
    $pattern = '%04X';
    if ($length > 1) {
        $pattern .= str_repeat('-%04X', $length - 1);
    }

    $args = array_fill(1, $length, '');
    array_walk($args, function (&$item) {
        $item = mt_rand(0, 65535);
    });
    array_unshift($args, $pattern);

    return strtolower(call_user_func_array('sprintf', $args));
}

/**
 * Convert the data into a Collection object.
 *
 * @param $data
 *
 * @return Collection
 */
function collect($data): Collection
{
    return new Collection($data);
}

/**
 * Return the relative path between two path
 *
 * @param string $path
 * @param string $root
 *
 * @return string
 */
function getRelativePath(string $path, string $root): string
{
    $path = tidy($path);
    $root = tidy($root);

    $relativePath = preg_replace('/^' . preg_quote($root, '/\\') . '/', '', $path);
    return $relativePath ?? '';
}

/**
 * Fix the string of the relative path.
 *
 * @param string $path
 * @param string $separator
 * @param bool $relative
 *
 * @return bool|string return the fixed path or false if the path is not a relative path if the parameter is given
 */
function fix_path(string $path, string $separator = DIRECTORY_SEPARATOR, bool $relative = false)
{
    $path        = trim($path);
    $isDirectory = false;
    if (is_dir_path($path)) {
        // If the path ending is a slash or backslash
        $isDirectory = true;
    } elseif (preg_match('/^\.\.?$/', $path)) {
        // If the path is a `.` or `..` only
        $isDirectory = true;
    }

    $clips   = explode($separator, rtrim(tidy($path, false, $separator), $separator));
    $pathAry = [];
    foreach ($clips as $index => $clip) {
        if ($index > 0) {
            if ('..' == $clip) {
                if ('..' == end($pathAry)) {
                    $pathAry[] = '..';
                } elseif ('.' == end($pathAry)) {
                    $pathAry[0] = '..';
                } else {
                    array_pop($pathAry);
                }
            } elseif ('.' != $clip) {
                $pathAry[] = $clip;
            }
        } else {
            $pathAry[] = $clip;
        }
    }

    $fixedPath = implode($separator, $pathAry) . ($isDirectory ? $separator : '');

    if ($relative && 0 !== strpos($fixedPath, $path)) {
        return false;
    }

    return $fixedPath;
}

/**
 * @param string $path
 *
 * @return bool
 */
function is_dir_path(string $path): bool
{
    return $path && preg_match('/[\\\\\/]/', substr($path, -1));
}

/**
 * Remove the directory or file recursively.
 *
 * @param string $path
 *
 * @return bool
 */
function xremove(string $path): bool
{
    $path     = tidy($path);
    $basePath = $path;

    try {
        ($recursive = function (string $path = '') use (&$recursive, $basePath) {
            $path = append($basePath, $path);
            if (is_dir($path)) {
                foreach (scandir($path) as $item) {
                    if (!preg_match('/^\.\.?$/', $item)) {
                        if (is_dir(append($path, $item))) {
                            $recursive(append($path, $item));
                        } else {
                            unlink(append($path, $item));
                        }
                    }
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        })();
    } catch (Exception $e) {
        return false;
    }

    return true;
}

/**
 * Copy the directory and file recursively.
 *
 * @param string $source
 * @param string $dest
 * @param string $pattern
 * @param null|array $unpacked
 *
 * @return bool
 */
function xcopy(string $source, string $dest, string $pattern = '', ?array &$unpacked = []): bool
{
    $source = tidy($source);
    $dest   = tidy($dest);
    if (!is_file($source) && !is_dir($source)) {
        return false;
    }

    $fileName = '';
    if (is_file($source)) {
        if (substr($dest, -1) !== '/') {
            $fileName = substr($dest, strrpos($dest, '/') + 1);
            $dest = substr($dest, 0, strrpos($dest, '/'));
        }
    }

    if (!is_dir($dest)) {
        mkdir($dest, 0777, true);
    }

    if (!$unpacked) {
        $unpacked = [];
    }

    try {
        $basePath = $source;
        ($recursive = function (string $path = '') use (&$recursive, $basePath, $dest, &$unpacked, $pattern, $fileName) {
            $source = append($basePath, $path);
            if (is_dir($source)) {
                foreach (scandir($source) as $item) {
                    if (!preg_match('/^\.\.?$/', $item)) {
                        if (is_dir(append($source, $item))) {
                            $recursive(append($path, $item));
                        } else {
                            if (!$pattern || preg_match(append($dest, $path), '/^' . preg_quote($pattern) . '$/')) {
                                if (!is_dir(append($dest, $path))) {
                                    mkdir(append($dest, $path), 0777, true);
                                }

                                $unpacked[append($source, $item)] = append($dest, $path, $item);
                                copy(append($source, $item), append($dest, $path, $item));
                            }
                        }
                    }
                }
            } else {
                $unpacked[$source] = append($dest, $fileName ?? basename($source));
                copy($source, append($dest, $fileName ?? basename($source)));
            }
        })();
    } catch (Exception $e) {
        return false;
    }

    return true;
}

/**
 * Autoloader.
 *
 * @param string $className
 * @param string $path
 *
 * @return bool
 */
function autoload(string $className, string $path = ''): bool
{
    if (is_dir($path)) {
        $libraryPath = append($path, $className . '.php');
        if (!is_file($libraryPath)) {
            $splits      = explode('\\', $className);
            $libraryPath = append($path, $className, end($splits) . '.php');
            if (!is_file($libraryPath)) {
                // Psr-0
                if (false !== strpos($className, '_')) {
                    $splits      = explode('_', $className);
                    $classFolder = append($path, reset($splits));
                    if (is_dir($classFolder)) {
                        $libraryPath = append($classFolder, implode(DIRECTORY_SEPARATOR, $splits) . '.php');
                    }
                }
            }
        }

        if (is_file($libraryPath)) {
            try {
                include $libraryPath;

                return class_exists($className);
            } catch (Exception $e) {
                return false;
            }
        }
    }

    return false;
}

/**
 * @param string $startDate
 * @param int    $numberOfDays
 * @param array  $holidays
 *
 * @return string
 * @throws Exception
 */
function getFutureWeekday(string $startDate, int $numberOfDays, array $holidays = []): string
{
    $holidays = array_fill_keys($holidays, true);
    $datetime = new DateTime($startDate);
    for ($day = 0; $day < $numberOfDays; ++$day) {
        do {
            $date = $datetime->modify('+1 weekday')->format('Y-m-d');
        } while (isset($holidays[$date]));
    }
    return $date ?? $startDate;
}
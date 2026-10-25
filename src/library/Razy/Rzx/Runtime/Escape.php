<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Rzx\Runtime;

use JsonException;
use JsonSerializable;
use Razy\Rzx\SafeHtml;
use Razy\Rzx\SafeUrl;
use stdClass;
use Stringable;

/**
 * Context escaping for compiled `.rzx` output. The compiler picks the
 * context; values never choose their own.
 *
 * | Context      | Rule                                                              |
 * |--------------|-------------------------------------------------------------------|
 * | text         | `htmlspecialchars` (quotes too); null and booleans print nothing  |
 * | attribute    | null/false omit it, true prints the bare name, a list or map is   |
 * |              | JSON (`JSON_HEX_*`) and then attribute-escaped                    |
 * | URL attribute| `http`, `https`, `mailto` or a relative URL; anything else is `#` |
 * | JSON script  | `json_encode` with `JSON_HEX_TAG|AMP|APOS|QUOT`, so `</script>`   |
 * |              | cannot close the element                                          |
 * | raw HTML     | only a SafeHtml value                                             |
 */
final class Escape
{
    public const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'poster', 'cite', 'background', 'ping', 'data', 'xlink:href', 'manifest', 'longdesc', 'codebase', 'icon', 'classid', 'archive', 'lowsrc', 'dynsrc', 'profile', 'xml:base'];

    /** Attributes holding a comma-separated list of `URL descriptor` candidates. */
    public const SRCSET_ATTRIBUTES = ['srcset', 'imagesrcset'];

    private const JSON_FLAGS = \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_THROW_ON_ERROR;

    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** HTML maps numeric references 0x80-0x9F to these windows-1252 code points. */
    private const C1 = [
        0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021,
        0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D, 0x91 => 0x2018,
        0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014, 0x98 => 0x02DC,
        0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A, 0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
    ];

    public static function html(string $text): string
    {
        return \htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }

    /**
     * The printable form of a value: strings and numbers as they are, null
     * and booleans as nothing (as in JSX). A list or map cannot be printed.
     */
    public static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null, \is_bool($value) => '',
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            $value instanceof SafeHtml => throw new RenderError('A SafeHtml value can only be printed with {html(…)}'),
            $value instanceof Stringable => (string) $value,
            default => throw new RenderError('Cannot print ' . \get_debug_type($value) . '; use join() or json()'),
        };
    }

    public static function text(mixed $value): string
    {
        return self::html(self::stringify($value));
    }

    public static function safe(mixed $value): string
    {
        if (!$value instanceof SafeHtml) {
            throw new RenderError('html() needs a SafeHtml value, got ' . \get_debug_type($value) . '; strings are always escaped');
        }

        return $value->html;
    }

    /**
     * ` name="value"`, ` name`, or '' for an expression attribute.
     */
    /**
     * @param bool $resource the attribute loads a resource (`<script src>`, `<iframe src>`, …):
     *                       only a SafeUrl or a same-origin path is allowed there
     */
    public static function attribute(string $name, mixed $value, ?callable $warn = null, bool $resource = false): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        if ($value === true) {
            return ' ' . $name;
        }
        if (\is_array($value) || $value instanceof JsonSerializable || $value instanceof stdClass) {
            $text = self::json($value);
        } else {
            $trusted = $value instanceof SafeUrl;
            $text = self::stringify($value);
            $lower = \strtolower($name);
            if ($resource && !$trusted && !self::isSameOrigin($text)) {
                if ($warn !== null) {
                    $warn('Off-site URL on a resource attribute replaced with "#": ' . \substr($text, 0, 80));
                }
                $text = '#';
            } elseif (\in_array($lower, self::URL_ATTRIBUTES, true)) {
                $text = self::url($text, $warn);
            } elseif (\in_array($lower, self::SRCSET_ATTRIBUTES, true)) {
                $text = self::srcset($text, $warn);
            }
        }

        return ' ' . $name . '="' . self::html($text) . '"';
    }

    /**
     * The URL when its scheme is allowed (or it has none), else `#`.
     */
    public static function url(string $url, ?callable $warn = null): string
    {
        if (self::isSafeUrl($url)) {
            return $url;
        }
        if ($warn !== null) {
            $warn('Unsafe URL replaced with "#": ' . \substr($url, 0, 80));
        }

        return '#';
    }

    /**
     * A srcset with each candidate's URL checked: `a.png 1x, javascript:x 2x`
     * becomes `a.png 1x, # 2x`.
     */
    public static function srcset(string $value, ?callable $warn = null): string
    {
        // HTML's srcset parsing: a URL is a run of non-whitespace (trailing
        // commas end the candidate); descriptors run to the next comma.
        $candidates = [];
        $length = \strlen($value);
        $pos = 0;
        while ($pos < $length) {
            $pos += \strspn($value, " \t\n\r\f,", $pos);
            if ($pos >= $length) {
                break;
            }
            $url = \substr($value, $pos, \strcspn($value, " \t\n\r\f", $pos));
            $pos += \strlen($url);
            $descriptor = '';
            if (\str_ends_with($url, ',')) {
                $url = \rtrim($url, ',');
            } else {
                $end = \strpos($value, ',', $pos);
                $descriptor = \trim(\substr($value, $pos, $end === false ? null : $end - $pos));
                $pos = $end === false ? $length : $end + 1;
            }
            $candidates[] = self::url($url, $warn) . ($descriptor === '' ? '' : ' ' . $descriptor);
        }

        return \implode(', ', $candidates);
    }

    /**
     * Decode character references the way a browser does in an attribute
     * value: numeric references with or without the closing `;` (`&#104ref`
     * reads `href`), then named references. Checks on literal attribute
     * values run on this, never on the raw text.
     */
    public static function decode(string $value): string
    {
        // One pass, as the HTML tokenizer does: numeric references (`;` optional)
        // become their code point, every one of them, CR and controls included;
        // NUL, surrogates and values past U+10FFFF become U+FFFD; 0x80-0x9F map
        // to windows-1252. Named references need their `;`. The result is never
        // rescanned, so `&amp;#58;` stays the text `&#58;`.
        return \preg_replace_callback(
            '/&(?:#[xX]([0-9a-fA-F]++)|#([0-9]++)|([A-Za-z][A-Za-z0-9]*););?/',
            static function (array $m): string {
                if (($m[3] ?? '') !== '') {
                    return \html_entity_decode('&' . $m[3] . ';', \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
                }
                $digits = $m[1] !== '' ? \ltrim($m[1], '0') : \ltrim($m[2], '0');
                $code = \strlen($digits) > 8 ? 0x110000 : ($m[1] !== '' ? (int) \hexdec($digits === '' ? '0' : $digits) : (int) ($digits === '' ? '0' : $digits));

                return self::utf8(self::C1[$code] ?? $code);
            },
            $value,
        ) ?? $value;
    }

    public static function isSafeSrcset(string $value): bool
    {
        // A control or space inside a scheme splits the candidate; refuse it as if
        // joined, so a literal never relies on where a browser cuts it.
        if (!self::isSafeUrl((string) \preg_replace('/[\x00-\x20\x7F]+/', '', $value))) {
            return false;
        }
        $unsafe = false;
        self::srcset($value, static function () use (&$unsafe): void {
            $unsafe = true;
        });

        return !$unsafe;
    }

    /**
     * `//host/…`, `\\host`, `/\host` or `\/host`: browsers resolve these to
     * another origin (they read `\` as `/` in http and https URLs).
     */
    public static function isProtocolRelative(string $url): bool
    {
        $normalized = \str_replace('\\', '/', (string) \preg_replace('/[\t\n\r]+/', '', \trim($url, "\x00..\x20")));

        return \str_starts_with($normalized, '//');
    }

    /**
     * A path on this origin: no scheme and not protocol-relative
     * (`/js/a.js`, `js/a.js`, `?q`, `#x`).
     */
    public static function isSameOrigin(string $url): bool
    {
        $normalized = (string) \preg_replace('/[\x00-\x20\x7F]+/', '', \trim($url, "\x00..\x20"));

        return !self::isProtocolRelative($url) && !\preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:/', $normalized);
    }

    public static function isSafeUrl(string $url): bool
    {
        // The URL parser strips leading/trailing C0 controls and space and removes
        // every tab, LF and CR; we also drop the other controls (stricter than a
        // browser) before reading the scheme up to the first `:`.
        $normalized = \preg_replace('/[\x00-\x20\x7F]+/', '', \trim($url, "\x00..\x20")) ?? '';
        if (!\preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $normalized, $m)) {
            return true; // relative, or protocol-relative (http/https)
        }

        return \in_array(\strtolower($m[1]), self::SAFE_SCHEMES, true);
    }

    public static function json(mixed $value): string
    {
        try {
            return \json_encode($value, self::JSON_FLAGS);
        } catch (JsonException $e) {
            throw new RenderError('Cannot encode the value as JSON: ' . $e->getMessage(), '', $e);
        }
    }

    /**
     * UTF-8 for a code point; NUL, surrogates and out-of-range become U+FFFD.
     */
    private static function utf8(int $code): string
    {
        if ($code === 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
            $code = 0xFFFD;
        }

        return match (true) {
            $code < 0x80 => \chr($code),
            $code < 0x800 => \chr(0xC0 | $code >> 6) . \chr(0x80 | $code & 0x3F),
            $code < 0x10000 => \chr(0xE0 | $code >> 12) . \chr(0x80 | $code >> 6 & 0x3F) . \chr(0x80 | $code & 0x3F),
            default => \chr(0xF0 | $code >> 18) . \chr(0x80 | $code >> 12 & 0x3F) . \chr(0x80 | $code >> 6 & 0x3F) . \chr(0x80 | $code & 0x3F),
        };
    }
}

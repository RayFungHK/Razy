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

    private const SAFE_SCHEMES = ['http', 'https', 'mailto'];

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
    public static function attribute(string $name, mixed $value, ?callable $warn = null): string
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
            $text = self::stringify($value);
            $lower = \strtolower($name);
            if (\in_array($lower, self::URL_ATTRIBUTES, true)) {
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
        $value = \preg_replace('/&#(x[0-9a-f]++|[0-9]++)(?!;)/i', '&#$1;', $value) ?? $value;

        return \html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    public static function isSafeSrcset(string $value): bool
    {
        $unsafe = false;
        self::srcset($value, static function () use (&$unsafe): void {
            $unsafe = true;
        });

        return !$unsafe;
    }

    public static function isSafeUrl(string $url): bool
    {
        // Browsers ignore control characters and whitespace inside the scheme.
        $normalized = \preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? '';
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
}

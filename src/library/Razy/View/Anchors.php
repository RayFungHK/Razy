<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT
 */

namespace Razy\View;

use Razy\Exception\TemplateException;

/**
 * RZ-27: named anchors that pages and components push markup into.
 *
 * A layout (or any template) prints {@see marker()} where an anchor goes; code
 * that renders later or earlier calls {@see push()}. When the outermost render
 * finishes, {@see finalize()} swaps each marker for its anchor's content. Because
 * markers are replaced at the end, a `<head>` anchor printed before the page body
 * still receives the body's pushes, without a two-phase renderer.
 *
 * - Dedupe: by `key`, else by the first `src` / `href` in the pushed markup, else
 *   by the markup itself. A stylesheet pushed by ten components is emitted once.
 * - Order: by `order`, then by first push.
 * - CSP: every `<script>` and `<link rel="modulepreload">` that enters through a
 *   push gets the request nonce; an inline script is allowed only for
 *   `type="importmap"` and `type="application/json"`.
 * - Markers carry a random token made per response ({@see reset()}). Only that
 *   exact token is replaced, so markup that merely looks like a marker (user
 *   content, a stale cache) stays literal text.
 *
 * One instance belongs to one Template manager. The Distributor calls reset() at
 * the start of every request, so worker mode cannot carry pushes over.
 *
 * Generic: no element library or application names here.
 */
final class Anchors
{
    /** Reserved anchor that receives the page body inside a layout. */
    public const CONTENT = 'content';

    private const NAME = '/^[a-z][a-z0-9_.-]*$/';

    /** @var string Random per response; never derived from the request */
    private string $token;

    /** @var array<string, true> Anchors printed in this response */
    private array $defined = [];

    /** @var array<string, array<int, array{order: int, seq: int, html: string}>> */
    private array $items = [];

    /** @var array<string, array<string, true>> Dedupe keys per anchor */
    private array $keys = [];

    /** @var array<string, true> Anchors already drained by finalize() */
    private array $drained = [];

    /** @var array<string, int> Pushes that reached an anchor no template printed */
    private array $unresolved = [];

    private int $seq = 0;

    private bool $importMapClaimed = false;

    /** @var callable():string|null */
    private $nonce;

    /**
     * @param callable():string|null $nonce Supplies this request's CSP nonce; null = no nonce injection
     */
    public function __construct(?callable $nonce = null)
    {
        $this->nonce = $nonce;
        $this->reset();
    }

    /**
     * Start a new response: new token, nothing pushed, nothing defined.
     */
    public function reset(): void
    {
        $this->token = \bin2hex(\random_bytes(12));
        $this->defined = [];
        $this->items = [];
        $this->keys = [];
        $this->drained = [];
        $this->unresolved = [];
        $this->seq = 0;
        $this->importMapClaimed = false;
    }

    /**
     * Declare that an anchor exists in this response (marker() does this too).
     */
    public function define(string $name): void
    {
        $this->defined[self::checkName($name)] = true;
    }

    /**
     * The placeholder a template prints where the anchor's content goes.
     */
    public function marker(string $name): string
    {
        $this->define($name);

        return '<!--rz-anchor:' . $this->token . ':' . $name . '-->';
    }

    /**
     * Add markup to an anchor.
     *
     * @param string $anchor Anchor name
     * @param string $html Rendered markup (already escaped by its template)
     * @param string|null $key Dedupe key; null = the first src/href, else the markup
     * @param int $order Lower first; equal orders keep push order
     *
     * @throws TemplateException When the markup holds an inline script that is not importmap / JSON
     */
    public function push(string $anchor, string $html, ?string $key = null, int $order = 0): void
    {
        self::checkName($anchor);
        if (self::CONTENT === $anchor) {
            throw new TemplateException("The anchor 'content' is reserved for the page body.");
        }
        $html = $this->secureScripts($html);
        $dedupe = $key ?? self::sourceOf($html) ?? 'html:' . \sha1($html);
        if (isset($this->keys[$anchor][$dedupe])) {
            return;
        }
        $this->keys[$anchor][$dedupe] = true;
        $this->items[$anchor][] = ['order' => $order, 'seq' => $this->seq++, 'html' => $html];
    }

    /**
     * Set the page body for the reserved `content` anchor (used by layout rendering).
     */
    public function setContent(string $html): void
    {
        $this->items[self::CONTENT] = [['order' => 0, 'seq' => $this->seq++, 'html' => $html]];
    }

    /**
     * True for the first caller in this response: the importmap is printed once.
     */
    public function claimImportMap(): bool
    {
        if ($this->importMapClaimed) {
            return false;
        }

        return $this->importMapClaimed = true;
    }

    /**
     * Replace this response's markers in $html with their anchors' content.
     *
     * The `content` anchor is filled first, so markers inside the page body are
     * resolved too. An anchor printed twice gets its content at the first marker.
     * Content pushed to an anchor no template printed is dropped and reported by
     * {@see unresolved()}.
     */
    public function finalize(string $html): string
    {
        $pattern = '/<!--rz-anchor:' . \preg_quote($this->token, '/') . ':([a-z][a-z0-9_.-]*)-->/';
        $replace = fn (array $m): string => $this->drain($m[1]);

        $content = '<!--rz-anchor:' . $this->token . ':' . self::CONTENT . '-->';
        if (\str_contains($html, $content)) {
            $html = \str_replace($content, $this->drain(self::CONTENT), $html);
        }
        $html = (string) \preg_replace_callback($pattern, $replace, $html);

        foreach ($this->items as $anchor => $list) {
            if ($list && !isset($this->defined[$anchor])) {
                $this->unresolved[$anchor] = ($this->unresolved[$anchor] ?? 0) + \count($list);
                $this->items[$anchor] = [];
            }
        }

        return $html;
    }

    /**
     * Anchors that received pushes but were never printed, with the push count.
     *
     * @return array<string, int>
     */
    public function unresolved(): array
    {
        return $this->unresolved;
    }

    /**
     * Emit and clear one anchor's content, sorted by order then push sequence.
     */
    private function drain(string $anchor): string
    {
        if (isset($this->drained[$anchor])) {
            return '';
        }
        $this->drained[$anchor] = true;
        $list = $this->items[$anchor] ?? [];
        \usort($list, static fn (array $a, array $b): int => [$a['order'], $a['seq']] <=> [$b['order'], $b['seq']]);
        $this->items[$anchor] = [];

        return \implode('', \array_column($list, 'html'));
    }

    /**
     * Refuse inline executable scripts and add the nonce to scripts and module preloads.
     *
     * @throws TemplateException
     */
    private function secureScripts(string $html): string
    {
        if (!\str_contains($html, '<script') && !\str_contains($html, 'modulepreload')) {
            return $html;
        }
        if (\preg_match_all('/<script\b([^>]*)>(.*?)<\/script\s*>/is', $html, $scripts, PREG_SET_ORDER)) {
            foreach ($scripts as $script) {
                if ('' === \trim($script[2])) {
                    continue;
                }
                $type = \preg_match('/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $script[1], $t) ? \strtolower($t[1]) : '';
                if ('importmap' !== $type && 'application/json' !== $type) {
                    throw new TemplateException('An inline <script> cannot be pushed to an anchor; use a src, or type="importmap" / "application/json".');
                }
            }
        }
        $nonce = $this->nonce ? (string) ($this->nonce)() : '';
        if ('' === $nonce) {
            return $html;
        }
        $attr = ' nonce="' . \htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"';
        $html = (string) \preg_replace('/<script\b(?![^>]*\bnonce\s*=)/i', '<script' . $attr, $html);

        return (string) \preg_replace('/<link\b(?=[^>]*\brel\s*=\s*["\']?modulepreload)(?![^>]*\bnonce\s*=)/i', '<link' . $attr, $html);
    }

    /**
     * The first src / href attribute value in the markup, as a dedupe key.
     */
    private static function sourceOf(string $html): ?string
    {
        return \preg_match('/\b(?:src|href)\s*=\s*(["\'])(.*?)\1/i', $html, $m) ? 'url:' . $m[2] : null;
    }

    /**
     * @throws TemplateException
     */
    private static function checkName(string $name): string
    {
        if (!\preg_match(self::NAME, $name)) {
            throw new TemplateException("Invalid anchor name '{$name}': use lowercase letters, digits, '.', '_' or '-'.");
        }

        return $name;
    }
}

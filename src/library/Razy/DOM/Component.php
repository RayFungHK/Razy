<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Defines the Component class for generating SPA-compatible virtual DOM
 * trees from PHP. Outputs JSON that the RazyUI SPA module can hydrate
 * into live DOM elements with event bindings.
 *
 * @license MIT
 */

namespace Razy\DOM;

use Razy\DOM;

/**
 * Represents an SPA component descriptor — a PHP mirror of the RazyUI SPA
 * `h()` virtual-node structure. Produces serialised JSON VNode trees that
 * the frontend `hydrate()` function renders into real DOM.
 *
 * Usage in a Razy controller:
 *
 *     use Razy\DOM\Component;
 *
 *     $card = Component::h('div', ['className' => 'card'],
 *         Component::h('h3', ['className' => 'card-title'], 'Hello World'),
 *         Component::h('p', 'Some descriptive text'),
 *         Component::h('button', ['className' => 'btn', 'data-action' => 'greet'], 'Click me')
 *     );
 *
 *     // In a template:  {$component}
 *     $source->assign(['component' => $card->render()]);
 *
 * @class Component
 */
class Component
{
    private string $tag;

    /** @var array<string, mixed> */
    private array $props;

    /** @var array<self|string> */
    private array $children;

    /** @var string|null Text content for text nodes */
    private ?string $text;

    /**
     * @param string $tag HTML tag name, or '#text' for text nodes
     * @param array<string, mixed> $props Attributes / data attributes
     * @param array<self|string> $children Child nodes
     * @param string|null $text Text content (only for #text nodes)
     */
    private function __construct(string $tag, array $props = [], array $children = [], ?string $text = null)
    {
        $this->tag = $tag;
        $this->props = $props;
        $this->children = $children;
        $this->text = $text;
    }

    /**
     * Create a virtual node — mirrors the RazyUI SPA `h()` function.
     *
     * Signatures:
     *   h('div', ['className' => 'card'], $child1, $child2)
     *   h('div', $child1, $child2)          — no props shorthand
     *   h('div', 'Hello')                   — text child
     *   h('div')                            — empty element
     *
     * @param string $tag HTML tag name
     * @param array|self|string|null $props Props object or first child
     * @param self|string ...$rest Additional children
     *
     * @return self
     */
    public static function h(string $tag, array|self|string|null $props = null, self|string|null ...$rest): self
    {
        $actualProps = [];
        $children = [];

        if ($props === null) {
            // h('div')
        } elseif ($props instanceof self || \is_string($props)) {
            // h('div', child, ...) — no props
            $children[] = $props;
        } elseif (!self::isAssoc($props)) {
            // h('div', [$child1, $child2]) — array of children, no props
            foreach ($props as $c) {
                if ($c !== null) {
                    $children[] = $c;
                }
            }
        } else {
            // h('div', ['className' => '...'], ...)
            $actualProps = $props;
        }

        foreach ($rest as $child) {
            if ($child !== null) {
                $children[] = $child;
            }
        }

        // Normalise children: strings → text nodes
        $normalised = [];
        foreach ($children as $child) {
            if ($child instanceof self) {
                $normalised[] = $child;
            } else {
                $normalised[] = new self('#text', [], [], (string) $child);
            }
        }

        return new self($tag, $actualProps, $normalised);
    }

    /**
     * Render multiple components as a single JSON script block for batch hydration.
     *
     * @param array<string, self> $components Map of mount-point selector → Component
     *
     * @return string HTML <script> block
     */
    public static function renderBatch(array $components): string
    {
        $batch = [];
        foreach ($components as $selector => $component) {
            $batch[$selector] = $component->toArray();
        }

        return '<script type="application/json" data-spa-batch>'
            . \htmlspecialchars(\json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_NOQUOTES, 'UTF-8')
            . '</script>';
    }

    /**
     * Check whether an array is associative (has string keys).
     */
    private static function isAssoc(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return \array_keys($arr) !== \range(0, \count($arr) - 1);
    }

    /**
     * Convert the VNode tree to a JSON-serialisable array matching the
     * RazyUI SPA VNode structure.
     *
     * @return array{_vnode: true, tag: string, props: array, children: array, text?: string}
     */
    public function toArray(): array
    {
        $node = [
            '_vnode' => true,
            'tag' => $this->tag,
            'props' => $this->props,
            'children' => \array_map(fn (self $c) => $c->toArray(), $this->children),
        ];

        if ($this->tag === '#text') {
            $node['text'] = $this->text ?? '';
        }

        return $node;
    }

    /**
     * Serialise the VNode tree to a JSON string.
     *
     * @return string
     */
    public function toJSON(): string
    {
        return \json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Render the component as an HTML container with embedded VNode data.
     * The frontend SPA `hydrate()` picks up `[data-spa-component]` elements
     * and renders the VNode tree inside them.
     *
     * @param string $id Optional element ID
     * @param string $className Optional CSS class(es) for the wrapper
     *
     * @return string HTML string
     */
    public function render(string $id = '', string $className = ''): string
    {
        $attrs = 'data-spa-component';
        if ($id) {
            $attrs .= ' id="' . \htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';
        }
        if ($className) {
            $attrs .= ' class="' . \htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '"';
        }

        return '<div ' . $attrs . '>'
            . '<script type="application/json">'
            . \htmlspecialchars($this->toJSON(), ENT_NOQUOTES, 'UTF-8')
            . '</script>'
            . '</div>';
    }

    /**
     * Render the component as a static HTML string (no hydration, pure SSR).
     * Useful for SEO or no-JS fallbacks.
     *
     * @return string
     */
    public function toHTML(): string
    {
        if ($this->tag === '#text') {
            return \htmlspecialchars($this->text ?? '', ENT_QUOTES, 'UTF-8');
        }

        $html = '<' . $this->tag;

        foreach ($this->props as $key => $value) {
            if (\str_starts_with($key, 'on') && \strlen($key) > 2) {
                continue; // Skip event handlers for static HTML
            }
            if ($key === 'className') {
                $html .= ' class="' . \htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
            } elseif (\is_bool($value)) {
                if ($value) {
                    $html .= ' ' . \htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
                }
            } else {
                $html .= ' ' . \htmlspecialchars($key, ENT_QUOTES, 'UTF-8')
                    . '="' . \htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        // Void elements
        $voidTags = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];
        if (\in_array($this->tag, $voidTags, true)) {
            return $html . ' />';
        }

        $html .= '>';
        foreach ($this->children as $child) {
            $html .= $child->toHTML();
        }
        $html .= '</' . $this->tag . '>';

        return $html;
    }
}

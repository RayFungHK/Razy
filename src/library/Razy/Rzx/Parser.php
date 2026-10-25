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

namespace Razy\Rzx;

use Razy\Rzx\Expr\ExprParser;
use Razy\Rzx\Node\AttributeNode;
use Razy\Rzx\Node\ComponentNode;
use Razy\Rzx\Node\DocumentNode;
use Razy\Rzx\Node\ElementNode;
use Razy\Rzx\Node\IfNode;
use Razy\Rzx\Node\Node;
use Razy\Rzx\Node\OutputNode;
use Razy\Rzx\Node\PropsNode;
use Razy\Rzx\Node\PushNode;
use Razy\Rzx\Node\TextNode;

/**
 * Recursive-descent parser: Lexer tokens to a DocumentNode.
 *
 * Structure rules enforced here (each error has a stable RZX0nn code and
 * file:line:col): one optional `<props>` first, exactly one root element,
 * then only `<push>` blocks (optionally inside `<if>`); every tag closed by
 * its own name; `<elseif>` / `<else>` only right after an `<if>`; `<empty>`
 * only directly inside `<for>`. Attribute rules for the control tags live in
 * TagRules. Semantics (props schema, slots, vocabulary, escaping) are the
 * compiler's job (RZ-28b).
 */
final class Parser
{
    public const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const MAX_DEPTH = 256;

    /** @var list<Token> */
    private array $tokens = [];

    private int $pos = 0;

    private int $depth = 0;

    private Source $source;

    private ExprParser $exprParser;

    /** @var array<int, list<Node>> `<empty>` bodies keyed by the offset of their `<for>` */
    private array $emptyBodies = [];

    private TagRules $rules;

    /**
     * @param list<string>|null $functions expression function names; null = not checked here
     */
    public function __construct(?array $functions = null)
    {
        $this->exprParser = new ExprParser($functions);
    }

    /**
     * JSX whitespace: trim each line (keeping spaces that sit inside a line),
     * drop blank lines, and join the rest with one space.
     */
    public static function normalizeText(string $raw): string
    {
        if (!\str_contains($raw, "\n")) {
            return $raw;
        }
        $lines = \preg_split('/\r?\n/', $raw) ?: [];
        $last = \count($lines) - 1;
        $kept = [];
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $line = \ltrim($line, " \t");
            }
            if ($i < $last) {
                $line = \rtrim($line, " \t");
            }
            if (\trim($line, " \t") !== '') {
                $kept[] = $line;
            }
        }

        return \implode(' ', $kept);
    }

    public static function describe(Node $node): string
    {
        return match (true) {
            $node instanceof ElementNode => '<' . $node->tag . '>',
            $node instanceof ComponentNode => '<' . $node->name . '>',
            $node instanceof TextNode => 'text',
            $node instanceof OutputNode => 'an expression',
            default => '<' . TagRules::tagOf($node) . '>',
        };
    }

    public function parseFile(string $path): DocumentNode
    {
        return $this->parse(Source::fromFile($path));
    }

    public function parse(Source $source): DocumentNode
    {
        $this->source = $source;
        $this->tokens = Lexer::tokenize($source);
        $this->pos = 0;
        $this->depth = 0;
        $this->emptyBodies = [];
        $this->rules = new TagRules($source);

        $props = null;
        $this->skipBlank();
        if ($this->peek()->type === Token::PROPS) {
            $token = $this->next();
            $props = new PropsNode($token->offset, $token->value, $token->bodyOffset);
        }
        $nodes = $this->children(null);
        if ($nodes === []) {
            throw $this->error('RZX024', 'A .rzx file needs exactly one root element', $this->peek()->offset);
        }
        $root = \array_shift($nodes);
        if (!$root instanceof ElementNode && !$root instanceof ComponentNode) {
            throw $this->error('RZX022', 'The root must be an element or a component, not ' . self::describe($root), $root->offset);
        }
        foreach ($nodes as $node) {
            if (!$this->isPushBlock($node)) {
                $reason = $node instanceof ElementNode || $node instanceof ComponentNode
                    ? 'A .rzx file has exactly one root element; wrap them in one element'
                    : 'Only <push> blocks (optionally inside <if>) may follow the root element';
                throw $this->error('RZX023', $reason, $node->offset);
            }
        }

        /** @var list<PushNode|IfNode> $nodes */
        return new DocumentNode($source, $props, $root, $nodes);
    }

    /**
     * Child nodes up to `</$closing>` (or EOF when $closing is null). The
     * closing tag is consumed. At the top level, whitespace is dropped and
     * other text is an error.
     *
     * @return list<Node>
     */
    private function children(?Token $open): array
    {
        $nodes = [];
        $lastIf = null;
        while (true) {
            $token = $this->peek();
            switch ($token->type) {
                case Token::EOF:
                    if ($open !== null) {
                        throw $this->error('RZX014', '<' . $open->value . '> is never closed', $open->offset);
                    }

                    return $nodes;
                case Token::CLOSE:
                    $this->next();
                    if (($open === null || $token->value !== $open->value) && \in_array($token->value, self::VOID_ELEMENTS, true)) {
                        throw $this->error('RZX015', '<' . $token->value . '> is a void element and has no closing tag', $token->offset);
                    }
                    if ($open === null) {
                        throw $this->error('RZX016', 'Unexpected </' . $token->value . '>: no element is open', $token->offset);
                    }
                    if ($token->value !== $open->value) {
                        [$line, $column] = $this->source->position($open->offset);
                        $reason = \sprintf('Expected </%s> to close <%s> opened at %d:%d, found </%s>', $open->value, $open->value, $line, $column, $token->value);
                        throw $this->error('RZX012', $reason, $token->offset);
                    }

                    return $nodes;
                case Token::TEXT:
                    [$raw, $at] = $this->textRun();
                    $text = self::normalizeText($raw);
                    $blank = \trim($text, " \t\r\n") === '';
                    // Whitespace is dropped at the top level, and between </if> and <elseif>/<else>.
                    if ($text === '' || ($blank && ($open === null || ($lastIf !== null && $this->nextIsBranch())))) {
                        break;
                    }
                    if ($open === null) {
                        throw $this->error('RZX021', 'Text outside the root element', $at);
                    }
                    $nodes[] = new TextNode($at, $text);
                    $lastIf = null;
                    break;
                case Token::EXPR:
                    $this->next();
                    if ($open === null) {
                        throw $this->error('RZX021', 'An expression outside the root element', $token->offset);
                    }
                    $nodes[] = new OutputNode($token->offset, $this->exprParser->parseTokens($this->source, $token->expr ?? [], $token->offset));
                    $lastIf = null;
                    break;
                case Token::PROPS:
                    throw $this->error('RZX020', '<props> must be the first thing in the file', $token->offset);
                case Token::OPEN:
                    $this->next();
                    $name = $token->value;
                    if ($name === 'elseif' || $name === 'else') {
                        if ($lastIf === null || $lastIf->else !== null) {
                            throw $this->error('RZX052', '<' . $name . '> must directly follow </if> or </elseif>', $token->offset);
                        }
                        [$attributes, $selfClosed] = $this->openTag($token);
                        $body = $selfClosed ? [] : $this->nested($token);
                        $this->rules->attachBranch($lastIf, $token, $attributes, $body);
                        break;
                    }
                    if ($name === 'empty') {
                        $this->emptyBlock($token, $open);
                        $lastIf = null;
                        break;
                    }
                    $node = $this->element($token);
                    $nodes[] = $node;
                    $lastIf = $node instanceof IfNode ? $node : null;
                    break;
                default:
                    throw $this->error('RZX010', 'Unexpected token', $token->offset);
            }
        }
    }

    private function element(Token $token): Node
    {
        $name = $token->value;
        if ($name === 'props') {
            throw $this->error('RZX020', '<props> takes no attributes and must be the first thing in the file', $token->offset);
        }
        [$attributes, $selfClosed] = $this->openTag($token);
        $void = \in_array($name, self::VOID_ELEMENTS, true);
        $children = $selfClosed || $void ? [] : $this->nested($token);

        if (TagRules::isSpecial($name)) {
            $empty = $this->emptyBodies[$token->offset] ?? null;
            unset($this->emptyBodies[$token->offset]);

            return $this->rules->build($token, $attributes, $children, $empty);
        }
        if (\ctype_upper($name[0])) {
            if (!Names::isComponent($name)) {
                throw $this->error('RZX017', 'Invalid component name <' . $name . '>; use PascalCase, namespaced with dots (Pkg.Name)', $token->offset);
            }

            return new ComponentNode($token->offset, $name, $attributes, $children);
        }
        if (!Names::isElement($name)) {
            throw $this->error('RZX017', 'Invalid element name <' . $name . '>; use lowercase (custom elements with dashes)', $token->offset);
        }

        return new ElementNode($token->offset, $name, $attributes, $children, $void);
    }

    /**
     * Children of an open tag, with the nesting limit.
     *
     * @return list<Node>
     */
    private function nested(Token $open): array
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw $this->error('RZX019', 'Elements are nested too deeply (limit ' . self::MAX_DEPTH . ')', $open->offset);
        }
        $children = $this->children($open);
        --$this->depth;

        return $children;
    }

    /**
     * `<empty>…</empty>` directly inside `<for>`: its body is kept aside and
     * handed to the ForNode when the `<for>` closes.
     */
    private function emptyBlock(Token $token, ?Token $parent): void
    {
        if ($parent === null || $parent->value !== 'for') {
            throw $this->error('RZX053', '<empty> is only allowed directly inside <for>', $token->offset);
        }
        if (isset($this->emptyBodies[$parent->offset])) {
            throw $this->error('RZX053', '<for> can have only one <empty>', $token->offset);
        }
        [$attributes, $selfClosed] = $this->openTag($token);
        if ($attributes !== []) {
            throw $this->error('RZX051', '<empty> takes no attributes', $attributes[0]->offset);
        }
        $this->emptyBodies[$parent->offset] = $selfClosed ? [] : $this->nested($token);
    }

    /**
     * Read the attributes after an OPEN token, up to `>` or `/>`.
     *
     * @return array{list<AttributeNode>, bool} attributes and whether the tag self-closed
     */
    private function openTag(Token $open): array
    {
        $attributes = [];
        $seen = [];
        while ($this->peek()->type === Token::ATTR) {
            $token = $this->next();
            if (isset($seen[$token->value])) {
                throw $this->error('RZX013', 'Duplicate attribute ' . $token->value . ' on <' . $open->value . '>', $token->offset);
            }
            $seen[$token->value] = true;
            $expr = $token->expr === null ? null : $this->exprParser->parseTokens($this->source, $token->expr, $token->bodyOffset);
            $attributes[] = new AttributeNode($token->offset, $token->value, $token->literal, $expr);
        }

        return [$attributes, $this->next()->type === Token::SELF_CLOSE];
    }

    /**
     * A `<push>`, or an `<if>` whose branches hold only pushes (at least one).
     * Whitespace text around the pushes is removed from the branches.
     */
    private function isPushBlock(Node $node): bool
    {
        if ($node instanceof PushNode) {
            return true;
        }
        if (!$node instanceof IfNode) {
            return false;
        }
        $pushes = 0;
        $strip = static fn (array $body): array => \array_values(\array_filter($body, static fn (Node $n): bool => !($n instanceof TextNode && \trim($n->text) === '')));
        foreach ($node->branches as $i => [$test, $body]) {
            $node->branches[$i] = [$test, $strip($body)];
        }
        if ($node->else !== null) {
            $node->else = $strip($node->else);
        }
        foreach ([...\array_column($node->branches, 1), $node->else ?? []] as $body) {
            foreach ($body as $child) {
                if (!$this->isPushBlock($child)) {
                    return false;
                }
                ++$pushes;
            }
        }

        return $pushes > 0;
    }

    /**
     * Consume consecutive TEXT tokens (split only by dropped comments) as one run.
     *
     * @return array{string, int} the raw text and the offset of its first
     *                            visible character (or of the run, when blank)
     */
    private function textRun(): array
    {
        $raw = '';
        $at = null;
        $first = $this->peek()->offset;
        while ($this->peek()->type === Token::TEXT) {
            $token = $this->next();
            $raw .= $token->value;
            $skip = \strspn($token->value, " \t\r\n");
            if ($at === null && $skip < \strlen($token->value)) {
                $at = $token->offset + $skip;
            }
        }

        return [$raw, $at ?? $first];
    }

    private function nextIsBranch(): bool
    {
        $next = $this->peek();

        return $next->type === Token::OPEN && ($next->value === 'elseif' || $next->value === 'else');
    }

    private function skipBlank(): void
    {
        while ($this->peek()->type === Token::TEXT && \trim($this->peek()->value) === '') {
            ++$this->pos;
        }
    }

    private function peek(): Token
    {
        return $this->tokens[$this->pos];
    }

    private function next(): Token
    {
        $token = $this->tokens[$this->pos];
        if ($token->type !== Token::EOF) {
            ++$this->pos;
        }

        return $token;
    }

    private function error(string $code, string $reason, int $offset): CompileError
    {
        return new CompileError($code, $reason, $this->source, $offset);
    }
}

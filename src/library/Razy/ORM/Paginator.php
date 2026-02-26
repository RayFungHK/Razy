<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\ORM;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use JsonException;
use JsonSerializable;
use LogicException;
use Traversable;

/**
 * Paginator wraps a page of ORM results with metadata and helpers.
 *
 * Provides typed getters, URL link generation, convenience booleans,
 * and JSON/array serialization for API responses.
 *
 * Implements `ArrayAccess` for backward compatibility with the legacy
 * `paginate()` array format (`$page['data']`, `$page['total']`, etc.).
 *
 * Usage:
 * ```php
 * $paginator = User::query($db)->paginate(2, 15);
 *
 * // Typed API
 * $paginator->items();       // ModelCollection
 * $paginator->currentPage(); // 2
 * $paginator->hasMorePages(); // bool
 *
 * // URL generation
 * $paginator->setPath('/users');
 * $paginator->nextPageUrl(); // "/users?page=3"
 *
 * // JSON API response
 * echo $paginator->toJson(); // {"data":[...],"total":50,...,"links":{...}}
 *
 * // Backward-compatible array access
 * $paginator['data'];      // ModelCollection
 * $paginator['total'];     // 50
 * ```
 *
 * @implements ArrayAccess<string, mixed>
 * @implements IteratorAggregate<int, Model>
 *
 * @package Razy\ORM
 */
class Paginator implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * The items for the current page.
     */
    private ModelCollection $items;

    /**
     * Total number of matching records (null for simple pagination).
     */
    private ?int $total;

    /**
     * The current page number (1-based).
     */
    private int $currentPage;

    /**
     * Number of items per page.
     */
    private int $perPage;

    /**
     * The last available page (null when total is unknown).
     */
    private ?int $lastPage;

    /**
     * Whether there are more pages after the current one (for simple pagination).
     */
    private ?bool $hasMore;

    /**
     * The base path for URL generation (e.g. "/users").
     */
    private string $path = '/';

    /**
     * The query string parameter name for the page number.
     */
    private string $pageName = 'page';

    /**
     * Additional query string parameters to append to URLs.
     *
     * @var array<string, string>
     */
    private array $queryParams = [];

    /**
     * Create a new paginator instance.
     *
     * @param ModelCollection $items Items for the current page
     * @param int|null $total Total record count (null for simple pagination)
     * @param int $currentPage Current page number (1-based)
     * @param int $perPage Items per page
     * @param bool|null $hasMore Whether more pages exist (for simple pagination)
     */
    public function __construct(
        ModelCollection $items,
        ?int $total,
        int $currentPage,
        int $perPage,
        ?bool $hasMore = null,
    ) {
        $this->items = $items;
        $this->total = $total;
        $this->perPage = \max(1, $perPage);
        $this->hasMore = $hasMore;

        if ($total !== null) {
            $this->lastPage = \max(1, (int) \ceil($total / $this->perPage));
            $this->currentPage = \max(1, \min($currentPage, $this->lastPage));
        } else {
            $this->lastPage = null;
            $this->currentPage = \max(1, $currentPage);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Getters
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get the items for the current page.
     */
    public function items(): ModelCollection
    {
        return $this->items;
    }

    /**
     * Get the total number of matching records.
     *
     * Returns null for simple pagination (when total is unknown).
     */
    public function total(): ?int
    {
        return $this->total;
    }

    /**
     * Get the current page number.
     */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Get the number of items per page.
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Get the last page number.
     *
     * Returns null for simple pagination (when total is unknown).
     */
    public function lastPage(): ?int
    {
        return $this->lastPage;
    }

    // ═══════════════════════════════════════════════════════════════
    // Convenience Booleans
    // ═══════════════════════════════════════════════════════════════

    /**
     * Whether there are more pages after this one.
     */
    public function hasMorePages(): bool
    {
        if ($this->hasMore !== null) {
            return $this->hasMore;
        }

        return $this->lastPage !== null && $this->currentPage < $this->lastPage;
    }

    /**
     * Whether the paginator is on the first page.
     */
    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    /**
     * Whether the paginator is on the last page.
     *
     * For simple pagination: returns `!hasMorePages()`.
     */
    public function onLastPage(): bool
    {
        if ($this->lastPage !== null) {
            return $this->currentPage >= $this->lastPage;
        }

        return !$this->hasMorePages();
    }

    /**
     * Whether there are multiple pages of results.
     *
     * For simple pagination: returns true if not on both first and last page.
     */
    public function hasPages(): bool
    {
        if ($this->lastPage !== null) {
            return $this->lastPage > 1;
        }

        return !$this->onFirstPage() || $this->hasMorePages();
    }

    /**
     * Whether the current page has no items.
     */
    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /**
     * Whether the current page has items.
     */
    public function isNotEmpty(): bool
    {
        return $this->items->isNotEmpty();
    }

    // ═══════════════════════════════════════════════════════════════
    // URL Generation
    // ═══════════════════════════════════════════════════════════════

    /**
     * Set the base path for URL generation.
     *
     * @param string $path The base URL path (e.g. "/users", "/api/posts")
     */
    public function setPath(string $path): static
    {
        $this->path = \rtrim($path, '/') ?: '/';

        return $this;
    }

    /**
     * Get the base path used for URL generation.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Set the query parameter name for the page number.
     *
     * @param string $name Parameter name (default: "page")
     */
    public function setPageName(string $name): static
    {
        $this->pageName = $name;

        return $this;
    }

    /**
     * Get the page parameter name.
     */
    public function getPageName(): string
    {
        return $this->pageName;
    }

    /**
     * Add extra query parameters to generated URLs.
     *
     * @param array<string, string> $params Key-value pairs
     */
    public function appends(array $params): static
    {
        $this->queryParams = \array_merge($this->queryParams, $params);

        return $this;
    }

    /**
     * Generate the URL for a given page number.
     */
    public function url(int $page): string
    {
        $page = \max(1, $page);

        $params = \array_merge($this->queryParams, [$this->pageName => $page]);
        $query = \http_build_query($params, '', '&');

        return $this->path . '?' . $query;
    }

    /**
     * Get the URL for the first page.
     */
    public function firstPageUrl(): string
    {
        return $this->url(1);
    }

    /**
     * Get the URL for the last page, or null if unknown (simple pagination).
     */
    public function lastPageUrl(): ?string
    {
        if ($this->lastPage === null) {
            return null;
        }

        return $this->url($this->lastPage);
    }

    /**
     * Get the URL for the previous page, or null if on page 1.
     */
    public function previousPageUrl(): ?string
    {
        if ($this->currentPage <= 1) {
            return null;
        }

        return $this->url($this->currentPage - 1);
    }

    /**
     * Get the URL for the next page, or null if on the last page.
     */
    public function nextPageUrl(): ?string
    {
        if (!$this->hasMorePages()) {
            return null;
        }

        return $this->url($this->currentPage + 1);
    }

    // ═══════════════════════════════════════════════════════════════
    // Page Range
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get an array of page numbers surrounding the current page.
     *
     * Returns `null` for simple pagination (when total/lastPage is unknown).
     *
     * Example with currentPage=5, onEachSide=2, lastPage=10:
     * ```php
     * $paginator->getPageRange(2); // [3, 4, 5, 6, 7]
     * ```
     *
     * @param int $onEachSide Number of pages to show on each side of the current page
     *
     * @return list<int>|null
     */
    public function getPageRange(int $onEachSide = 3): ?array
    {
        if ($this->lastPage === null) {
            return null;
        }

        $start = \max(1, $this->currentPage - $onEachSide);
        $end = \min($this->lastPage, $this->currentPage + $onEachSide);

        return \range($start, $end);
    }

    /**
     * Get structured link data for rendering pagination controls.
     *
     * Returns an array with `first`, `last`, `prev`, `next` URLs
     * and a `pages` array of `{page, url, active}` entries.
     *
     * @param int $onEachSide Pages to show on each side of current page
     *
     * @return array{
     *     first: string,
     *     last: string|null,
     *     prev: string|null,
     *     next: string|null,
     *     pages: list<array{page: int, url: string, active: bool}>
     * }
     */
    public function links(int $onEachSide = 3): array
    {
        $pages = [];
        $range = $this->getPageRange($onEachSide);

        if ($range !== null) {
            foreach ($range as $page) {
                $pages[] = [
                    'page' => $page,
                    'url' => $this->url($page),
                    'active' => $page === $this->currentPage,
                ];
            }
        }

        return [
            'first' => $this->firstPageUrl(),
            'last' => $this->lastPageUrl(),
            'prev' => $this->previousPageUrl(),
            'next' => $this->nextPageUrl(),
            'pages' => $pages,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Serialization
    // ═══════════════════════════════════════════════════════════════

    /**
     * Convert the paginator to an array.
     *
     * @return array{
     *     data: array<int, array<string, mixed>>,
     *     total: int|null,
     *     page: int,
     *     per_page: int,
     *     last_page: int|null,
     *     from: int|null,
     *     to: int|null,
     *     links: array{first: string, last: string|null, prev: string|null, next: string|null, pages: list<array{page: int, url: string, active: bool}>}
     * }
     */
    public function toArray(): array
    {
        $count = \count($this->items);

        return [
            'data' => $this->items->toArray(),
            'total' => $this->total,
            'page' => $this->currentPage,
            'per_page' => $this->perPage,
            'last_page' => $this->lastPage,
            'from' => $count > 0 ? ($this->currentPage - 1) * $this->perPage + 1 : null,
            'to' => $count > 0 ? ($this->currentPage - 1) * $this->perPage + $count : null,
            'links' => $this->links(),
        ];
    }

    /**
     * Convert the paginator to a JSON string.
     *
     * @param int $options `json_encode` flags
     *
     * @throws JsonException
     */
    public function toJson(int $options = 0): string
    {
        return \json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Specify data which should be serialized to JSON.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    // ═══════════════════════════════════════════════════════════════
    // ArrayAccess (backward compatibility)
    // ═══════════════════════════════════════════════════════════════

    /**
     * @param mixed $offset Key name: 'data', 'total', 'page', 'per_page', or 'last_page'
     */
    public function offsetExists(mixed $offset): bool
    {
        return \in_array($offset, ['data', 'total', 'page', 'per_page', 'last_page'], true);
    }

    /**
     * @param mixed $offset Key name
     */
    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'data' => $this->items,
            'total' => $this->total,
            'page' => $this->currentPage,
            'per_page' => $this->perPage,
            'last_page' => $this->lastPage,
            default => null,
        };
    }

    /**
     * Not supported — paginator is read-only.
     *
     * @throws LogicException always
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Paginator is read-only.');
    }

    /**
     * Not supported — paginator is read-only.
     *
     * @throws LogicException always
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Paginator is read-only.');
    }

    // ═══════════════════════════════════════════════════════════════
    // Countable & IteratorAggregate
    // ═══════════════════════════════════════════════════════════════

    /**
     * Count of items on the current page.
     */
    public function count(): int
    {
        return \count($this->items);
    }

    /**
     * Iterate over the items on the current page.
     */
    public function getIterator(): Traversable
    {
        return $this->items->getIterator();
    }
}

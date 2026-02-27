# Razy\ORM\Paginator

## Summary

Pagination result object with typed getters, URL generation, JSON serialisation, and backward-compatible `ArrayAccess`. Returned by `ModelQuery::paginate()` and `ModelQuery::simplePaginate()`.

## Namespace

`Razy\ORM`

## Implements

`ArrayAccess`, `Countable`, `IteratorAggregate`, `JsonSerializable`

## Constructor

```php
public function __construct(
    ModelCollection $items,
    ?int $total,           // null for simplePaginate
    int $currentPage,
    int $perPage,
    ?bool $hasMore = null, // Used by simplePaginate
)
```

## Getter Methods

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `items` | `(): ModelCollection` | `ModelCollection` | Current page items |
| `total` | `(): ?int` | `?int` | Total records. `null` for `simplePaginate` |
| `currentPage` | `(): int` | `int` | 1-based page number |
| `perPage` | `(): int` | `int` | Items per page |
| `lastPage` | `(): ?int` | `?int` | Last page number. `null` if total unknown |

## Boolean Helpers

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `hasMorePages` | `(): bool` | `bool` | More pages after current? |
| `onFirstPage` | `(): bool` | `bool` | On page 1? |
| `onLastPage` | `(): bool` | `bool` | On last page? |
| `hasPages` | `(): bool` | `bool` | More than one page? |
| `isEmpty` | `(): bool` | `bool` | No items? |
| `isNotEmpty` | `(): bool` | `bool` | Has items? |

## URL Generation

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `setPath` | `(string $path): static` | `static` | Base URL path |
| `getPath` | `(): string` | `string` | Current base path |
| `setPageName` | `(string $name): static` | `static` | Query param name (default `'page'`) |
| `getPageName` | `(): string` | `string` | |
| `appends` | `(array $params): static` | `static` | Extra query parameters |
| `url` | `(int $page): string` | `string` | URL for specific page |
| `firstPageUrl` | `(): string` | `string` | URL for page 1 |
| `lastPageUrl` | `(): ?string` | `?string` | URL for last page (null if total unknown) |
| `previousPageUrl` | `(): ?string` | `?string` | URL for previous page (null if on page 1) |
| `nextPageUrl` | `(): ?string` | `?string` | URL for next page (null if on last page) |

## Page Range

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `getPageRange` | `(int $onEachSide = 3): ?array` | `?list<int>` | Window of page numbers around current |
| `links` | `(int $onEachSide = 3): array` | `array` | Full nav structure: `{first, last, prev, next, pages: [{url, label, active}]}` |

## Serialisation

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `toArray` | `(): array` | `array` | `{data, total, page, per_page, last_page, from, to, links}` |
| `toJson` | `(int $options = 0): string` | `string` | JSON string |
| `jsonSerialize` | `(): mixed` | `mixed` | For `json_encode()` |

## ArrayAccess (Backward Compatibility)

Read-only access using keys: `data`, `total`, `page`, `per_page`, `last_page`.

```php
$page['data'];      // ModelCollection
$page['total'];     // ?int
$page['page'];      // int
$page['per_page'];  // int
$page['last_page']; // ?int
```

`offsetSet` and `offsetUnset` throw `\LogicException` (read-only).

## Countable & Iterator

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `count` | `(): int` | `int` | Items on current page |
| `getIterator` | `(): Traversable` | `ArrayIterator` | Delegates to `ModelCollection` |

## Usage Examples

### Full Pagination

```php
$page = User::query($db)
    ->where('active=:a', ['a' => 1])
    ->orderBy('name')
    ->paginate(2, 15);  // page 2, 15 per page

$page->items();        // ModelCollection
$page->total();        // 105
$page->currentPage();  // 2
$page->perPage();      // 15
$page->lastPage();     // 7
$page->count();        // 15 (items on this page)

$page->hasMorePages();  // true
$page->onFirstPage();   // false
$page->onLastPage();    // false
```

### Simple Pagination

```php
$page = User::query($db)
    ->orderBy('created_at', 'DESC')
    ->simplePaginate(1, 25);

$page->total();     // null
$page->lastPage();  // null
```

### URL Generation

```php
$page->setPath('/api/users');
$page->appends(['sort' => 'name', 'filter' => 'active']);

$page->url(3);             // /api/users?sort=name&filter=active&page=3
$page->firstPageUrl();     // /api/users?sort=name&filter=active&page=1
$page->previousPageUrl();  // /api/users?sort=name&filter=active&page=1
$page->nextPageUrl();      // /api/users?sort=name&filter=active&page=3
```

### JSON Response

```php
// Implements JsonSerializable
header('Content-Type: application/json');
echo json_encode($page);

// Output:
// {
//   "data": [...],
//   "total": 105,
//   "page": 2,
//   "per_page": 15,
//   "last_page": 7,
//   "from": 16,
//   "to": 30,
//   "links": { "first": "...", "last": "...", "prev": "...", "next": "...", "pages": [...] }
// }
```

### Pagination UI

```php
$nav = $page->links(3);

// $nav = [
//   'first' => '/users?page=1',
//   'last'  => '/users?page=7',
//   'prev'  => '/users?page=1',
//   'next'  => '/users?page=3',
//   'pages' => [
//     ['url' => '/users?page=1', 'label' => '1', 'active' => false],
//     ['url' => '/users?page=2', 'label' => '2', 'active' => true],
//     ...
//   ]
// ]
```

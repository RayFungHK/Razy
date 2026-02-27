# Database Query Syntax - Quick Reference

Fast lookup guide for TableJoinSyntax and WhereSyntax operators.

---

## Join Operators

| Operator | Type | Syntax | SQL |
|----------|------|--------|-----|
| `<` | LEFT JOIN | `users<posts[user_id]` | `LEFT JOIN posts ON posts.user_id = users.user_id` |
| `<<` | LEFT OUTER | `users<<posts[user_id]` | `LEFT OUTER JOIN posts ON ...` |
| `>` | RIGHT JOIN | `users>posts[user_id]` | `RIGHT JOIN posts ON posts.user_id = users.user_id` |
| `>>` | RIGHT OUTER | `users>>posts[user_id]` | `RIGHT OUTER JOIN posts ON ...` |
| `-` | INNER JOIN | `users-posts[user_id]` | `INNER JOIN posts ON posts.user_id = users.user_id` |
| `*` | CROSS JOIN | `users*products` | `CROSS JOIN products` |

---

## Join Condition Formats

| Format | Description | Example |
|--------|-------------|---------|
| `[column]` | Match on column | `posts[user_id]` → `ON posts.user_id = users.user_id` |
| `[:columns]` | USING clause | `posts[:user_id]` → `USING (user_id)` |
| `[table:columns]` | Explicit source | `posts[users:id]` → `ON users.id = posts.id` |
| `[?condition]` | WHERE syntax | `posts[?user_id,status="active"]` → `ON ... AND status='active'` |

---

## Where Comparison Operators

| Operator | Meaning | Example | SQL |
|----------|---------|---------|-----|
| `=` | Equal | `status="active"` | `status = 'active'` |
| `!=` | Not equal | `status!="deleted"` | `status <> 'deleted'` |
| `<>` | Not equal | `status<>"deleted"` | `status <> 'deleted'` |
| `>` | Greater | `age>18` | `age > 18` |
| `<` | Less | `price<100` | `price < 100` |
| `>=` | Greater/equal | `quantity>=10` | `quantity >= 10` |
| `<=` | Less/equal | `discount<=50` | `discount <= 50` |
| `><` | Not equal | `price><100` | `price <> 100` |

---

## String Operators

| Operator | Type | Example | SQL |
|----------|------|---------|-----|
| `*=` | Contains | `name*="john"` | `name LIKE '%john%'` |
| `^=` | Starts with | `email^="admin"` | `email LIKE 'admin%'` |
| `$=` | Ends with | `file$=".pdf"` | `file LIKE '%.pdf'` |
| `#=` | Regex | `phone#="^\\+1"` | `phone REGEXP '^\\+1'` |

---

## JSON Operators

| Operator | Purpose | Example | SQL |
|----------|---------|---------|-----|
| `\|=` | In array | `tags\|="php"` | `JSON_CONTAINS(tags, '"php"', '$') > 0` |
| `~=` | Contains value | `meta~="enabled"` | `JSON_CONTAINS(meta, '"enabled"') = 1` |
| `&=` | Search value | `data&="test"` | `JSON_SEARCH(data, 'one', 'test') IS NOT NULL` |
| `@=` | Key exists | `settings@="theme"` | `JSON_SEARCH(JSON_KEYS(settings), ...) IS NOT NULL` |
| `:=` | Path exists | `data:="$.user.name"` | `JSON_EXTRACT(data, '$.user.name') IS NOT NULL` |

---

## Logical Operators

| Operator | Type | Example | SQL |
|----------|------|---------|-----|
| `,` | AND | `active=1,verified=1` | `active = 1 AND verified = 1` |
| `\|` | OR | `status="a"\|status="b"` | `status = 'a' OR status = 'b'` |
| `!` | NOT | `!deleted=1` | `deleted <> 1` |
| `()` | Group | `(a=1\|b=1),c=1` | `(a = 1 OR b = 1) AND c = 1` |

---

## Quick Examples

### Basic Join

```php
// LEFT JOIN
->from('users<posts[user_id]')

// INNER JOIN  
->from('users-posts[user_id]')

// With aliases
->from('u.users<p.posts[user_id]')
```

### Multiple Joins

```php
->from('users<posts[user_id]<comments[post_id]')
// users LEFT JOIN posts LEFT JOIN comments
```

### Basic WHERE

```php
// Simple conditions
->where('status="active"')
->where('age>18')
->where('email!=null')
```

### AND Conditions

```php
->where('status="active",verified=1,age>=18')
// WHERE status = 'active' AND verified = 1 AND age >= 18
```

### OR Conditions

```php
->where('status="active"|status="pending"')
// WHERE status = 'active' OR status = 'pending'
```

### Mixed Conditions

```php
->where('(status="active"|status="trial"),verified=1')
// WHERE (status = 'active' OR status = 'trial') AND verified = 1
```

### Parameter Binding

```php
// Auto-bind
->where('status=?,age>?')
->assign(['status' => 'active', 'age' => 18])

// Named parameters
->where('status=:st,age>:age')
->assign(['st' => 'active', 'age' => 18])
```

### String Matching

```php
// Contains
->where('name*="john"')
// LIKE '%john%'

// Starts with
->where('email^="admin"')
// LIKE 'admin%'

// Regex
->where('phone#="^\\+1"')
// REGEXP '^\\+1'
```

### Array Operations

```php
->where('status|=?')
->assign(['status' => ['active', 'pending', 'processing']])
// WHERE status IN('active', 'pending', 'processing')
```

### JSON Operations

```php
// Array contains
->where('tags|="php"')

// Value contains
->where('meta~="enabled"')

// Key exists
->where('settings@="theme"')

// Path exists
->where('data:="$.user.email"')
```

### Negation

```php
// NOT equal
->where('!status="deleted"')

// NOT LIKE
->where('!name*="test"')

// NOT IN
->where('!role|=?')
->assign(['role' => ['admin', 'superuser']])
```

---

## Common Patterns

### Active Records with Pagination

```php
$stmt = $db->prepare()
    ->from('users')
    ->where('status="active",verified=1')
    ->orderBy('created_at', 'DESC')
    ->limit(20)
    ->offset($page * 20);
```

### Search with Filters

```php
$stmt = $db->prepare()
    ->from('products')
    ->where('name*=?,price>=?,price<=?,in_stock=1')
    ->assign([
        'name' => $search,
        'price' => [$minPrice, $maxPrice]
    ])
    ->orderBy('price', 'ASC');
```

### User Posts with Count

```php
$stmt = $db->prepare()
    ->select('u.*', 'COUNT(p.id) as post_count')
    ->from('u.users<p.posts[user_id]')
    ->where('u.status="active"')
    ->groupBy('u.id')
    ->having('post_count>0');
```

### Complex Filtering

```php
$stmt = $db->prepare()
    ->from('products')
    ->where('
        (category_id=?|featured=1),
        (price>=?,price<=?),
        !status="discontinued",
        tags|="bestseller"
    ')
    ->assign([
        'category_id' => $catId,
        'price' => [$min, $max]
    ]);
```

---

## Cheat Sheet

### Join Types Quick Pick

- **Need all left rows?** → Use `<` (LEFT JOIN)
- **Need only matches?** → Use `-` (INNER JOIN)
- **Need all right rows?** → Use `>` (RIGHT JOIN)
- **Need all combinations?** → Use `*` (CROSS JOIN)

### Where Clause Quick Pick

- **Exact match** → `column="value"`
- **Contains text** → `column*="text"`
- **Range** → `column>=min,column<=max`
- **Multiple options** → `column|=?` with array
- **JSON array** → `column|="value"`
- **NULL check** → `column=null` or `column!=null`

### Performance Tips

1. ✅ Use `-` (INNER JOIN) when both sides must match
2. ✅ Index columns used in JOIN conditions
3. ✅ Use parameter binding (`?` or `:name`)
4. ✅ Filter with WHERE before HAVING
5. ✅ Add indexes on WHERE clause columns

---

## Operator Precedence

1. **Parentheses** `()`
2. **NOT** `!`
3. **Comparison** `=`, `<`, `>`, `*=`, etc.
4. **AND** `,`
5. **OR** `|`

**Example:**
```php
'!status="deleted"|role="admin",verified=1'
// Evaluated as: ((!status="deleted") | role="admin") , verified=1
// Result: (NOT deleted OR admin) AND verified
```

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| "Invalid Table Join Syntax" | Check join condition: `table[column]` |
| "Invalid Where Syntax" | Balance parentheses, check operators |
| Parameters not binding | Match parameter names in `assign()` |
| JSON operator fails | Ensure column is JSON type |
| Ambiguous column | Use table aliases: `u.column` |

---

## Remember

- **Comma `,`** = AND
- **Pipe `|`** = OR  
- **Exclamation `!`** = NOT
- **Question `?`** = Auto-bind parameter
- **Colon `:name`** = Named parameter
- **Brackets `[]`** = Join condition
- **Backticks `` ` ``  = Special characters in names

---

## Full Documentation

For complete details, examples, and advanced usage:
→ [DATABASE-QUERY-SYNTAX.md](DATABASE-QUERY-SYNTAX.md)

---

**Version**: 0.5.4  
**Last Updated**: February 8, 2026

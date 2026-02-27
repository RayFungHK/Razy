# Database Query Syntax - TableJoinSyntax & WhereSyntax

Complete guide to Razy's powerful table join and where clause syntax for building complex SQL queries.

---

## Table of Contents

1. [Overview](#overview)
2. [TableJoinSyntax](#tablejoinsynta)
   - [Join Types](#join-types)
   - [Join Conditions](#join-conditions)
   - [Table Aliases](#table-aliases)
   - [Subqueries](#subqueries)
   - [Complex Joins](#complex-joins)
3. [WhereSyntax](#wheresyntax)
   - [Basic Comparisons](#basic-comparisons)
   - [String Matching](#string-matching)
   - [JSON Operations](#json-operations)
   - [Array Operations](#array-operations)
   - [Logical Operators](#logical-operators)
   - [Negation](#negation)
4. [Complete Examples](#complete-examples)
5. [Best Practices](#best-practices)

---

### Overview

Razy provides a concise, powerful syntax for building SQL queries without writing raw SQL. The syntax is designed to be:

- **Readable** - Easy to understand at a glance
- **Type-safe** - Reduces SQL injection risks
- **Flexible** - Supports complex queries
- **Maintainable** - Changes are easier to track

### Quick Example

```php
// Traditional SQL
$sql = "SELECT u.*, p.title FROM users u 
        LEFT JOIN posts p ON u.id = p.user_id 
        WHERE u.status = 'active' AND p.published = 1";

// Razy Syntax
$stmt = $db->prepare()
    ->select('u.*', 'p.title')
    ->from('u.users<p.posts[user_id]')
    ->where('u.status="active",p.published=1');
```

---

### TableJoinSyntax

TableJoinSyntax provides a compact syntax for defining table joins with various join types and conditions.

### Join Types

Razy supports all standard SQL join types using symbolic operators:

| Symbol | Join Type | SQL Equivalent |
|--------|-----------|----------------|
| `<` | LEFT JOIN | `LEFT JOIN` |
| `<<` | LEFT OUTER JOIN | `LEFT OUTER JOIN` |
| `>` | RIGHT JOIN | `RIGHT JOIN` |
| `>>` | RIGHT OUTER JOIN | `RIGHT OUTER JOIN` |
| `-` | INNER JOIN | `JOIN` / `INNER JOIN` |
| `*` | CROSS JOIN | `CROSS JOIN` |
| (none) | Simple FROM | No join |

### Basic Join Syntax

```php
// Format: table1<table2[condition]
$stmt = $db->prepare()->from('users<posts[user_id]');
// SQL: FROM users LEFT JOIN posts ON users.id = posts.user_id
```

#### Example: LEFT JOIN

```php
$stmt = $db->prepare()
    ->select('*')
    ->from('users<orders[user_id]');

// SQL: SELECT * FROM users LEFT JOIN orders ON users.id = orders.user_id
```

#### Example: RIGHT JOIN

```php
$stmt = $db->prepare()
    ->select('*')
    ->from('users>orders[user_id]');

// SQL: SELECT * FROM users RIGHT JOIN orders ON users.id = orders.user_id
```

#### Example: INNER JOIN

```php
$stmt = $db->prepare()
    ->select('*')
    ->from('users-posts[user_id]');

// SQL: SELECT * FROM users INNER JOIN posts ON users.id = posts.user_id
```

#### Example: CROSS JOIN

```php
$stmt = $db->prepare()
    ->select('*')
    ->from('users*products');

// SQL: SELECT * FROM users CROSS JOIN products
```

---

### Join Conditions

Join conditions define how tables are related. Razy provides several condition syntaxes:

#### 1. Simple Column Match `[column]`

Automatically joins on matching column names:

```php
->from('users<posts[user_id]')
// ON users.user_id = posts.user_id
```

#### 2. USING Clause `[:columns]`

For tables with identical column names:

```php
->from('users<posts[:user_id]')
// USING (user_id)

// Multiple columns
->from('orders<order_items[:order_id,product_id]')
// USING (order_id, product_id)
```

#### 3. Custom ON Condition `[source:columns]`

Specify source table explicitly:

```php
->from('users<posts[users:id]')
// ON users.id = posts.id

->from('u.users<p.posts[u:id,user_id]')
// ON u.id = p.id AND u.user_id = p.user_id
```

#### 4. WHERE-Style Condition `[?condition]`

Use full WHERE syntax in join condition:

```php
->from('users<posts[?user_id,status="active"]')
// ON posts.user_id = users.user_id AND posts.status = 'active'

->from('users<posts[?user_id,created_at>?]')
// ON posts.user_id = users.user_id AND posts.created_at > :created_at
```

---

### Table Aliases

Table aliases enable clearer queries and prevent naming conflicts:

#### Basic Aliasing

```php
// Format: alias.table_name
->from('u.users<p.posts[user_id]')
// FROM users AS u LEFT JOIN posts AS p ON u.user_id = p.user_id

$stmt = $db->prepare()
    ->select('u.name', 'p.title')
    ->from('u.users<p.posts[user_id]')
    ->where('u.status="active"');
```

#### Aliasing with Complex Names

```php
// Backticks for special characters
->from('u.`user-data`<p.`post_data`[user_id]')
```

---

### Subqueries

Subqueries allow using query results as tables:

#### Basic Subquery

```php
$stmt = $db->prepare()
    ->select('active_users.name', 'orders.total')
    ->from('active_users.users<orders[?user_id]');

// Create subquery for active_users alias
$activeUsersQuery = $stmt->alias('active_users');
$activeUsersQuery
    ->select('id', 'name')
    ->from('users')
    ->where('status="active"');

// SQL: 
// FROM (SELECT id, name FROM users WHERE status = 'active') AS active_users
// LEFT JOIN orders ON orders.user_id = active_users.id
```

#### Multiple Subqueries

```php
$stmt = $db->prepare()
    ->select('*')
    ->from('recent.posts<top.comments[post_id]');

// Recent posts subquery
$stmt->alias('recent')
    ->select('*')
    ->from('posts')
    ->where('created_at>=?')
    ->orderBy('created_at', 'DESC')
    ->limit(100);

// Top comments subquery
$stmt->alias('top')
    ->select('*')
    ->from('comments')
    ->where('votes>=10');
```

---

### Complex Joins

#### Multiple Joins

Chain multiple tables together:

```php
$stmt = $db->prepare()
    ->from('users<posts[user_id]<comments[post_id]');

// SQL: 
// FROM users 
// LEFT JOIN posts ON posts.user_id = users.user_id
// LEFT JOIN comments ON comments.post_id = posts.post_id
```

#### Mixed Join Types

```php
$stmt = $db->prepare()
    ->from('users<posts[user_id]-categories[category_id]>tags[tag_id]');

// SQL:
// FROM users
// LEFT JOIN posts ON posts.user_id = users.user_id
// INNER JOIN categories ON categories.category_id = posts.category_id
// RIGHT JOIN tags ON tags.tag_id = categories.tag_id
```

#### Nested Conditions

```php
$stmt = $db->prepare()
    ->from('users<(posts<comments[post_id])[?user_id,posts.status="published"]');

// SQL:
// FROM users
// LEFT JOIN (
//     SELECT * FROM posts 
//     LEFT JOIN comments ON comments.post_id = posts.post_id
// ) ON posts.user_id = users.user_id AND posts.status = 'published'
```

---

### WhereSyntax

WhereSyntax provides a powerful, concise syntax for building WHERE clauses with support for complex conditions.

### Basic Comparisons

#### Equality and Inequality

```php
// Equal
->where('status="active"')
// WHERE status = 'active'

// Not equal
->where('status!="deleted"')
// WHERE status <> 'deleted'

// Greater than
->where('age>18')
// WHERE age > 18

// Less than
->where('price<100')
// WHERE price < 100

// Greater than or equal
->where('quantity>=10')
// WHERE quantity >= 10

// Less than or equal
->where('discount<=50')
// WHERE discount <= 50

// Not equal (alternative)
->where('status<>"pending"')
// WHERE status <> 'pending'

// Spaceship operator
->where('price><100')
// WHERE price <> 100
```

#### NULL Checks

```php
// IS NULL
->where('deleted_at=null')
// WHERE deleted_at IS NULL

// IS NOT NULL
->where('email!=null')
// WHERE email IS NOT NULL
```

#### Column Comparison

```php
// Compare two columns
->where('created_at<updated_at')
// WHERE created_at < updated_at

->where('min_price<=max_price')
// WHERE min_price <= max_price
```

---

### String Matching

#### LIKE Operators

```php
// Contains (both sides wildcard)
->where('name*="john"')
// WHERE name LIKE '%john%'

// Starts with (right wildcard)
->where('email^="admin"')
// WHERE email LIKE 'admin%'

// Ends with (left wildcard)
->where('filename$=".pdf"')
// WHERE filename LIKE '%.pdf'

// NOT LIKE
->where('!name*="test"')
// WHERE name NOT LIKE '%test%'
```

#### REGEXP

```php
// Regular expression match
->where('phone#="^\\+1"')
// WHERE phone REGEXP '^\\+1'

// NOT REGEXP
->where('!email#="@example\\.com$"')
// WHERE email NOT REGEXP '@example\\.com$'
```

---

### JSON Operations

Razy provides powerful JSON operations for modern databases:

#### JSON Contains Array `|=`

Check if a value exists in a JSON array:

```php
// Check if array contains value
->where('tags|="php"')
// WHERE JSON_CONTAINS(tags, '"php"', '$') > 0

// Check if array contains parameter
->where('tags|=?')
->assign(['tags' => 'javascript'])
// WHERE tags IN('javascript')
```

#### JSON Contains Value `~=`

Check if JSON contains a specific value:

```php
->where('meta~="enabled"')
// WHERE JSON_CONTAINS(meta, '"enabled"') = 1

->where('settings~=?')
->assign(['settings' => ['theme' => 'dark']])
// WHERE JSON_CONTAINS(settings, '{"theme":"dark"}') = 1
```

#### JSON Search `&=`

Search for a value anywhere in JSON:

```php
->where('config&="production"')
// WHERE JSON_SEARCH(config, 'one', 'production') IS NOT NULL

->where('!data&="test"')
// WHERE JSON_SEARCH(data, 'one', 'test') IS NULL
```

#### JSON Key Exists `@=`

Check if a JSON key exists:

```php
->where('settings@="theme"')
// WHERE JSON_SEARCH(JSON_KEYS(settings), "one", "theme") IS NOT NULL

->where('!metadata@="deprecated"')
// WHERE JSON_SEARCH(JSON_KEYS(metadata), "one", "deprecated") IS NULL
```

#### JSON Path Exists `:=`

Check if a JSON path exists:

```php
->where('data:="$.user.name"')
// WHERE JSON_EXTRACT(data, '$.user.name') IS NOT NULL

->where('!config:="$.debug.enabled"')
// WHERE JSON_EXTRACT(config, '$.debug.enabled') IS NULL
```

---

### Array Operations

#### IN Clause

```php
// Check if column value is in array
->where('status|=?')
->assign(['status' => ['active', 'pending', 'processing']])
// WHERE status IN('active', 'pending', 'processing')

// NOT IN
->where('!role|=?')
->assign(['role' => ['admin', 'superuser']])
// WHERE role NOT IN('admin', 'superuser')

// Empty array results in NULL check
->where('status|=?')
->assign(['status' => []])
// WHERE status IS NULL
```

---

### Logical Operators

#### AND (Comma)

```php
// Multiple conditions with AND
->where('status="active",age>=18,verified=1')
// WHERE status = 'active' AND age >= 18 AND verified = 1
```

#### OR (Pipe)

```php
// Multiple conditions with OR
->where('status="pending"|status="processing"')
// WHERE status = 'pending' OR status = 'processing'
```

#### Combining AND & OR

```php
// Mixed operators (use parentheses for clarity)
->where('(status="active"|status="pending"),age>=18')
// WHERE (status = 'active' OR status = 'pending') AND age >= 18
```

#### Parentheses for Grouping

```php
->where('((status="active",verified=1)|(role="admin"))')
// WHERE ((status = 'active' AND verified = 1) OR (role = 'admin'))
```

---

### Negation

#### Basic Negation

```php
// Negate with !
->where('!status="deleted"')
// WHERE status <> 'deleted'

->where('!verified=1')
// WHERE verified <> 1
```

#### Double Negation

```php
// Double ! cancels out
->where('!!status="active"')
// WHERE status = 'active'

// Triple ! is same as single !
->where('!!!verified=1')
// WHERE verified <> 1
```

#### Negating Groups

```php
// Negate entire group
->where('!(status="deleted"|status="banned")')
// WHERE !(status = 'deleted' OR status = 'banned')
// Equivalent to: WHERE status <> 'deleted' AND status <> 'banned'
```

---

### Parameter Binding

#### Auto-binding with `?`

The `?` operator automatically binds values from the statement:

```php
// Auto-bind from column name
->where('status=?')
->assign(['status' => 'active'])
// WHERE status = 'active'

// Multiple auto-binds
->where('status=?,age>?,verified=?')
->assign([
    'status' => 'active',
    'age' => 18,
    'verified' => true
])
// WHERE status = 'active' AND age > 18 AND verified = 1
```

#### Named Parameters

```php
// Use :name for explicit parameters
->where('status=:user_status,created_at>:start_date')
->assign([
    'user_status' => 'active',
    'start_date' => '2024-01-01'
])
// WHERE status = 'active' AND created_at > '2024-01-01'
```

#### NULL Handling

```php
// When parameter is null, converts to IS NULL
->where('deleted_at=?')
->assign(['deleted_at' => null])
// WHERE deleted_at IS NULL

->where('deleted_at!=?')
->assign(['deleted_at' => null])
// WHERE deleted_at IS NOT NULL
```

---

### Complete Examples

### Example 1: User Posts with Comments

```php
$db = new Database($config);

$stmt = $db->prepare()
    ->select('u.name', 'p.title', 'COUNT(c.id) as comment_count')
    ->from('u.users<p.posts[user_id]<c.comments[post_id]')
    ->where('u.status="active",p.published=1')
    ->groupBy('p.id')
    ->having('comment_count>5')
    ->orderBy('comment_count', 'DESC');

$results = $stmt->execute()->fetchAll();
```

**Generated SQL:**
```sql
SELECT u.name, p.title, COUNT(c.id) as comment_count
FROM users AS u
LEFT JOIN posts AS p ON p.user_id = u.user_id
LEFT JOIN comments AS c ON c.post_id = p.post_id
WHERE u.status = 'active' AND p.published = 1
GROUP BY p.id
HAVING comment_count > 5
ORDER BY comment_count DESC
```

---

### Example 2: Product Search with Categories

```php
$stmt = $db->prepare()
    ->select('p.*', 'c.name as category_name')
    ->from('p.products-c.categories[category_id]')
    ->where('p.name*=?,p.price>=?,p.price<=?,p.in_stock=1')
    ->assign([
        'name' => $searchTerm,
        'price' => $minPrice,
        'price' => $maxPrice
    ])
    ->orderBy('p.price', 'ASC')
    ->limit(20);

$products = $stmt->execute()->fetchAll();
```

---

### Example 3: Complex Join with Subquery

```php
$stmt = $db->prepare()
    ->select('au.*, o.order_count')
    ->from('au.users<o.orders[user_id]');

// Active users subquery
$stmt->alias('au')
    ->select('id', 'name', 'email')
    ->from('users')
    ->where('status="active",last_login_at>=?')
    ->assign(['last_login_at' => date('Y-m-d', strtotime('-30 days'))]);

// Order count subquery
$stmt->alias('o')
    ->select('user_id', 'COUNT(*) as order_count')
    ->from('orders')
    ->where('status="completed"')
    ->groupBy('user_id');

$activeUsersWithOrders = $stmt->execute()->fetchAll();
```

---

### Example 4: JSON Data Filtering

```php
$stmt = $db->prepare()
    ->select('*')
    ->from('products')
    ->where('tags|="electronics",meta@="warranty",(price<100|featured=1)')
    ->orderBy('created_at', 'DESC');

// Find products:
// - Tagged as "electronics"
// - Have "warranty" key in meta JSON
// - Either price < 100 OR featured = 1

$products = $stmt->execute()->fetchAll();
```

---

### Example 5: Multi-level Joins with Conditions

```php
$stmt = $db->prepare()
    ->select('u.name', 'p.title', 'c.content', 'cat.name as category')
    ->from('u.users<p.posts[?user_id,p.status="published"]<c.comments[?post_id,c.approved=1]-cat.categories[category_id]')
    ->where('u.verified=1,p.created_at>=?')
    ->assign(['created_at' => '2024-01-01'])
    ->orderBy('p.created_at', 'DESC')
    ->limit(50);

$results = $stmt->execute()->fetchAll();
```

**Generated SQL:**
```sql
SELECT u.name, p.title, c.content, cat.name as category
FROM users AS u
LEFT JOIN posts AS p ON p.user_id = u.user_id AND p.status = 'published'
LEFT JOIN comments AS c ON c.post_id = p.post_id AND c.approved = 1
INNER JOIN categories AS cat ON cat.category_id = p.category_id
WHERE u.verified = 1 AND p.created_at >= '2024-01-01'
ORDER BY p.created_at DESC
LIMIT 50
```

---

### Example 6: Advanced WHERE with Multiple Operators

```php
$stmt = $db->prepare()
    ->select('*')
    ->from('users')
    ->where('
        (status="active"|status="trial"),
        (email!=null,email#="@company\\.com$"),
        (age>=18,age<=65),
        !(role="banned"|role="suspended"),
        tags|=?
    ')
    ->assign(['tags' => ['premium', 'verified']])
    ->orderBy('created_at', 'DESC');

// Find users:
// - Status is active OR trial
// - Email exists AND matches company domain
// - Age between 18-65
// - NOT banned or suspended
// - Has premium OR verified tag

$users = $stmt->execute()->fetchAll();
```

---

### Example 7: JSON Search and Filtering

```php
$stmt = $db->prepare()
    ->select('id', 'name', 'settings')
    ->from('users')
    ->where('
        settings:="$.theme",
        settings~=?,
        metadata@="subscription_tier",
        !preferences&="notifications_disabled"
    ')
    ->assign(['settings' => ['theme' => 'dark']])
    ->limit(100);

// Find users:
// - Have theme setting in JSON
// - Theme is set to "dark"
// - Have subscription_tier key in metadata
// - Do NOT have "notifications_disabled" anywhere in preferences

$users = $stmt->execute()->fetchAll();
```

---

### Best Practices

### 1. Use Table Aliases for Complex Queries

```php
// ✅ Good - Clear aliases
->from('u.users<p.posts[user_id]<c.comments[post_id]')
->where('u.status="active",p.published=1,c.approved=1')

// ❌ Bad - No aliases in complex join
->from('users<posts[user_id]<comments[post_id]')
->where('users.status="active"') // Ambiguous
```

### 2. Use Parameter Binding

```php
// ✅ Good - Safe from injection
->where('email=?,status=?')
->assign(['email' => $userInput, 'status' => $status])

// ❌ Bad - SQL injection risk
->where('email="' . $userInput . '"')
```

### 3. Group Complex Conditions

```php
// ✅ Good - Clear grouping
->where('(status="active"|status="pending"),(verified=1|role="admin")')

// ❌ Bad - Ambiguous precedence
->where('status="active"|status="pending",verified=1|role="admin"')
```

### 4. Use Appropriate Join Types

```php
// ✅ Good - Use INNER JOIN when you need matching records
->from('orders-order_items[order_id]')

// ❌ Bad - LEFT JOIN when you always need matches
->from('orders<order_items[order_id]')
```

### 5. Leverage JSON Operators

```php
// ✅ Good - Use JSON operators for JSON columns
->where('tags|="php",metadata@="version"')

// ❌ Bad - Manual JSON parsing
->where('FIND_IN_SET("php", tags)>0')
```

### 6. Be Explicit with Subqueries

```php
// ✅ Good - Clear alias and purpose
$stmt->alias('recent_posts')
    ->select('*')
    ->from('posts')
    ->where('created_at>=?')
    ->limit(100);

// ❌ Bad - No clear purpose
$stmt->alias('temp')
    ->select('*')
    ->from('posts');
```

### 7. Use USING for Simple Joins

```php
// ✅ Good - Clean syntax for identical column names
->from('users<user_profiles[:user_id]')

// ⚠️ Acceptable - More verbose but explicit
->from('users<user_profiles[users:id]')
```

### 8. Validate User Input

```php
// ✅ Good - Validate before query
if (!in_array($status, ['active', 'pending', 'deleted'])) {
    throw new Error('Invalid status');
}
->where('status=?')
->assign(['status' => $status])

// ❌ Bad - No validation
->where('status=?')
->assign(['status' => $_GET['status']])
```

---

## Operator Quick Reference

### Join Operators

| Operator | Join Type | Example |
|----------|-----------|---------|
| `<` | LEFT JOIN | `users<posts[user_id]` |
| `<<` | LEFT OUTER JOIN | `users<<posts[user_id]` |
| `>` | RIGHT JOIN | `users>posts[user_id]` |
| `>>` | RIGHT OUTER JOIN | `users>>posts[user_id]` |
| `-` | INNER JOIN | `users-posts[user_id]` |
| `*` | CROSS JOIN | `users*products` |

### Where Operators

| Operator | Meaning | Example |
|----------|---------|---------|
| `=` | Equal | `status="active"` |
| `!=` | Not equal | `status!="deleted"` |
| `<>` | Not equal | `status<>"deleted"` |
| `>` | Greater than | `age>18` |
| `<` | Less than | `price<100` |
| `>=` | Greater or equal | `quantity>=10` |
| `<=` | Less or equal | `discount<=50` |
| `><` | Not equal | `price><100` |
| `*=` | Contains (LIKE) | `name*="john"` |
| `^=` | Starts with | `email^="admin"` |
| `$=` | Ends with | `file$=".pdf"` |
| `#=` | Matches regex | `phone#="^\\+1"` |
| `\|=` | In array | `status\|=?` |
| `~=` | JSON contains | `tags~="php"` |
| `&=` | JSON search | `data&="value"` |
| `@=` | JSON key exists | `meta@="key"` |
| `:=` | JSON path exists | `data:="$.path"` |
| `,` | AND | `active=1,verified=1` |
| `\|` | OR | `status="a"\|status="b"` |
| `!` | NOT | `!deleted=1` |

---

## Troubleshooting

### Common Issues

#### Issue: "Invalid Table Join Syntax"

**Cause**: Missing or incorrect join condition

**Solution**:
```php
// ❌ Wrong
->from('users<posts')

// ✅ Correct
->from('users<posts[user_id]')
```

#### Issue: "Invalid Where Syntax"

**Cause**: Unbalanced parentheses or incorrect operators

**Solution**:
```php
// ❌ Wrong
->where('(status="active",verified=1')

// ✅ Correct
->where('(status="active",verified=1)')
```

#### Issue: Parameters not binding

**Cause**: Parameter name mismatch

**Solution**:
```php
// ❌ Wrong
->where('status=?')
->assign(['user_status' => 'active'])

// ✅ Correct
->where('status=?')
->assign(['status' => 'active'])
```

#### Issue: JSON operator not working

**Cause**: Column is not JSON type

**Solution**:
```sql
-- Make sure column is JSON type
ALTER TABLE products MODIFY COLUMN tags JSON;
```

---

## Performance Tips

1. **Use INNER JOIN when possible** - Faster than LEFT JOIN when you need matching records
2. **Add indexes on join columns** - Significantly improves join performance
3. **Limit subquery results** - Use LIMIT in subqueries to reduce data
4. **Use WHERE before HAVING** - Filter before aggregation when possible
5. **Index JSON paths** - Use virtual columns for frequently queried JSON paths

```sql
-- Create virtual column for JSON path
ALTER TABLE products 
ADD COLUMN price_value DECIMAL(10,2) 
AS (JSON_EXTRACT(data, '$.price')) VIRTUAL,
ADD INDEX idx_price (price_value);
```

---

## See Also

- [Database.Statement.md](usage/Razy.Database.Statement.md) - Query builder documentation
- [Database.md](usage/Razy.Database.md) - Database connection management
- [PLUGIN-SYSTEM.md](PLUGIN-SYSTEM.md) - Statement builder plugins

---

**Version**: 0.5.4  
**Last Updated**: February 8, 2026  
**Related**: Statement, Query, Database

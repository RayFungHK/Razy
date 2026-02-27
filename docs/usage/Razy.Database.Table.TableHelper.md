# Razy\Database\Table\TableHelper

## Summary

The `Alter` class provides a fluent interface for generating `ALTER TABLE` SQL statements. It supports comprehensive table modifications including column operations, index management, and foreign key constraints.

## Location

`src/library/Razy/Database/Table/TableHelper.php`

## Construction

```php
use Razy\Database\Table;
use Razy\Database\Table\TableHelper;

// Method 1: Direct instantiation
$table = new Table('users');
$alter = new TableHelper($table);

// Method 2: From Table instance (recommended)
$alter = $table->createHelper();
```

---

## Table Operations

### rename(string $newName): static
Rename the table.
```php
$helper->rename('customers');
// ALTER TABLE `users` RENAME TO `customers`;
```

### charset(string $charset): static
Change the table character set.
```php
$helper->charset('utf8mb4');
```

### collation(string $collation): static
Change the table collation.
```php
$helper->collation('utf8mb4_unicode_ci');
```

### engine(string $engine): static
Change the storage engine.
```php
$helper->engine('InnoDB');
```

### comment(string $comment): static
Set or change the table comment.
```php
$helper->comment('User accounts table');
```

---

## Column Operations

### addColumn(string $columnSyntax, string $position = ''): Column
Add a new column. Returns a `Column` instance for further configuration.

```php
// Basic add
$helper->addColumn('email=type(text),nullable');

// Add as first column
$helper->addColumn('id=type(auto)', 'FIRST');

// Add after specific column
$helper->addColumn('phone=type(text)', 'AFTER email');
```

### modifyColumn(string $columnName, string $newSyntax = '', string $position = ''): Column
Modify an existing column's definition.

```php
$helper->modifyColumn('username', 'type(text),length(100)');
$helper->modifyColumn('email', 'type(text),length(320)', 'AFTER username');
```

### renameColumn(string $oldName, string $newName, string $newSyntax = ''): Column
Rename a column (with optional type change).

```php
$helper->renameColumn('name', 'full_name');
$helper->renameColumn('old_col', 'new_col', 'type(int),length(11)');
```

### dropColumn(string $columnName): static
Remove a column from the table.

```php
$helper->dropColumn('deprecated_field');

// Chain multiple drops
$helper->dropColumn('field1')->dropColumn('field2');
```

---

## Index Operations

### addIndex(string $type, array|string $columns, string $indexName = ''): static
Add an index to the table.

**Types:** `INDEX`, `KEY`, `UNIQUE`, `FULLTEXT`, `SPATIAL`, `PRIMARY`

```php
// Single column index
$helper->addIndex('INDEX', 'email', 'idx_email');

// Composite index
$helper->addIndex('INDEX', ['first_name', 'last_name'], 'idx_name');
```

### addPrimaryKey(array|string $columns): static
Add a primary key.

```php
$helper->addPrimaryKey('id');
$helper->addPrimaryKey(['user_id', 'role_id']); // Composite PK
```

### addUniqueIndex(array|string $columns, string $indexName = ''): static
Add a unique index.

```php
$helper->addUniqueIndex('email', 'uniq_email');
$helper->addUniqueIndex(['username', 'domain']);
```

### addFulltextIndex(array|string $columns, string $indexName = ''): static
Add a fulltext index (for text searching).

```php
$helper->addFulltextIndex('content', 'ft_content');
$helper->addFulltextIndex(['title', 'body'], 'ft_article');
```

### dropIndex(string $indexName): static
Remove an index.

```php
$helper->dropIndex('idx_old');
```

### dropPrimaryKey(): static
Remove the primary key.

```php
$helper->dropPrimaryKey();
```

---

## Foreign Key Operations

### addForeignKey(string $column, string $referenceTable, string $referenceColumn = '', string $onDelete = 'RESTRICT', string $onUpdate = 'RESTRICT', string $constraintName = ''): static

Add a foreign key constraint.

**Actions:** `CASCADE`, `SET NULL`, `RESTRICT`, `NO ACTION`, `SET DEFAULT`

```php
// Basic foreign key
$helper->addForeignKey('user_id', 'users', 'id');

// With cascade delete
$helper->addForeignKey('user_id', 'users', 'id', 'CASCADE');

// Full options
$helper->addForeignKey(
    'category_id',
    'categories',
    'id',
    'SET NULL',
    'CASCADE',
    'fk_posts_category'
);
```

### dropForeignKey(string $constraintName): static
Remove a foreign key constraint.

```php
$helper->dropForeignKey('fk_posts_user');
```

---

## Utility Methods

### hasPendingChanges(): bool
Check if there are any queued alterations.

```php
if ($helper->hasPendingChanges()) {
    echo $helper->getSyntax();
}
```

### reset(): static
Clear all pending alterations.

```php
$helper->reset();
```

### getTable(): Table
Get the associated table instance.

```php
$table = $helper->getTable();
```

---

## Generating SQL

### getSyntax(): string
Generate a single `ALTER TABLE` statement with all alterations.

```php
$sql = $helper->getSyntax();
// ALTER TABLE `users` ADD COLUMN `email` VARCHAR(255), DROP COLUMN `old_col`;
```

### getSyntaxArray(): array
Generate separate statements for each alteration (useful for databases that don't support multiple alterations in one statement).

```php
$statements = $helper->getSyntaxArray();
// ['ALTER TABLE `users` ADD COLUMN...;', 'ALTER TABLE `users` DROP COLUMN...;']
```

### __toString(): string
The class can be cast to string to get the SQL.

```php
echo $alter; // Same as $helper->getSyntax()
```

---

## Complete Example

```php
use Razy\Database\Table;

$table = new Table('posts');
$alter = $table->createHelper();

// Multiple alterations in one chain
$helper->addColumn('slug=type(text),length(255)', 'AFTER title')
      ->addColumn('published_at=type(datetime),nullable')
      ->modifyColumn('content', 'type(long_text)')
      ->dropColumn('deprecated_flag')
      ->addUniqueIndex('slug', 'uniq_slug')
      ->addIndex('INDEX', 'published_at', 'idx_published')
      ->addForeignKey('author_id', 'users', 'id', 'CASCADE', 'CASCADE')
      ->engine('InnoDB')
      ->charset('utf8mb4')
      ->collation('utf8mb4_unicode_ci');

// Execute
$sql = $helper->getSyntax();
$db->exec($sql);
```

---

## Order of Operations

When generating SQL, alterations are applied in this order:

1. Rename table
2. Drop foreign keys (before dropping referenced columns)
3. Drop indexes
4. Drop columns
5. Add columns
6. Modify/rename columns
7. Add indexes
8. Add foreign keys
9. Table options (engine, charset, collation, comment)

---

## See Also

- [Razy.Database.Table.ColumnAlter.md](Razy.Database.Table.ColumnAlter.md) - Column-specific alterations
- [Razy.Database.Table.md](Razy.Database.Table.md) - Table creation
- [Razy.Database.Column.md](Razy.Database.Column.md) - Column definitions

# Razy\Database\Table\ColumnHelper

## Summary

The `ColumnAlter` class provides a fluent interface for modifying individual column definitions. It generates `ALTER TABLE ... MODIFY COLUMN` or `ALTER TABLE ... CHANGE COLUMN` statements with comprehensive type and property support.

## Location

`src/library/Razy/Database/Table/ColumnHelper.php`

## Construction

```php
use Razy\Database\Table;
use Razy\Database\Table\ColumnHelper;

// Method 1: Direct instantiation
$table = new Table('users');
$columnAlter = new ColumnHelper($table, 'username');

// Method 2: From Table instance (recommended)
$columnAlter = $table->columnHelper('username');
```

---

## Type Methods

### Basic Types

```php
// String types
$column->varchar(255);      // VARCHAR(255)
$column->text();            // TEXT
$column->mediumtext();      // MEDIUMTEXT
$column->longtext();        // LONGTEXT

// Integer types
$column->int(11);           // INT(11)
$column->tinyint(1);        // TINYINT(1) - for boolean
$column->bigint(20);        // BIGINT(20)

// Decimal types
$column->decimal(10, 2);    // DECIMAL(10,2)
$column->float(10, 2);      // FLOAT(10,2)

// Date/Time types
$column->datetime();        // DATETIME
$column->timestamp();       // TIMESTAMP
$column->date();            // DATE
$column->time();            // TIME

// Other types
$column->json();            // JSON
$column->blob();            // BLOB
$column->enum(['a', 'b']);  // ENUM('a','b')
```

### type(string $type): static
Set any column type directly.

```php
$column->type('MEDIUMINT');
$column->type('BINARY');
```

### length(int $length, int $decimalPoints = 0): static
Set the column length/precision.

```php
$column->type('VARCHAR')->length(100);
$column->type('DECIMAL')->length(12, 4);
```

---

## Property Methods

### Nullability

```php
$column->nullable();      // NULL
$column->nullable(true);  // NULL
$column->nullable(false); // NOT NULL
$column->notNull();       // NOT NULL
```

### Default Values

```php
$column->default('value');           // DEFAULT 'value'
$column->default(null);              // DEFAULT NULL
$column->default(0);                 // DEFAULT '0'
$column->defaultCurrentTimestamp();  // DEFAULT CURRENT_TIMESTAMP
$column->dropDefault();              // Removes default
```

### Character Set & Collation

```php
$column->charset('utf8mb4');
$column->collation('utf8mb4_unicode_ci');
```

### Comment

```php
$column->comment('User email address');
```

### Auto Increment

```php
$column->autoIncrement();
$column->autoIncrement(true);
$column->autoIncrement(false);
```

### Zerofill

```php
$column->zerofill();
$column->zerofill(true);
```

---

## Position Methods

### first(): static
Move the column to the first position.

```php
$column->first();
// ... FIRST
```

### after(string $columnName): static
Move the column after another column.

```php
$column->after('id');
// ... AFTER `id`
```

---

## Rename Method

### rename(string $newName): static
Rename the column. This changes the generated statement from `MODIFY COLUMN` to `CHANGE COLUMN`.

```php
$column->rename('new_column_name');
// CHANGE COLUMN `old_name` `new_column_name` ...
```

---

## Generating SQL

### getSyntax(): string
Generate the complete ALTER TABLE statement.

```php
$sql = $column->getSyntax();
// ALTER TABLE `users` MODIFY COLUMN `username` VARCHAR(100) NOT NULL;
```

### __toString(): string
The class can be cast to string.

```php
echo $column;
```

---

## Accessor Methods

### getTable(): Table
Get the associated table.

```php
$table = $column->getTable();
```

### getColumnName(): string
Get the original column name.

```php
$name = $column->getColumnName();
```

### getNewName(): ?string
Get the new column name (if renamed).

```php
$newName = $column->getNewName();
```

---

## Complete Examples

### Basic Modification

```php
$table = new Table('users');

// Change varchar length
$sql = $table->columnHelper('username')
    ->varchar(100)
    ->notNull()
    ->getSyntax();
// ALTER TABLE `users` MODIFY COLUMN `username` VARCHAR(100) NOT NULL;
```

### Rename with Type Change

```php
$sql = $table->columnHelper('name')
    ->rename('full_name')
    ->varchar(200)
    ->nullable()
    ->getSyntax();
// ALTER TABLE `users` CHANGE COLUMN `name` `full_name` VARCHAR(200) NULL;
```

### Complex Modification

```php
$sql = $table->columnHelper('email')
    ->varchar(320)
    ->notNull()
    ->default('')
    ->charset('utf8mb4')
    ->collation('utf8mb4_unicode_ci')
    ->comment('Primary email address')
    ->after('username')
    ->getSyntax();

// ALTER TABLE `users` MODIFY COLUMN `email` VARCHAR(320) 
//   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci 
//   NOT NULL DEFAULT '' COMMENT 'Primary email address' 
//   AFTER `username`;
```

### Timestamp with Default

```php
$sql = $table->columnHelper('created_at')
    ->timestamp()
    ->nullable()
    ->defaultCurrentTimestamp()
    ->getSyntax();
// ALTER TABLE `users` MODIFY COLUMN `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;
```

### Enum Type

```php
$sql = $table->columnHelper('status')
    ->enum(['active', 'inactive', 'pending', 'suspended'])
    ->notNull()
    ->default('pending')
    ->getSyntax();
// ALTER TABLE `users` MODIFY COLUMN `status` ENUM('active','inactive','pending','suspended') 
//   NOT NULL DEFAULT 'pending';
```

### Auto Increment ID

```php
$sql = $table->columnHelper('id')
    ->int(11)
    ->notNull()
    ->autoIncrement()
    ->first()
    ->getSyntax();
// ALTER TABLE `users` MODIFY COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT FIRST;
```

---

## Chaining with Table Alter

`ColumnAlter` is designed for single-column modifications. For multiple column changes, use the `Alter` class:

```php
$alter = $table->createAlter();
$alter->modifyColumn('username', 'type(text),length(100)');
$alter->modifyColumn('email', 'type(text),length(320)');
echo $alter->getSyntax();
```

---

## See Also

- [Razy.Database.Table.Alter.md](Razy.Database.Table.Alter.md) - Multiple table alterations
- [Razy.Database.Table.md](Razy.Database.Table.md) - Table creation
- [Razy.Database.Column.md](Razy.Database.Column.md) - Column definitions

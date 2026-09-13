# 04 — Database

Connections, the `Statement` builder, Simple Syntax, transactions, migrations, and the ORM —
plus the injection discipline this framework **requires** because its executor inlines
quoted literals instead of bound parameters.

Verified sources: `src/library/Razy/Database.php`, `Database/Statement.php`,
`Database/WhereSyntax.php`, `Database/Transaction.php`, `Database/MigrationManager.php`,
`ORM/Model.php`, `ORM/ModelQuery.php`, plus the working handlers under
`demo_modules/data/database_demo/default/controller/demo/`.

---

## 1. Getting a connection

Razy does **not** auto-connect. You create/obtain a named `Database` and connect explicitly.

```php
use Razy\Database;

$db = new Database('main');                     // named instance (Database.php:103)
$db->connect('localhost', 'user', 'secret', 'razy_demo');   // (Database.php:220)
```

or with driver choice (MySQL / pgsql / sqlite / registered custom,
`Database.php:238` + `createDriver()` `:169` + `registerDriver()` `:149`):

```php
$db = new Database('main');
$db->connectWithDriver('mysql', [
    'host' => '127.0.0.1', 'port' => 3306, 'database' => 'app',
    'username' => 'app', 'password' => $secret, 'charset' => 'UTF8',
]);   // config keys verified against Database/Driver/MySQL.php::connect defaults
```

The demo pattern uses the named-instance registry
(`demo_modules/data/database_demo/default/controller/demo/connect.php:35-44`):

```php
$db = Database::getInstance('main');   // ⚠ @deprecated (Database.php:115-121)
```

`getInstance()` still works (demo controllers override their own `getDB()` wrapper around
it — `database_demo/default/controller/database_demo.php:88-95`) but is marked
**@deprecated**: *"Use the DI Container instead: `$container->make(Database::class,
['name' => $name])`"* (`Database.php:115`). Controller-level sugar shown in the framework's
own docblock: `$db = $this->resolve(Database::class);` (`Controller.php:648`). Prefer
`new Database()` / DI; treat the static registry as legacy.

Per-module configuration belongs in your module config — read it via **ArrayAccess**:
`$this->getModuleConfig()['key'] ?? $default` (`Controller.php:292`; the entity is a
`Configuration extends Collection extends ArrayObject` — `Configuration.php:26`,
`Collection.php:31`. **There is no `->get()` method** — the `->get(...)` this line used
to advertise was a phantom, corrected 2026-09 when razymod/permissions was built;
dossier ledger P8). Read credentials there, never hardcode.

Extras: `setPrefix('rzy_')` (`:495`, applied automatically by the builder), `setTimezone()`
(`:286`), debug helpers `getLastQueried()`/`getQueried()`/`getTotalQueryCount()`
(`:460/:510/:531`). The MySQL driver enables **persistent PDO by default**
(`ATTR_PERSISTENT => true` in `Driver/MySQL.php::getConnectionOptions()`) — relevant under
worker mode ([03 §5](03-routing-and-requests.md)).

## 2. The Statement builder

`$db->prepare()` returns a `Statement` (`Database.php:559`). Fluent methods (verified
signatures in `Database/Statement.php`):

| Method | Line | Notes |
|---|---|---|
| `select(string $columns)` | `:780` | comma string: `'*'`, `'id, username'`, `'COUNT(*) AS total'` |
| `from(string\|callable)` | `:230` | `'table'` or aliased `'u.users'` (alias.table); callable = nested subquery |
| `where(string\|callable)` | `:933` | Simple Syntax (§3) or nested callable |
| `order(string)` | `:722` | `'>created_at'` DESC, `'<created_at'` ASC (demo usage `select.php:57`) |
| `limit(int $position, int $fetchLength = 0)` | `:704` | **`fetchLength=0` ⇒ `position` is the total row limit** (`:695-700`): `limit(10)` = take 10; `limit(20, 10)` = skip 20, take 10 |
| `group(string)` | `:531` | GROUP BY |
| `assign(array)` | `:212` | **values enter ONLY here** (§6) |
| `insert(string $table, array $columns, array $duplicateKeys = [])` | `:555` | columns are declared names; `$duplicateKeys` ⇒ `ON DUPLICATE KEY UPDATE` |
| `update(string $table, array $updateSyntax)` | `:871` | column ⇒ expression map |
| `delete(string $table, array $params = [], string $whereSyntax = '')` | `:822` | |
| `query(array $params = []): Query` | `:625` | execute |
| `lazy(array $params = []): mixed` | `:610` | execute + fetch first row |
| `lazyKeyValuePair($kCol, $vCol, array $p = [])` | `:689` | two-column map fetch |
| `getSyntax(): string` | `:312` | compiled SQL — the demos' favourite debugging tool |

Execution result `Razy\Database\Query`: `fetch(array $mapping = [])` (`Query.php:61`),
`fetchAll(FetchMode|string)` (`:85`), `affected()` (`:47`).

Working insert flow (`demo/.../insert.php:37-49` — values via `assign`, columns declared):

```php
$stmt = $db->prepare()
    ->insert('users', ['username', 'email', 'active', 'created_at'])
    ->assign([
        'username'   => 'john_doe',
        'email'      => 'john@example.com',
        'active'     => 1,
        'created_at' => '{{NOW()}}',   // {{expr}} emits a raw SQL expression (insert.php:80)
    ]);
$stmt->query();
$lastId = $db->lastID();               // Database.php:583
```

Upsert (`insert.php:89-95`): third arg lists the duplicate-key update columns:

```php
$db->prepare()->insert('user_stats', ['user_id', 'login_count', 'last_login'],
        ['login_count', 'last_login'])          // ON DUPLICATE KEY UPDATE these
    ->assign(['user_id' => 1, 'login_count' => 1, 'last_login' => $now])
    ->query();
```

`Database` also has shortcuts `$db->insert()/update()/delete()` returning Statements
(`:547/:598/:612`).

## 3. Simple Syntax primer

One string compiles to WHERE/JOIN SQL. Operator table, verbatim from the parser docblock
(`Database/WhereSyntax.php:22-24`):

| Family | Operators |
|---|---|
| Comparison | `=`, `!=`, `>`, `<`, `>=`, `<=` |
| Pattern | `*=` (contains), `^=` (starts), `$=` (ends), `#=` |
| JSON | `~=` (path has value), `&=`, `@=`, `:=`, `\|=` |
| Range | `><` (between), `<>` (not between) |
| Logical | `,` = AND, `\|` = OR, grouping via `( )`, `!` prefix negation (`WhereSyntax.php:41-44, 86-93`) |

Column references are validated against strict regexes (`REGEX_COLUMN`
`WhereSyntax.php:35`; backticked or `\w` identifiers).

JOIN syntax (readme + parser): `from` with relation syntax
`'u.user-g.group[group_id]'` reads *`u` (users) — each `g` (groups) via `group_id`*.
JSON column path: `settings->>'$.theme'=:theme` style within where syntax
(JSON path handling in `REGEX_COLUMN` `:35`).

```php
$db->prepare()
   ->select('u.username, g.name')
   ->from('u.users-g.group[group_id]')
   ->where('u.active=1, g.name!="spam"')
   ->assign([])
   ->query()
   ->fetchAll();
```
*illustrative* assembly of verified syntax fragments (`from` alias table
`demo/joins.php`, where operators `WhereSyntax.php:22-24`).

## 4. Transactions

Verified on `Database.php`: `beginTransaction()` `:680`, `commit()` `:693`,
`rollback()` `:706`, `inTransaction()` `:717`, `getTransactionLevel()` `:759`, and the
callback wrapper:

```php
$user = $db->transaction(function (Database $db) {     // Database.php:736
    $id = $db->prepare()->insert('users', ['name'])->assign(['name' => 'a'])->query()
             && $db->lastID();
    $db->prepare()->insert('profiles', ['user_id'])->assign(['user_id' => $id])->query();
    return $id;
});   // commit on return, rollback on throw
```

Nested transactions use **SAVEPOINTs** at the current level
(`Database/Transaction.php:72` savepoint, `:101` release, `:128` rollback-to — verified),
so level-2 rollbacks are partial.

## 5. Migrations

One API: `$this->getMigrationManager($db)` binds the module's `migration/` folder
(`Controller.php:670-683`; throws if the folder is missing).

**Migration class shape** (abstract base: `up(SchemaBuilder): void` `Migration.php:59`,
`down(SchemaBuilder): void` `:66`, optional `getDescription(): string` `:76`; anonymous-class
form verified in `tests/MigrationTest.php:257-262`):

```php
// migration/2026_02_24_100000_CreateUsersTable.php  (naming: YYYY_MM_DD_HHMMSS_Description.php)
<?php
use Razy\Database\Migration;
use Razy\Database\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $schema->create('users', function ($table): void {       // receives Razy\Database\Table
            $table->addColumn('id=type(auto)');                  // auto type ⇒ driver-specific
                                                                  //   auto-increment PK
                                                                  //   (SQLite.php:146, PostgreSQL.php:183)
            $table->addColumn('name=type(text)');
            $table->addColumn('email=type(text),nullable');
        });
    }
    public function down(SchemaBuilder $schema): void
    {
        $schema->dropIfExists('users');
    }
    public function getDescription(): string
    {
        return 'Create the users table';
    }
};
```

Column definitions use a **simple-syntax string** — verified working usage:
`'id=type(int),auto'` (`tests/MigrationTest.php:196`), `'email=type(text),nullable'`,
`'id=type(auto)'`, `'…', 'AFTER username'` / `'FIRST'` positioning
(`tests/TableHelperTest.php:96-116`); the grammar is parsed by `Database/Column.php`
(:54-59 validator; full flag set lives there). Table helpers: `addColumn()`
(`Table.php:162`), `groupIndexing()` (`:656`); `SchemaBuilder::create()` passes a `Table`
(`SchemaBuilder.php:64-72`), `table()` passes a `TableHelper` for ALTERs (`:76-88`),
`dropIfExists()` (`:110`).

✅ **Doc drift resolved (v1.0.3-beta):** the fake fluent example
(`$table->integer('id')->primary()->autoIncrement()` — methods that never
existed) has been removed from both `Controller.php`'s migration docblock
(replaced with the real `addColumn('name=type(...),flags')` grammar, verified
against `tests/MigrationTest.php:193-196`) and `Database/Migration.php` (which
now shows a valid `raw()` example). If you still meet the fluent form anywhere,
it is wrong — use the grammar above. (Was item 7 in the drift list.)

```php
$manager = $this->getMigrationManager($db);
$applied    = $manager->migrate();          // MigrationManager.php:232
$status     = $manager->getStatus();        // :388
$rolledBack = $manager->rollback();         // :271 (steps param)
$pending    = $manager->getPending();       // :213
$manager->ensureTrackingTable();            // :111 — applied-migrations bookkeeping
```

(`Database/Migration.php:52` abstract class; `SchemaBuilder.php:43`.)

## 6. ORM

Entry point: `$this->loadModel('User')` — loads `model/User.php` from your module, which
must **return an instance of an anonymous class extending `Razy\ORM\Model`**, and gives you
its FQCN (`Controller.php:574-601`, cached per name `:576-578`).

```php
// model/User.php  (shape from the Controller.php:549-558 docblock)
<?php
use Razy\ORM\Model;

return new class extends Model {
    protected static string $table    = 'users';
    protected static array  $fillable = ['name', 'email'];
};
```

```php
// in a handler:
$User  = $this->loadModel('User');                      // Controller.php:574
$user  = $User::find($db, 1);                           // static, Model.php:460
$all   = $User::all($db);                               // :487 → ModelCollection
$fresh = $User::create($db, ['name' => 'x', 'email' => 'e@x']);   // :497
$active = $User::query($db)                             // :306 → ModelQuery
    ->where('active=:a', ['a' => 1])                   // ModelQuery.php:195 — named params!
    ->orderBy('created_at', 'DESC')                    // :316
    ->limit(20)->offset(0)                             // :329/:341
    ->with('profile')                                  // :411 — eager load
    ->get();                                           // :425 → ModelCollection
$page  = $User::query($db)->paginate(1, 15);           // :512 → Paginator
```

ModelQuery also verified: `whereIn/whereNotIn` (`:242/:259`), `whereBetween` (`:281`),
`select` (`:353`), `chunk` (`:578`), `cursor` (`:618`), `count` (`:488`), `first` (`:450`),
`findOrFail` (`:473`), `create` (`:639`), `bulkUpdate` (`:665`), `bulkDelete` (`:683`),
global scopes (`Model.php:336` `addGlobalScope`; query-side `withoutGlobalScope` `:371`),
model events `creating/created/updating/updated/saving/saved/deleting/deleted`
(`Model.php:376-435`), local `scopeX()` via `__call` (`ModelQuery.php:123,133`).

The ORM is the safe path: **all values go through named parameters** (`where('x=:x',
['x'=>…])`). Prefer it when the builder's inline-quoting (below) tempts you to interpolate.

### 6a. Contract + Visibility packs (v1.0.3-beta+)

`Razy\ORM\Contract` declares a table's skeleton ONCE (fields in the same Column
simple-syntax the Migrations use, relations incl. `hasManyThrough`, JSON
sub-schemas as addresses):

```php
use Razy\ORM\Contract;
use Razy\ORM\ContractCompiler;

$contract = Contract::define([
    'table' => 'users', 'primary_key' => 'id', 'timestamps' => true,
    'fields' => [
        'id'      => 'id=type(int),auto',
        'email'   => 'email=type(varchar,255),nullable',
        'team_id' => 'team_id=type(int),nullable,reference(teams,id)', // real FK DDL
        'profile' => ['column' => 'profile=type(text),nullable', 'json' => ['city' => 'type(varchar)']],
    ],
    'relations' => [['kind' => 'hasMany', 'target' => 'posts', 'as' => 'posts', 'fk' => 'user_id']],
]);
$compiler = new ContractCompiler();
echo $compiler->createTableSql($contract);          // CREATE TABLE incl. FK, no DB needed
$drift = $compiler->drift($contract, $savedSnapshot); // added/removed/changed REPORT only
```

Scope honesty: create-generate + drift **reporting** — no diff-migrations against a
live DB (driver introspection does not exist), and the grammar normalizes positional
lengths (`varchar,320` vs `,255` export identically — see `OrmContractTest`).

A **pack** is a named serialisation view — "which attributes are visible to whom":

```php
class User extends Model {
    protected static array $packs = [
        'public'  => ['id', 'name', 'profile.city'],   // JSON pruned to declared paths
        'contact' => ['id', 'name', 'email', 'profile.city', 'profile.phone'],
    ];
}
$users = $User::query($db)->pack('public')->get();  // typo'd name throws IMMEDIATELY
$users->first()->toArray();  // ['id'=>…,'name'=>…,'profile'=>['city'=>…]] — nothing else
```

Packs override `$visible`/`$hidden` while active, propagate through
`get/first/find/paginate`, and shape ONLY the queried model — eager-loaded
relations keep their own shape. They are an **output** gate: `getRawAttribute()`
still sees everything in memory (intentional; the Executor inlines values at SQL
level, so attribute-hiding was never an injection control — see §7).

## 7. Injection discipline (RZ-003) — read this once, save many apps

The executor builds a final SQL string and `prepare()`s it **without a bound parameter
array** — assigned values are inlined as PDO-quoted literals
(`Database.php:306-327` `execute()`; verified behaviour, documented honestly in
`RAZY-ANALYSIS-REPORT.md` §7.2). Security therefore rests on *every path through the
builder quoting correctly*. Your obligations:

1. **Values only via `assign()`** (or ORM named params). Never concatenate a `$_GET` into a
   syntax string.
2. **Identifiers are your input**: table/column names come from **whitelists you own**.
   The parser validates identifier shapes (strict regexes; `Statement.php` table/column
   validation), but shape-valid ≠ authorized — map user input to a fixed set.
3. **Never use `getSearchTextSyntax()` with user text.** It embeds raw search text; a value
   containing a quote breaks the generated pattern (`Statement.php:122` +
   `RAZY-ANALYSIS-REPORT.md` §7.2 — injection landmine verified statically). Pass search
   terms as `assign()`/named params with a `LIKE`-style where instead, or build `where` with
   quoted parameter values.
4. Raw escape hatch: `getDB()->prepare('SELECT … WHERE id=:id')` (SQL-passthrough overload,
   `Database.php:559`) exists — but with Razy you must bind/assign properly; lint flags it
   (RZ-003). Do not interpolate.
5. `{{expr}}` in assigned values emits raw SQL (`demo/.../insert.php:80`) — only ever feed
   it literals **you** wrote.
6. In `where()`, plain literals (unquoted operators against bare words) are treated as
   expressions/params per Simple Syntax; when in doubt use `assign()` + named params.
7. If an app-level query is too complex for Simple Syntax, write raw SQL as a constant +
   `assign()` every dynamic value — and add a test (RZ-014).

Quick self-checks (from the rules pack):

```bash
grep -rEn 'getDB\(\)\s*->\s*prepare\(\s*.(SELECT|INSERT|UPDATE|DELETE)' modules/   # RZ-003
grep -rEn 'getSearchTextSyntax' modules/                                            # never user text
grep -rEn 'new PDO\(' modules/                                                      # use Database
```

Next: [05-templates.md](05-templates.md).

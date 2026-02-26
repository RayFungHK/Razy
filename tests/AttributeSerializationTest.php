<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\ORM\Model;
use Razy\ORM\ModelCollection;
use Razy\ORM\ModelQuery;

/**
 * Tests for P20: Accessors/Mutators, Hidden/Visible, toJson.
 *
 * Covers attribute transformation via get/set accessors, serialisation
 * filtering via $hidden/$visible, and JSON output on Model and
 * ModelCollection.
 */
#[CoversClass(ModelQuery::class)]
#[CoversClass(Model::class)]
#[CoversClass(ModelCollection::class)]
class AttributeSerializationTest extends TestCase
{
    protected function tearDown(): void
    {
        Model::clearBootedModels();
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 1: Accessors (getXxxAttribute)
    // ═══════════════════════════════════════════════════════════════

    public function testAccessorTransformsValueOnGet(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $user = AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'ray@test.com', 'password' => 'secret']);

        // getFirstNameAttribute uppercases the first letter
        $this->assertSame('Ray', $user->first_name);
    }

    public function testAccessorWorksWithNullValue(): void
    {
        $user = new AS_User();

        // Accessor receives null for a missing attribute
        $this->assertSame('', $user->first_name);
    }

    public function testAccessorComputedProperty(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $user = AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'ray@test.com', 'password' => 'secret']);

        // full_name is a virtual accessor — no underlying attribute
        $this->assertSame('Ray Fung', $user->full_name);
    }

    public function testAccessorAppliedInToArray(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $user = AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'ray@test.com', 'password' => 'secret']);

        $arr = $user->toArray();
        $this->assertSame('Ray', $arr['first_name']);
        $this->assertSame('FUNG', $arr['last_name']);
    }

    public function testAccessorDoesNotAffectRawAttribute(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $user = AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'ray@test.com', 'password' => 'secret']);

        // Raw attribute is still lowercase
        $this->assertSame('ray', $user->getRawAttribute('first_name'));
        // But __get returns transformed
        $this->assertSame('Ray', $user->first_name);
    }

    public function testAccessorPriorityOverCast(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // AS_CastAccessor has both a cast and an accessor on 'score'
        $item = AS_CastAccessor::create($db, ['score' => '42']);

        // Accessor should win over cast
        $this->assertSame('Score: 42', $item->score);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: Mutators (setXxxAttribute)
    // ═══════════════════════════════════════════════════════════════

    public function testMutatorTransformsValueOnSet(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $user = AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'RAY@TEST.COM', 'password' => 'secret']);

        // setEmailAttribute lowercases email
        $this->assertSame('ray@test.com', $user->getRawAttribute('email'));
    }

    public function testMutatorOnPropertySet(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $user = AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'ray@test.com', 'password' => 'secret']);

        $user->email = 'NEW@EMAIL.COM';

        $this->assertSame('new@email.com', $user->getRawAttribute('email'));
    }

    public function testMutatorCanSetMultipleAttributes(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // setPasswordAttribute hashes the password and sets password_hash
        $user = AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'ray@test.com', 'password' => 'mypassword']);

        // The mutator stores a hashed version
        $raw = $user->getRawAttribute('password');
        $this->assertNotSame('mypassword', $raw);
        $this->assertStringStartsWith('hashed:', $raw);
    }

    public function testMutatorOnDirectAssignment(): void
    {
        $user = new AS_User();
        $user->email = 'UPPER@CASE.COM';

        $this->assertSame('upper@case.com', $user->getRawAttribute('email'));
    }

    public function testMutatorPriorityOverCast(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // AS_CastAccessor has a mutator on 'label' that prepends 'L:'
        $item = AS_CastAccessor::create($db, ['score' => '10', 'label' => 'test']);

        $this->assertSame('L:test', $item->getRawAttribute('label'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: Hidden attributes
    // ═══════════════════════════════════════════════════════════════

    public function testHiddenExcludedFromToArray(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $user = AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'ray@test.com', 'password' => 'secret']);

        $arr = $user->toArray();

        // 'password' is in $hidden
        $this->assertArrayNotHasKey('password', $arr);
        // Other attributes remain
        $this->assertArrayHasKey('first_name', $arr);
        $this->assertArrayHasKey('email', $arr);
    }

    public function testHiddenExcludedFromToJson(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $user = AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'ray@test.com', 'password' => 'secret']);

        $json = $user->toJson();
        $decoded = \json_decode($json, true);

        $this->assertArrayNotHasKey('password', $decoded);
        $this->assertArrayHasKey('first_name', $decoded);
    }

    public function testHiddenDoesNotAffectPropertyAccess(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $user = AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'ray@test.com', 'password' => 'secret']);

        // Direct property access still works, even for hidden attributes
        $this->assertNotNull($user->password);
    }

    public function testMultipleHiddenAttributes(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $item = AS_MultiHidden::create($db, ['name' => 'Test', 'secret1' => 'a', 'secret2' => 'b']);

        $arr = $item->toArray();
        $this->assertArrayNotHasKey('secret1', $arr);
        $this->assertArrayNotHasKey('secret2', $arr);
        $this->assertArrayHasKey('name', $arr);
    }

    public function testGetHiddenReturnsHiddenArray(): void
    {
        $this->assertSame(['password'], AS_User::getHidden());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: Visible attributes (whitelist)
    // ═══════════════════════════════════════════════════════════════

    public function testVisibleWhitelistsAttributes(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $item = AS_VisibleOnly::create($db, ['name' => 'Public', 'internal_code' => 'X123', 'notes' => 'secret']);

        $arr = $item->toArray();
        $this->assertArrayHasKey('name', $arr);
        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayNotHasKey('internal_code', $arr);
        $this->assertArrayNotHasKey('notes', $arr);
        $this->assertArrayNotHasKey('created_at', $arr);
    }

    public function testVisibleTakesPrecedenceOverHidden(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // AS_VisibleAndHidden has both $visible and $hidden
        $item = AS_VisibleAndHidden::create($db, ['name' => 'Test', 'code' => 'ABC', 'secret' => 'x']);

        $arr = $item->toArray();

        // $visible = ['name', 'id'] → only these appear, regardless of $hidden
        $this->assertSame(['name', 'id'], \array_keys($arr));
    }

    public function testGetVisibleReturnsVisibleArray(): void
    {
        $this->assertSame(['name', 'id'], AS_VisibleOnly::getVisible());
    }

    public function testVisibleAppliedInToJson(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $item = AS_VisibleOnly::create($db, ['name' => 'Public', 'internal_code' => 'X123', 'notes' => 'secret']);

        $decoded = \json_decode($item->toJson(), true);

        $this->assertArrayHasKey('name', $decoded);
        $this->assertArrayNotHasKey('internal_code', $decoded);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: toJson on Model
    // ═══════════════════════════════════════════════════════════════

    public function testToJsonReturnsValidJson(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $item = AS_Simple::create($db, ['name' => 'Hello', 'value' => '42']);

        $json = $item->toJson();
        $this->assertJson($json);

        $decoded = \json_decode($json, true);
        $this->assertSame('Hello', $decoded['name']);
        $this->assertSame('42', $decoded['value']);
    }

    public function testToJsonWithOptions(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $item = AS_Simple::create($db, ['name' => 'Test', 'value' => '1']);

        $json = $item->toJson(JSON_PRETTY_PRINT);
        $this->assertStringContainsString("\n", $json);
    }

    public function testToJsonIncludesAccessors(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $user = AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'ray@test.com', 'password' => 'secret']);

        $decoded = \json_decode($user->toJson(), true);

        // Accessor transforms first_name to ucfirst
        $this->assertSame('Ray', $decoded['first_name']);
        $this->assertSame('FUNG', $decoded['last_name']);
    }

    public function testToJsonRespectsHidden(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $user = AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'ray@test.com', 'password' => 'secret']);

        $decoded = \json_decode($user->toJson(), true);
        $this->assertArrayNotHasKey('password', $decoded);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: toJson on ModelCollection
    // ═══════════════════════════════════════════════════════════════

    public function testCollectionToJsonReturnsValidJson(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        AS_Simple::create($db, ['name' => 'A', 'value' => '1']);
        AS_Simple::create($db, ['name' => 'B', 'value' => '2']);

        $collection = AS_Simple::all($db);
        $json = $collection->toJson();

        $this->assertJson($json);
        $decoded = \json_decode($json, true);
        $this->assertCount(2, $decoded);
        $this->assertSame('A', $decoded[0]['name']);
        $this->assertSame('B', $decoded[1]['name']);
    }

    public function testCollectionToJsonRespectsHidden(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'ray@test.com', 'password' => 'secret']);

        $collection = AS_User::all($db);
        $decoded = \json_decode($collection->toJson(), true);

        $this->assertArrayNotHasKey('password', $decoded[0]);
    }

    public function testCollectionToJsonWithOptions(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        AS_Simple::create($db, ['name' => 'X', 'value' => '9']);

        $json = AS_Simple::all($db)->toJson(JSON_PRETTY_PRINT);
        $this->assertStringContainsString("\n", $json);
    }

    public function testCollectionToArrayRespectsAccessorsAndHidden(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        AS_User::create($db, ['first_name' => 'alice', 'last_name' => 'smith', 'email' => 'alice@test.com', 'password' => 'pw']);

        $arr = AS_User::all($db)->toArray();

        $this->assertSame('Alice', $arr[0]['first_name']);
        $this->assertSame('SMITH', $arr[0]['last_name']);
        $this->assertArrayNotHasKey('password', $arr[0]);
    }

    public function testEmptyCollectionToJson(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $json = AS_Simple::all($db)->toJson();
        $this->assertSame('[]', $json);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: Studly case conversion
    // ═══════════════════════════════════════════════════════════════

    public function testAccessorWithSnakeCaseName(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $user = AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'ray@test.com', 'password' => 'secret']);

        // first_name → getFirstNameAttribute → ucfirst
        $this->assertSame('Ray', $user->first_name);
    }

    public function testAccessorWithSingleSegmentName(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $user = AS_User::create($db, ['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'ray@test.com', 'password' => 'secret']);

        // email → getEmailAttribute (single segment, no underscores)
        // We don't have an email accessor, so it should just return the raw value
        $this->assertSame('ray@test.com', $user->email);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testModelWithNoHiddenOrVisible(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $item = AS_Simple::create($db, ['name' => 'Test', 'value' => '42']);
        $arr = $item->toArray();

        // All attributes returned
        $this->assertArrayHasKey('name', $arr);
        $this->assertArrayHasKey('value', $arr);
        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayHasKey('created_at', $arr);
    }

    public function testModelWithNoAccessorsMutatorsIsUnchanged(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $item = AS_Simple::create($db, ['name' => 'Plain', 'value' => '100']);

        $this->assertSame('Plain', $item->name);
        $this->assertSame('100', $item->value);
    }

    public function testAccessorAndMutatorOnSameAttribute(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // AS_CastAccessor has both accessor and mutator on 'label'
        $item = AS_CastAccessor::create($db, ['score' => '5', 'label' => 'hello']);

        // Mutator prepends 'L:', so raw is 'L:hello'
        $this->assertSame('L:hello', $item->getRawAttribute('label'));

        // Accessor uppercases, so __get returns 'L:HELLO'
        $this->assertSame('L:HELLO', $item->label);
    }

    public function testFillUsesMutators(): void
    {
        // fill() now routes through __set, so mutators ARE applied
        $user = new AS_User();
        $user->fill(['first_name' => 'ray', 'last_name' => 'fung', 'email' => 'UPPER@TEST.COM', 'password' => 'pw']);

        // setEmailAttribute lowercases email
        $this->assertSame('upper@test.com', $user->getRawAttribute('email'));
        // setPasswordAttribute hashes password
        $this->assertStringStartsWith('hashed:', $user->getRawAttribute('password'));
    }

    public function testToArrayOnFreshModel(): void
    {
        $model = new AS_Simple();
        $model->name = 'Test';

        $arr = $model->toArray();
        $this->assertSame('Test', $arr['name']);
    }

    public function testToJsonOnFreshModel(): void
    {
        $model = new AS_Simple();
        $model->name = 'Fresh';

        $json = $model->toJson();
        $decoded = \json_decode($json, true);
        $this->assertSame('Fresh', $decoded['name']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function createDb(): Database
    {
        static $counter = 0;
        $db = new Database('as_test_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        return $db;
    }

    private function createSchema(Database $db): void
    {
        $adapter = $db->getDBAdapter();

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS as_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name TEXT,
                last_name TEXT,
                email TEXT,
                password TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS as_simples (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                value TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS as_cast_accessors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                score TEXT,
                label TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS as_multi_hiddens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                secret1 TEXT,
                secret2 TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS as_visible_onlys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                internal_code TEXT,
                notes TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS as_visible_and_hiddens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                code TEXT,
                secret TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');
    }
}

// ═══════════════════════════════════════════════════════════════
// Test Model Doubles
// ═══════════════════════════════════════════════════════════════

/**
 * User model with accessors, mutators, and hidden attributes.
 */
class AS_User extends Model
{
    protected static string $table = 'as_users';

    protected static array $fillable = ['first_name', 'last_name', 'email', 'password'];

    protected static array $hidden = ['password'];

    // Accessor: first_name → ucfirst
    public function getFirstNameAttribute(?string $value): string
    {
        return \ucfirst($value ?? '');
    }

    // Accessor: last_name → strtoupper
    public function getLastNameAttribute(?string $value): string
    {
        return \strtoupper($value ?? '');
    }

    // Computed accessor: full_name (virtual, no underlying column)
    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . \ucfirst($this->getRawAttribute('last_name') ?? '');
    }

    // Mutator: email → strtolower
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = \strtolower($value);
    }

    // Mutator: password → hash
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = 'hashed:' . $value;
    }
}

/**
 * Simple model with no accessors/mutators/hidden/visible.
 */
class AS_Simple extends Model
{
    protected static string $table = 'as_simples';

    protected static array $fillable = ['name', 'value'];
}

/**
 * Model with both a cast AND an accessor on the same attribute,
 * plus a mutator+accessor pair on 'label'.
 */
class AS_CastAccessor extends Model
{
    protected static string $table = 'as_cast_accessors';

    protected static array $fillable = ['score', 'label'];

    protected static array $casts = ['score' => 'int'];

    // Accessor should win over cast for 'score'
    public function getScoreAttribute(mixed $value): string
    {
        return 'Score: ' . $value;
    }

    // Accessor for 'label': uppercase
    public function getLabelAttribute(mixed $value): string
    {
        return \strtoupper($value ?? '');
    }

    // Mutator for 'label': prepend 'L:'
    public function setLabelAttribute(string $value): void
    {
        $this->attributes['label'] = 'L:' . $value;
    }
}

/**
 * Model with multiple hidden attributes.
 */
class AS_MultiHidden extends Model
{
    protected static string $table = 'as_multi_hiddens';

    protected static array $fillable = ['name', 'secret1', 'secret2'];

    protected static array $hidden = ['secret1', 'secret2'];
}

/**
 * Model with $visible whitelist.
 */
class AS_VisibleOnly extends Model
{
    protected static string $table = 'as_visible_onlys';

    protected static array $fillable = ['name', 'internal_code', 'notes'];

    protected static array $visible = ['name', 'id'];
}

/**
 * Model with BOTH $visible and $hidden ($visible takes precedence).
 */
class AS_VisibleAndHidden extends Model
{
    protected static string $table = 'as_visible_and_hiddens';

    protected static array $fillable = ['name', 'code', 'secret'];

    protected static array $visible = ['name', 'id'];

    protected static array $hidden = ['secret'];
}

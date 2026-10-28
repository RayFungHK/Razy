<?php

/**
 * RZ-47 — security follow-ups of RZ-41 (Razy#41 review rounds).
 *
 * Each case reproduces its defect on master: a stray or unclosed parenthesis
 * silently dropped or rewrote a clause, a where() parameter replaced the value
 * bulkUpdate() was writing, a NUL byte cut a JSON path short, a `:rzpN` inside a
 * quoted literal counted as a placeholder, and Model keys / columns were spliced
 * into Simple Syntax unchecked.
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\Database\ParameterBinder;
use Razy\Database\WhereSyntax;
use Razy\Exception\InvalidOperandException;
use Razy\Exception\QueryException;
use Razy\ORM\Model;
use Razy\SimpleSyntax;

#[CoversClass(SimpleSyntax::class)]
#[CoversClass(ParameterBinder::class)]
class SecurityFollowupsTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database('test_rz47');
        $this->db->connectWithDriver('sqlite', ['path' => ':memory:']);
        $this->db->getDBAdapter()?->exec(<<<'SQL'
            CREATE TABLE qe_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, price INTEGER, category TEXT, created_at TEXT, updated_at TEXT);
            INSERT INTO qe_items (name, price, category) VALUES ('Novel', 15, 'books'), ('Phone', 500, 'electronics');
            SQL);
    }

    protected function tearDown(): void
    {
        Model::clearBootedModels();
    }

    /**
     * @return array<string, array{0: callable(Database): mixed}>
     */
    public static function unbalancedBuilders(): array
    {
        return [
            'order, stray )' => [fn (Database $db) => $db->prepare()->select('*')->from('qe_items')->order('>id)--')->getSyntax()],
            'order, unclosed (' => [fn (Database $db) => $db->prepare()->select('*')->from('qe_items')->order('(id')->getSyntax()],
            'order, a group as key' => [fn (Database $db) => $db->prepare()->select('*')->from('qe_items')->order('(id)')->getSyntax()],
            'where, unclosed (' => [fn (Database $db) => $db->prepare()->select('*')->from('qe_items')->where('id=1,(name="a"')->getSyntax()],
            'update value, unclosed (' => [fn (Database $db) => $db->update('qe_items', ['price=(price+1'])->where('id=1')->getSyntax()],
            'join, stray )' => [fn (Database $db) => $db->prepare()->select('*')->from('a.qe_items-b.qe_items[id])')->getSyntax()],
        ];
    }

    #[DataProvider('unbalancedBuilders')]
    public function testUnbalancedParenthesisIsAQueryException(callable $build): void
    {
        // Before: order('>id)--') dropped the whole ORDER BY, '(id' sorted by id, '(id)' was a TypeError
        $this->expectException(QueryException::class);
        $build($this->db);
    }

    public function testVerifySyntaxReportsUnbalancedAsInvalid(): void
    {
        $this->assertSame('', WhereSyntax::VerifySyntax('a=1,(b=2'));
        $this->assertSame('a=1,(b=2)', WhereSyntax::VerifySyntax('a=1,(b=2)'));
    }

    public function testBulkUpdateValueIsNotReplacedByAWhereParameter(): void
    {
        // Before: SET bound as `:category`, so the where() value 'books' was written back
        $affected = RZ47_Item::query($this->db)->where('category=:category', ['category' => 'books'])->bulkUpdate(['category' => 'paperbacks']);

        $this->assertSame(1, $affected);
        $this->assertSame([['category' => 'paperbacks']], $this->db->prepare()->select('category')->from('qe_items')->where('id=1')->query()->fetchAll());
    }

    public function testJsonPathWithANulByteIsRefused(): void
    {
        $this->expectException(InvalidOperandException::class);
        $this->db->prepare()->select('*')->from('qe_items')->where("name->>'$.a\0b'=1")->getSyntax();
    }

    public function testPlaceholderInsideAQuotedLiteralIsNotBound(): void
    {
        $binder = ParameterBinder::begin();
        try {
            $real = $binder->add('x');
            $ghost = $binder->add('y');
            $sql = "SELECT * FROM t WHERE a = {$real} AND b = '{$ghost}' AND c = \"{$ghost}\"";

            $this->assertSame([\substr($real, 1) => 'x'], $binder->paramsUsedIn($sql));
        } finally {
            ParameterBinder::end($binder);
        }
    }

    public function testModelSplicedNamesMustBeIdentifiers(): void
    {
        // Before: a key or column became Simple Syntax (`name=:x|1=1` matched every row)
        $calls = [
            'firstOrCreate key' => fn () => RZ47_Item::firstOrCreate($this->db, ['name=:name|1' => 'x']),
            'increment column' => fn () => RZ47_Item::find($this->db, 1)?->increment('price=0,name'),
            'increment extra key' => fn () => RZ47_Item::find($this->db, 1)?->increment('price', 1, ['name=1' => 'y']),
        ];
        foreach ($calls as $label => $call) {
            try {
                $call();
                $this->fail('expected QueryException: ' . $label);
            } catch (QueryException) {
            }
        }
        $this->assertSame(15, RZ47_Item::find($this->db, 1)?->getRawAttribute('price') + 0, 'nothing was written');
        $this->assertTrue(RZ47_Item::find($this->db, 1)?->increment('price', 2), 'a plain column still increments');
    }
}

class RZ47_Item extends Model
{
    protected static string $table = 'qe_items';

    protected static array $fillable = ['name', 'price', 'category'];
}

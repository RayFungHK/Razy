<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Rzx\Node\PropsNode;
use Razy\Rzx\Prop;
use Razy\Rzx\PropsReader;
use Razy\Rzx\SafeHtml;
use Razy\Rzx\Source;

/**
 * RZ-28b: the props schema and the `<props>` reader (no PHP is run).
 */
#[CoversClass(Prop::class)]
#[CoversClass(PropsReader::class)]
class PropTest extends TestCase
{
    /**
     * @return iterable<string, array{Prop, mixed, string|null}>
     */
    public static function checks(): iterable
    {
        yield 'string ok' => [Prop::string(), 'a', null];
        yield 'string from int' => [Prop::string(), 1, 'p must be a string, got int'];
        yield 'string refuses SafeHtml' => [Prop::string(), new SafeHtml('<b>'), 'p must be a string, got Razy\Rzx\SafeHtml'];
        yield 'string refuses SafeUrl' => [Prop::string(), new \Razy\Rzx\SafeUrl('/x'), 'p must be a string, got Razy\Rzx\SafeUrl'];
        yield 'optional null' => [Prop::int(), null, null];
        yield 'required null' => [Prop::int()->required(), null, 'p is required'];
        yield 'float accepts int' => [Prop::float(), 3, null];
        yield 'bool strict' => [Prop::bool(), 1, 'p must be a bool, got int'];
        yield 'enum' => [Prop::enum('a', 'b'), 'c', "p must be one of 'a', 'b', got string"];
        yield 'enum strict' => [Prop::enum(1, 2), '1', 'p must be one of 1, 2, got string'];
        yield 'html refuses string' => [Prop::html(), '<b>', 'p must be a SafeHtml value, got string'];
        yield 'html ok' => [Prop::html(), new SafeHtml('<b>'), null];
        yield 'list not map' => [Prop::list(), ['a' => 1], 'p must be a list, got array'];
        yield 'list items' => [Prop::list(Prop::int()), [1, 'x'], 'p[1] must be a int, got string'];
        yield 'map values' => [Prop::map(Prop::string()), ['k' => 1], 'p[k] must be a string, got int'];
        yield 'shape field' => [Prop::shape(['id' => Prop::int()->required()]), ['x' => 1], 'p.id is required'];
        yield 'shape object' => [Prop::shape(['id' => Prop::int()]), (object) ['id' => 'x'], 'p.id must be a int, got string'];
        yield 'nested' => [Prop::list(Prop::shape(['n' => Prop::string()])), [['n' => 'a'], ['n' => 2]], 'p[1].n must be a string, got int'];
        yield 'min value' => [Prop::int()->min(5), 4, 'p must be at least 5'];
        yield 'max length' => [Prop::string()->max(3), 'äöüß', 'p must be at most 3 characters'];
        yield 'min count' => [Prop::list()->min(1), [], 'p must be at least 1 items'];
    }

    #[DataProvider('checks')]
    public function testCheck(Prop $prop, mixed $value, ?string $error): void
    {
        $this->assertSame($error, $prop->check($value, 'p'));
    }

    public function testModifiersReturnCopies(): void
    {
        $base = Prop::string();
        $required = $base->required();
        $this->assertFalse($base->isRequired());
        $this->assertTrue($required->isRequired());
        $this->assertFalse($required->optional()->isRequired());
        $this->assertSame('x', $base->default('x')->defaultValue());
        $this->assertFalse($base->hasDefault());
    }

    public function testInvalidDefaultsAndShapesThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Prop::int()->default('x');
    }

    public function testArrayRoundTrip(): void
    {
        $prop = Prop::list(Prop::shape(['id' => Prop::int()->required(), 'tone' => Prop::enum('a', 'b')->default('a')]))->min(1)->max(9);
        $copy = Prop::fromArray($prop->toArray());
        $this->assertEquals($prop, $copy);
        $this->assertSame($prop->toArray(), $copy->toArray());
    }

    public function testReaderBuildsTheSchemaWithoutRunningPhp(): void
    {
        $php = <<<'PHP'

            // a comment, # another, /* and a block */
            return [
                'title' => Prop::string()->required(),          // trailing
                'rows'  => \Razy\Rzx\Prop::list(Prop::shape(['id' => Prop::int()])),
                'size'  => Prop::enum('sm', "md", 'it\'s')->default("md"),
                'n'     => Prop::float()->min(-1.5)->max(10),
                'flags' => Prop::map()->default(['a' => true, 'b' => null]),
            ];
            PHP;
        $text = '<props>' . $php . '</props><p></p>';
        $schema = PropsReader::read(new Source($text), new PropsNode(0, $php, 7));

        $this->assertSame(['title', 'rows', 'size', 'n', 'flags'], \array_keys($schema));
        $this->assertTrue($schema['title']->isRequired());
        $this->assertSame('shape', $schema['rows']->item?->type);
        $this->assertSame(['sm', 'md', "it's"], $schema['size']->values);
        $this->assertSame('md', $schema['size']->defaultValue());
        $this->assertSame('n must be at least -1.5', $schema['n']->check(-2, 'n'));
        $this->assertSame(['a' => true, 'b' => null], $schema['flags']->defaultValue());
    }
}

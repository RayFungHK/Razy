<?php

/**
 * Unit tests for Razy\Route and Razy\Agent.
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Route;
use stdClass;

#[CoversClass(Route::class)]
class RouteTest extends TestCase
{
    // ==================== BASIC CONSTRUCTION ====================

    public function testConstructor(): void
    {
        $route = new Route('test/path');
        $this->assertInstanceOf(Route::class, $route);
    }

    public function testConstructorNormalizesPath(): void
    {
        $route = new Route('/test/path/');
        $this->assertEquals('test/path', $route->getClosurePath());
    }

    public function testConstructorWithMultipleSlashes(): void
    {
        $route = new Route('//test///path//');
        $this->assertEquals('test/path', $route->getClosurePath());
    }

    public function testConstructorEmptyPathThrowsError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The closure path cannot be empty');

        new Route('');
    }

    public function testConstructorOnlySlashesThrowsError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The closure path cannot be empty');

        new Route('///');
    }

    // ==================== GET CLOSURE PATH ====================

    public function testGetClosurePath(): void
    {
        $route = new Route('users/profile');
        $this->assertEquals('users/profile', $route->getClosurePath());
    }

    public function testGetClosurePathSimple(): void
    {
        $route = new Route('home');
        $this->assertEquals('home', $route->getClosurePath());
    }

    public function testGetClosurePathComplex(): void
    {
        $route = new Route('api/v1/users/profile/settings');
        $this->assertEquals('api/v1/users/profile/settings', $route->getClosurePath());
    }

    // ==================== DATA CONTAINER ====================

    public function testContainData(): void
    {
        $route = new Route('test');
        $data = ['key' => 'value'];

        $result = $route->contain($data);

        $this->assertInstanceOf(Route::class, $result);
        $this->assertEquals($data, $route->getData());
    }

    public function testContainDataChaining(): void
    {
        $route = new Route('test');
        $returnValue = $route->contain('test-data');

        $this->assertSame($route, $returnValue);
    }

    public function testGetDataInitiallyNull(): void
    {
        $route = new Route('test');
        $this->assertNull($route->getData());
    }

    public function testContainDataOverwrite(): void
    {
        $route = new Route('test');

        $route->contain('first');
        $this->assertEquals('first', $route->getData());

        $route->contain('second');
        $this->assertEquals('second', $route->getData());
    }

    public function testContainDataNull(): void
    {
        $route = new Route('test');
        $route->contain(null);

        $this->assertNull($route->getData());
    }

    // ==================== DATA TYPES ====================

    public function testContainArrayData(): void
    {
        $route = new Route('test');
        $data = [
            'user' => 'John',
            'id' => 123,
            'roles' => ['admin', 'user'],
        ];

        $route->contain($data);
        $this->assertEquals($data, $route->getData());
    }

    public function testContainObjectData(): void
    {
        $route = new Route('test');
        $data = new stdClass();
        $data->name = 'Test';
        $data->value = 42;

        $route->contain($data);
        $retrieved = $route->getData();

        $this->assertInstanceOf(stdClass::class, $retrieved);
        $this->assertEquals('Test', $retrieved->name);
        $this->assertEquals(42, $retrieved->value);
    }

    public function testContainStringData(): void
    {
        $route = new Route('test');
        $route->contain('simple string');

        $this->assertEquals('simple string', $route->getData());
    }

    public function testContainNumericData(): void
    {
        $route = new Route('test');

        $route->contain(123);
        $this->assertEquals(123, $route->getData());

        $route->contain(45.67);
        $this->assertEquals(45.67, $route->getData());
    }

    public function testContainBooleanData(): void
    {
        $route = new Route('test');

        $route->contain(true);
        $this->assertTrue($route->getData());

        $route->contain(false);
        $this->assertFalse($route->getData());
    }

    // ==================== PATH NORMALIZATION ====================

    public function testPathNormalizationLeadingSlash(): void
    {
        $route = new Route('/path');
        $this->assertEquals('path', $route->getClosurePath());
    }

    public function testPathNormalizationTrailingSlash(): void
    {
        $route = new Route('path/');
        $this->assertEquals('path', $route->getClosurePath());
    }

    public function testPathNormalizationBothSlashes(): void
    {
        $route = new Route('/path/');
        $this->assertEquals('path', $route->getClosurePath());
    }

    public function testPathNormalizationMultipleTrailingSlashes(): void
    {
        $route = new Route('path///');
        $this->assertEquals('path', $route->getClosurePath());
    }

    public function testPathNormalizationMultipleLeadingSlashes(): void
    {
        $route = new Route('///path');
        $this->assertEquals('path', $route->getClosurePath());
    }

    public function testPathNormalizationInternalSlashes(): void
    {
        $route = new Route('path//to///resource');
        $this->assertEquals('path/to/resource', $route->getClosurePath());
    }

    // ==================== EDGE CASES ====================

    public function testPathWithSpecialCharacters(): void
    {
        $route = new Route('api-v1/user_profile');
        $this->assertEquals('api-v1/user_profile', $route->getClosurePath());
    }

    public function testPathWithNumbers(): void
    {
        $route = new Route('api/v1/users/123');
        $this->assertEquals('api/v1/users/123', $route->getClosurePath());
    }

    public function testPathCaseSensitivity(): void
    {
        $route1 = new Route('Path/To/Resource');
        $route2 = new Route('path/to/resource');

        $this->assertNotEquals($route1->getClosurePath(), $route2->getClosurePath());
        $this->assertEquals('Path/To/Resource', $route1->getClosurePath());
        $this->assertEquals('path/to/resource', $route2->getClosurePath());
    }

    public function testSingleCharacterPath(): void
    {
        $route = new Route('a');
        $this->assertEquals('a', $route->getClosurePath());
    }

    public function testPathWithSpaces(): void
    {
        $route = new Route('  /  path  /  to  /  resource  /  ');
        // Spaces are trimmed but path segments remain
        $this->assertNotEmpty($route->getClosurePath());
    }

    // ==================== COMPLEX DATA SCENARIOS ====================

    public function testContainNestedArrayData(): void
    {
        $route = new Route('test');
        $data = [
            'user' => [
                'profile' => [
                    'name' => 'John',
                    'email' => 'john@example.com',
                ],
                'roles' => ['admin', 'editor'],
            ],
        ];

        $route->contain($data);
        $retrieved = $route->getData();

        $this->assertEquals('John', $retrieved['user']['profile']['name']);
        $this->assertEquals(['admin', 'editor'], $retrieved['user']['roles']);
    }

    public function testContainCallableData(): void
    {
        $route = new Route('test');
        $callable = fn () => 'test';

        $route->contain($callable);
        $retrieved = $route->getData();

        $this->assertIsCallable($retrieved);
        $this->assertEquals('test', $retrieved());
    }

    // ==================== IMMUTABILITY CHECKS ====================

    public function testClosurePathImmutable(): void
    {
        $route = new Route('original/path');
        $originalPath = $route->getClosurePath();

        // Try to manipulate (data doesn't affect path)
        $route->contain('new data');

        $this->assertEquals($originalPath, $route->getClosurePath());
    }

    public function testMultipleGetDataCalls(): void
    {
        $route = new Route('test');
        $data = ['key' => 'value'];
        $route->contain($data);

        $first = $route->getData();
        $second = $route->getData();

        $this->assertEquals($first, $second);
    }

    // ==================== REAL-WORLD PATTERNS ====================

    public function testRESTfulRoute(): void
    {
        $route = new Route('api/v1/users/123/posts/456');
        $this->assertEquals('api/v1/users/123/posts/456', $route->getClosurePath());
    }

    public function testNestedResourceRoute(): void
    {
        $route = new Route('admin/settings/security/two-factor');
        $this->assertEquals('admin/settings/security/two-factor', $route->getClosurePath());
    }

    public function testRouteWithMetadata(): void
    {
        $route = new Route('users/profile');
        $metadata = [
            'method' => 'GET',
            'auth' => true,
            'roles' => ['user', 'admin'],
            'cache' => 300,
        ];

        $route->contain($metadata);
        $retrieved = $route->getData();

        $this->assertEquals('GET', $retrieved['method']);
        $this->assertTrue($retrieved['auth']);
        $this->assertEquals(['user', 'admin'], $retrieved['roles']);
        $this->assertEquals(300, $retrieved['cache']);
    }
}

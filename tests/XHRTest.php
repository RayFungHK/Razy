<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\XHR;

#[CoversClass(XHR::class)]
class XHRTest extends TestCase
{
    #[Test]
    public function constructCreatesInstance(): void
    {
        $xhr = new XHR(true);
        $this->assertInstanceOf(XHR::class, $xhr);
    }

    #[Test]
    public function sendReturnsArrayWhenReturnAsArrayIsTrue(): void
    {
        $xhr = new XHR(true);
        $result = $xhr->send(true, 'OK');

        $this->assertIsArray($result);
        $this->assertTrue($result['result']);
        $this->assertSame('OK', $result['message']);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('response', $result);
    }

    #[Test]
    public function sendWithFailureResult(): void
    {
        $xhr = new XHR(true);
        $result = $xhr->send(false, 'Error occurred');

        $this->assertFalse($result['result']);
        $this->assertSame('Error occurred', $result['message']);
    }

    #[Test]
    public function sendWithoutMessage(): void
    {
        $xhr = new XHR(true);
        $result = $xhr->send(true);

        $this->assertArrayNotHasKey('message', $result);
    }

    #[Test]
    public function dataSetContentScalar(): void
    {
        $xhr = new XHR(true);
        $xhr->data('hello world');
        $result = $xhr->send();

        $this->assertSame('hello world', $result['response']);
    }

    #[Test]
    public function dataSetContentArray(): void
    {
        $xhr = new XHR(true);
        $xhr->data(['key' => 'value', 'num' => 42]);
        $result = $xhr->send();

        $this->assertIsArray($result['response']);
        $this->assertSame('value', $result['response']['key']);
        $this->assertSame(42, $result['response']['num']);
    }

    #[Test]
    public function dataSetContentNull(): void
    {
        $xhr = new XHR(true);
        $xhr->data(null);
        $result = $xhr->send();

        $this->assertNull($result['response']);
    }

    #[Test]
    public function dataSetContentInteger(): void
    {
        $xhr = new XHR(true);
        $xhr->data(123);
        $result = $xhr->send();

        $this->assertSame(123, $result['response']);
    }

    #[Test]
    public function dataSetContentBoolean(): void
    {
        $xhr = new XHR(true);
        $xhr->data(true);
        $result = $xhr->send();

        $this->assertTrue($result['response']);
    }

    #[Test]
    public function setAddsParameter(): void
    {
        $xhr = new XHR(true);
        $xhr->set('page', 1);
        $xhr->set('total', 100);
        $result = $xhr->send();

        $this->assertArrayHasKey('params', $result);
        $this->assertSame(1, $result['params']['page']);
        $this->assertSame(100, $result['params']['total']);
    }

    #[Test]
    public function setWithEmptyNameThrows(): void
    {
        $this->expectException(\Razy\Error::class);
        $xhr = new XHR(true);
        $xhr->set('', 'value');
    }

    #[Test]
    public function allowOriginWildcard(): void
    {
        $xhr = new XHR(true);
        $result = $xhr->allowOrigin('*');
        $this->assertSame($xhr, $result);
    }

    #[Test]
    public function allowOriginValidDomain(): void
    {
        $xhr = new XHR(true);
        $result = $xhr->allowOrigin('https://example.com');
        $this->assertSame($xhr, $result);
    }

    #[Test]
    public function corpSetsCrossOriginPolicy(): void
    {
        $xhr = new XHR(true);
        $result = $xhr->corp(XHR::CORP_SAME_ORIGIN);
        $this->assertSame($xhr, $result);
    }

    #[Test]
    public function corpConstants(): void
    {
        $this->assertSame('same-site', XHR::CORP_SAME_SITE);
        $this->assertSame('same-origin', XHR::CORP_SAME_ORIGIN);
        $this->assertSame('cross-origin', XHR::CORP_CROSS_ORIGIN);
    }

    #[Test]
    public function fluentInterface(): void
    {
        $xhr = new XHR(true);
        $result = $xhr->allowOrigin('*')
            ->corp(XHR::CORP_CROSS_ORIGIN)
            ->data(['test' => true])
            ->set('count', 5)
            ->send(true, 'done');

        $this->assertTrue($result['result']);
        $this->assertSame('done', $result['message']);
        $this->assertSame(true, $result['response']['test']);
        $this->assertSame(5, $result['params']['count']);
    }

    #[Test]
    public function hashIsUniquePerInstance(): void
    {
        $xhr1 = new XHR(true);
        $xhr2 = new XHR(true);

        $result1 = $xhr1->send();
        $result2 = $xhr2->send();

        $this->assertNotSame($result1['hash'], $result2['hash']);
    }

    #[Test]
    public function timestampIsRecentInteger(): void
    {
        $xhr = new XHR(true);
        $result = $xhr->send();

        $this->assertIsInt($result['timestamp']);
        $this->assertGreaterThan(0, $result['timestamp']);
    }

    #[Test]
    public function onCompleteReturnsChainable(): void
    {
        $xhr = new XHR(true);
        $result = $xhr->onComplete(function () {});
        $this->assertSame($xhr, $result);
    }

    #[Test]
    public function nestedArrayDataIsParsedRecursively(): void
    {
        $xhr = new XHR(true);
        $data = [
            'users' => [
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25],
            ],
        ];
        $xhr->data($data);
        $result = $xhr->send();

        $this->assertSame('Alice', $result['response']['users'][0]['name']);
        $this->assertSame(25, $result['response']['users'][1]['age']);
    }

    #[Test]
    public function paramsNotPresentWhenNoParametersSet(): void
    {
        $xhr = new XHR(true);
        $result = $xhr->send();
        $this->assertArrayNotHasKey('params', $result);
    }
}

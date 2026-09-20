<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Mcp\Server;
use Razy\Mcp\ToolProviderInterface;
use Razy\Mcp\UnknownToolException;

/**
 * MCP protocol-core pins (RZ-014): the 2025-06-18 stdio message shape —
 * one message in, at most one line out, notifications silent, and the
 * error-posture split (execution failure = tool result isError, protocol
 * failure = JSON-RPC error).
 */
#[CoversClass(Server::class)]
class McpServerTest extends TestCase
{
    /** @var list<array{name: string, arguments: array<string, mixed>}> read by the fixture provider (anonymous classes are separate scopes) */
    public array $calls = [];

    public bool $failNextCall = false;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = [];
        $this->failNextCall = false;

        $provider = new class($this) implements ToolProviderInterface {
            public function __construct(private McpServerTest $test)
            {
            }

            public function describeTools(): array
            {
                return [[
                    'name' => 'demo.ping',
                    'description' => 'Echoes back.',
                    'inputSchema' => ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']]],
                ]];
            }

            public function callTool(string $name, array $arguments): array
            {
                if ($name !== 'demo.ping') {
                    throw new UnknownToolException("Unknown tool: {$name}");
                }

                $this->test->calls[] = ['name' => $name, 'arguments' => $arguments];

                if ($this->test->failNextCall) {
                    return ['ok' => false, 'text' => 'handler said no'];
                }

                return ['ok' => true, 'text' => \json_encode(['pong' => $arguments['msg'] ?? ''])];
            }
        };

        $this->server = new Server('test-server', '9.9.9', $provider);
    }

    public function testInitializeEchoesSupportedProtocolVersion(): void
    {
        $out = $this->request('initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => []]);

        self::assertSame('2025-03-26', $out['result']['protocolVersion']);
        self::assertSame('test-server', $out['result']['serverInfo']['name']);
        self::assertSame('9.9.9', $out['result']['serverInfo']['version']);
        self::assertSame(['tools' => ['listChanged' => false]], $out['result']['capabilities'], 'capabilities are the truth: tools only');
    }

    public function testUnsupportedClientVersionAnswersLatestSupported(): void
    {
        $out = $this->request('initialize', ['protocolVersion' => '2099-01-01']);

        self::assertSame(Server::SUPPORTED_VERSIONS[0], $out['result']['protocolVersion']);
    }

    public function testInitializedNotificationIsSilent(): void
    {
        self::assertNull($this->server->handleLine(\json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])));
    }

    public function testToolsListPassesDescriptorsThrough(): void
    {
        $out = $this->request('tools/list');

        self::assertSame('demo.ping', $out['result']['tools'][0]['name']);
        self::assertSame('Echoes back.', $out['result']['tools'][0]['description']);
    }

    public function testToolCallSuccessWrapsTextContent(): void
    {
        $out = $this->request('tools/call', ['name' => 'demo.ping', 'arguments' => ['msg' => 'hi']]);

        self::assertFalse($out['result']['isError']);
        self::assertSame('text', $out['result']['content'][0]['type']);
        self::assertSame(['pong' => 'hi'], \json_decode($out['result']['content'][0]['text'], true));
        self::assertSame([['name' => 'demo.ping', 'arguments' => ['msg' => 'hi']]], $this->calls, 'arguments arrive verbatim');
    }

    public function testToolExecutionFailureTravelsAsToolResultNotJsonRpcError(): void
    {
        $this->failNextCall = true;
        $out = $this->request('tools/call', ['name' => 'demo.ping']);

        self::assertArrayHasKey('result', $out, 'execution failure is NOT a protocol error (spec posture)');
        self::assertTrue($out['result']['isError']);
        self::assertStringContainsString('handler said no', $out['result']['content'][0]['text']);
    }

    public function testUnknownToolIsAProtocolLevelInvalidParams(): void
    {
        $out = $this->request('tools/call', ['name' => 'no.such.tool']);

        self::assertArrayHasKey('error', $out);
        self::assertSame(-32602, $out['error']['code']);
        self::assertStringContainsString('no.such.tool', $out['error']['message']);
    }

    public function testPingAnswersEmptyObject(): void
    {
        $line = $this->server->handleLine('{"jsonrpc":"2.0","id":7,"method":"ping"}');

        self::assertIsString($line);
        self::assertStringContainsString('"result":{}', $line, 'ping result must serialize {} not []');
    }

    public function testUnknownMethodGetsMethodNotFound(): void
    {
        $out = $this->request('prompts/list');

        self::assertSame(-32601, $out['error']['code']);
    }

    public function testMalformedLineGetsParseErrorWithNullId(): void
    {
        $out = \json_decode((string) $this->server->handleLine('{not json'), true);

        self::assertSame(-32700, $out['error']['code']);
        self::assertNull($out['id']);
    }

    public function testMissingJsonrpcVersionIsInvalidRequest(): void
    {
        $out = \json_decode((string) $this->server->handleLine('{"id":3,"method":"initialize"}'), true);

        self::assertSame(-32600, $out['error']['code']);
    }

    public function testInboundResponseShapedMessageIsIgnored(): void
    {
        self::assertNull($this->server->handleLine('{"jsonrpc":"2.0","id":1,"result":"whatever"}'));
        self::assertNull($this->server->handleLine('   '));
    }

    public function testEveryResponseIsFramedWithoutEmbeddedNewlines(): void
    {
        $line = $this->server->handleLine(\json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'demo.ping', 'arguments' => ['msg' => "multi\nline"]],
        ]));

        self::assertIsString($line);
        self::assertStringNotContainsString("\n", $line, 'stdout framing rule: one message, one line, embedded newlines escaped by encoding');
        $decoded = \json_decode($line, true);
        self::assertSame(['pong' => "multi\nline"], \json_decode($decoded['result']['content'][0]['text'], true), 'content survives the framing round-trip');
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, array $params = [], int $id = 1): array
    {
        $line = $this->server->handleLine(\json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]));

        self::assertIsString($line, "request to {$method} must be answered");

        return \json_decode($line, true);
    }
}

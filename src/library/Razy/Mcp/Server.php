<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Mcp;

use stdClass;
use Throwable;

/**
 * The MCP protocol core (Model Context Protocol 2025-06-18, stdio
 * transport framing rules), transport-agnostic by construction:
 * handleLine() takes one newline-delimited JSON-RPC message and returns
 * one response line (or null for notifications) — the terminal shell
 * owns the actual stdin/stdout. That split is what makes the protocol
 * unit-testable, and it enforces the spec's one hard rule from the
 * inside: nothing that is not a valid MCP message ever passes through
 * this class (human logging belongs to the shell's stderr, never here).
 *
 * Only what a tools-only server needs is implemented — initialize /
 * ping / tools/list / tools/call — and every declared capability is the
 * truth: capabilities.tools only, no prompts/resources/sampling claims.
 */
class Server
{
    /** MCP protocol versions this server speaks, newest first. */
    public const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(
        private readonly string $name,
        private readonly string $version,
        private readonly ToolProviderInterface $tools,
    ) {
    }

    /**
     * One message in, at most one response line out.
     *
     * @param string $line A single JSON-RPC message (no embedded newlines)
     *
     * @return string|null The response line (unframed) or null for
     *                     notifications / responses-to-server / ignored input
     */
    public function handleLine(string $line): ?string
    {
        $line = \trim($line);

        if ($line === '') {
            return null;
        }

        $message = \json_decode($line, true);

        if (!\is_array($message) || !isset($message['method']) && !isset($message['id'])) {
            return $this->error(null, -32700, 'Parse error');
        }

        if (!isset($message['jsonrpc']) || $message['jsonrpc'] !== '2.0' || !isset($message['method'])) {
            // A response-shaped inbound message (no method) is ignored:
            // a tools-only server sends no server-originated requests.
            if (!isset($message['method'])) {
                return null;
            }

            return $this->error($message['id'] ?? null, -32600, 'Invalid Request');
        }

        $method = (string) $message['method'];
        $isRequest = \array_key_exists('id', $message) && $message['id'] !== null;
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        if (!$isRequest) {
            // Notifications: initialized/cancelled/progress are all
            // legitimately no-ops for a tools-only server.
            return null;
        }

        $id = $message['id'];

        try {
            return match ($method) {
                'initialize' => $this->response($id, [
                    'protocolVersion' => \in_array((string) ($params['protocolVersion'] ?? ''), self::SUPPORTED_VERSIONS, true)
                        ? (string) $params['protocolVersion']
                        : self::SUPPORTED_VERSIONS[0],
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => $this->name, 'version' => $this->version],
                ]),
                'ping' => $this->response($id, new stdClass()),
                'tools/list' => $this->response($id, ['tools' => $this->tools->describeTools()]),
                'tools/call' => $this->handleToolCall($id, $params),
                default => $this->error($id, -32601, "Method not found: {$method}"),
            };
        } catch (UnknownToolException $e) {
            return $this->error($id, -32602, $e->getMessage());
        } catch (Throwable $e) {
            return $this->error($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleToolCall(int|string $id, array $params): string
    {
        $name = (string) ($params['name'] ?? '');

        if ($name === '') {
            return $this->error($id, -32602, 'tools/call requires a tool name');
        }

        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        // Tool EXECUTION failures travel as tool results (isError), per
        // the spec — only protocol-level problems are JSON-RPC errors.
        $answer = $this->tools->callTool($name, $arguments);

        return $this->response($id, [
            'content' => [['type' => 'text', 'text' => $answer['text']]],
            'isError' => $answer['ok'] !== true,
        ]);
    }

    private function response(int|string $id, mixed $result): string
    {
        return \json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function error(int|string|null $id, int $code, string $message): string
    {
        return \json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

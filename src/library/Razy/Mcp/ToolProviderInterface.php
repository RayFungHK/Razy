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

/**
 * What the MCP server knows about its tools — the provider answers in
 * the server's own vocabulary (names, JSON-schema descriptions, and a
 * call door) so the protocol core stays boot-agnostic and testable.
 */
interface ToolProviderInterface
{
    /**
     * Tool descriptors for tools/list.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function describeTools(): array;

    /**
     * Invoke one tool.
     *
     * @param array<string, mixed> $arguments The JSON object from tools/call
     *
     * @return array{ok: bool, text: string} The text answer and its error flag
     *
     * @throws UnknownToolException when the tool name is not ours
     */
    public function callTool(string $name, array $arguments): array;
}

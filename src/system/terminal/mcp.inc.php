<?php

/**
 * CLI Command: mcp.
 *
 * Serves one distributor as a Model Context Protocol (2025-06-18) server
 * over stdio: newline-delimited JSON-RPC on stdin/stdout, an AI client
 * on the other end. The exposed tool surface is EXACTLY the dist's
 * bridge-command surface (addBridgeCommand + each module's __onBridgeCall
 * allow-list + RAZY_BRIDGE_SECRET HMAC when set) — the MCP process is the
 * operator's own trust domain (source identity 'mcp-cli', same standing
 * as the bridge CLI command), and no new door is cut anywhere: whatever
 * this server can reach, `php Razy.phar bridge` could already reach.
 *
 * stdout carries MCP messages ONLY (the spec's hard rule): every human
 * line this command prints goes to stderr, before or during the loop.
 *
 * Usage:
 *   php Razy.phar mcp <distributor_code> [--domain=<tag>] [--list]
 *
 * Arguments:
 *   distributor_code  The dist to serve
 *
 * Options:
 *   --domain=<tag>    Domain tag for the boot (default: *)
 *   --list            Print the resolved tool table to stderr and exit
 *                     (a no-client-needed smoke of what would be served)
 *
 * Examples:
 *   php Razy.phar mcp erp
 *   php Razy.phar mcp erp --list          # what tools would this dist publish?
 *
 * @license MIT
 */

namespace Razy\Mcp;

use Razy\Distributor;
use Throwable;

return function (string $distCode = '', ...$args) {
    $distCode = \trim($distCode);
    $domain = '*';
    $listOnly = false;

    foreach ($args as $arg) {
        if (\str_starts_with($arg, '--domain=')) {
            $domain = \substr($arg, 9);
        } elseif ($arg === '--list') {
            $listOnly = true;
        }
    }

    if ($distCode === '') {
        \fwrite(STDERR, "usage: php Razy.phar mcp <distributor_code> [--domain=<tag>] [--list]\n");

        exit(1);
    }

    try {
        $distributor = new Distributor($distCode, $domain);
        $distributor->initialize();
    } catch (Throwable $e) {
        \fwrite(STDERR, "mcp: cannot boot '{$distCode}': {$e->getMessage()}\n");

        exit(1);
    }

    $provider = new BridgeProvider($distributor);

    if ($listOnly) {
        $tools = $provider->describeTools();
        \fwrite(STDERR, 'mcp: ' . \count($tools) . " tool(s) would be served for '{$distCode}':\n");

        foreach ($tools as $tool) {
            $required = $tool['inputSchema']['required'] ?? [];
            $params = [];

            foreach ((array) ($tool['inputSchema']['properties'] ?? []) as $name => $shape) {
                $params[] = \in_array($name, (array) $required, true) ? "\${$name}" : "[\${$name}]";
            }

            \fwrite(STDERR, \sprintf("  %-40s %s %s\n", $tool['name'], \implode(' ', $params), \mb_substr($tool['description'], 0, 60)));
        }

        exit(0);
    }

    $server = new Server('razy-mcp-' . $distCode, \RAZY_VERSION, $provider);

    \fwrite(STDERR, "mcp: serving '{$distCode}' over stdio (Ctrl+D to close)\n");

    while (($line = \fgets(STDIN)) !== false) {
        $response = $server->handleLine($line);

        if ($response !== null) {
            echo $response, PHP_EOL;
            \fflush(STDOUT);
        }
    }

    \fwrite(STDERR, "mcp: stdin closed, done.\n");
};

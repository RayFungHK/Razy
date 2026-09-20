# 10 — MCP Server (`php Razy.phar mcp <dist>`)

Serves one distributor as a **Model Context Protocol** server (protocol
`2025-06-18`, stdio transport) so an AI client can discover and call the
dist's own machine surface. Shipped v1.2.1.

## 1. The one-sentence security model

**The tool surface IS the bridge surface.** No new door is cut: whatever
the MCP server can reach, `php Razy.phar bridge` could already reach —
the same `addBridgeCommand` registrations, the same per-module
`__onBridgeCall` allow-list, the same `RAZY_BRIDGE_SECRET` HMAC gate. The
MCP process runs under the source identity `mcp-cli`; a local stdio
process is the operator's own trust domain (same standing as the bridge
CLI command itself), and with `RAZY_BRIDGE_SECRET` set the server
self-signs each call so the module-level HMAC verification still runs for
real.

## 2. Usage

```bash
php Razy.phar mcp <distributor> [--domain=<tag>]   # serve over stdio
php Razy.phar mcp <distributor> --list             # what WOULD be served
```

`--list` boots the dist, resolves every tool, prints the table to stderr
and exits — the no-client-needed smoke test.

Client config (any MCP client, e.g. Claude Desktop style JSON):

```json
{ "mcpServers": { "my-erp": { "command": "php",
    "args": ["C:/sites/myerp/Razy.phar", "mcp", "erp"] } } }
```

## 3. What tools look like (nothing is hand-registered twice)

Tool metadata is READ live from the real handlers — there is no side
registry that could drift from the code:

| tool part | source of truth |
|-----------|-----------------|
| existence | `Agent::addBridgeCommand()` registrations of loaded modules |
| executability | the same `__onBridgeCall` gate + HMAC as any bridge call |
| description | the handler's first comment line (or method docblock) |
| parameter schema | real reflection: parameter names, declared builtin types, defaults (`required` = no default) |
| invocation | named JSON arguments bound **by parameter name** to the positional call |

Module-code separators (`/`, `.`) map to `_` in tool names (MCP tool-name
charset), e.g. bridge command `ping` of `demo/Chatter` →
`demo_Chatter_ping`.

Two honest collapses, inherited from the bridge contract and surfaced,
not hidden:

* a `null` result reports `isError: true` + "unverified" — the bridge
  collapses *no-answer*, *`__onBridgeCall` denial* and *HMAC failure*
  into one outcome, and the MCP layer will not pretend to separate them;
* named-argument holes cannot be positionalized (`{first, count}` with an
  argumentless-default middle parameter is refused naming the hole, never
  silently shifted).

## 4. Protocol implementation notes

* One message in, at most one line out; stdout carries **MCP messages
  only** — every human line goes to stderr (the spec's hard rule, and
  the reason the protocol core lives in `Razy\Mcp\Server` transport-free
  and unit-tested, with the stdio shell in `mcp.inc.php` around it).
* Implemented methods: `initialize` (version negotiation among
  `2025-06-18 / 2025-03-26 / 2024-11-05`), `ping`, `tools/list`,
  `tools/call`; `notifications/*` are silent no-ops.
* Error posture per spec: tool **execution** failures travel as tool
  results (`isError: true`); only protocol-level problems are JSON-RPC
  errors (`-32700/-32600/-32601/-32602/-32603`).
* Declared capabilities are the truth: `tools` only — no prompts, no
  resources, no sampling claims.

## 5. Deliberate limits

* Read-stdio/write-stdout only; no HTTP/SSE transport (a stdio server is
  the operator launching it, which is also the trust model of §1).
* `tools/list` reflects the bridge handlers as booted; a module not
  loaded is not serving tools either — the listing never lies ahead of
  the boot.
* No annotations/DSL to describe parameters: reflection on the real
  handler is the whole story. (If richer schemas are ever needed, the
  honest place is declared types on the closure parameters.)

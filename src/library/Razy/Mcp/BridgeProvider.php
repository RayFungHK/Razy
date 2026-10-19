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

use Razy\BridgeSignature;
use Razy\Distributor;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use stdClass;

/**
 * Publishes a running dist's BRIDGE commands as MCP tools — the whole
 * point being that nothing new is exposed: the bridge surface already is
 * the dist's declared machine door (addBridgeCommand registration +
 * per-module __onBridgeCall allow-list + optional RAZY_BRIDGE_SECRET
 * HMAC), so an MCP client gets exactly the reach the module author
 * already granted, named per module legal entity (RZ-002/RZ-017 shape
 * respected by construction, not by a new bypass).
 *
 * Tool metadata is READ from the real handlers (no side registry to
 * drift): descriptions from the handler's first comment line, input
 * schemas from real parameter names/declared types/defaults via
 * reflection over the SAME loader the dispatcher execution uses.
 *
 * Positional binding: bridge closures are invoked positionally
 * (call_user_func_array), while MCP passes named JSON arguments — named
 * parameters are matched by name and emitted in declaration order;
 * omitted-with-default params are materialized, a hole before a given
 * value is refused loudly (never silently shifted).
 *
 * The source identity is 'mcp-cli': a local stdio process is the
 * operator's own trust domain (same standing as the bridge CLI command),
 * and when RAZY_BRIDGE_SECRET is set the provider self-signs each call —
 * the HMAC then still proves the envelope reached the module intact.
 */
class BridgeProvider implements ToolProviderInterface
{
    /** @var array<string, array{module: string, command: string, reflection: ReflectionFunctionAbstract}>|null */
    private ?array $index = null;

    public function __construct(
        private readonly Distributor $distributor,
        private readonly string $source = 'mcp-cli',
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function describeTools(): array
    {
        $out = [];

        foreach ($this->index() as $name => $entry) {
            $out[] = [
                'name' => $name,
                'description' => $this->describeHandler(
                    $entry['reflection'],
                    "Bridge command {$entry['command']} of module {$entry['module']}.",
                ),
                'inputSchema' => $this->schemaFor($entry['reflection']),
            ];
        }

        return $out;
    }

    /**
     * {@inheritdoc}
     */
    public function callTool(string $name, array $arguments): array
    {
        $entry = $this->index()[$name] ?? null;

        if ($entry === null) {
            throw new UnknownToolException("Unknown tool: {$name}");
        }

        $module = $this->distributor->getRegistry()->get($entry['module']);

        if ($module === null) {
            return ['ok' => false, 'text' => "Module {$entry['module']} is not available in this boot."];
        }

        [$positional, $problem] = $this->bindArguments($entry['reflection'], $arguments);

        if ($problem !== null) {
            return ['ok' => false, 'text' => $problem];
        }

        $meta = [];

        if (BridgeSignature::isEnabled()) {
            $envelope = BridgeSignature::signedPayload(
                BridgeSignature::secretFromEnv(),
                $this->source,
                $entry['module'],
                $entry['command'],
                $positional,
            );
            $meta = ['ts' => $envelope['ts'], 'nonce' => $envelope['nonce'], 'sig' => $envelope['sig']];
        }

        $result = $module->executeBridgeCommand($this->source, $entry['command'], $positional, $meta);

        if ($result === null) {
            return ['ok' => false, 'text' => 'null — the bridge contract collapses "no answer", "denied by __onBridgeCall", and HMAC failure into one outcome; treat this call as unverified.'];
        }

        $text = \json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return ['ok' => true, 'text' => $text === false ? \print_r($result, true) : $text];
    }

    /**
     * Build (once) the tool index: every loaded module's bridge commands,
     * resolved to reflections through the SAME controller-method precedence
     * CommandRegistry::executeCommand() uses — no drift between what is
     * listed and what would run.
     *
     * @return array<string, array{module: string, command: string, reflection: ReflectionFunctionAbstract}>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];

        foreach ($this->distributor->getRegistry()->getLoadedModulesInfo() as $info) {
            /** @var \Razy\Module $module */
            $module = $info['module'];
            $controller = $module->getController();

            if ($controller === null) {
                continue;
            }

            foreach ($module->getBridgeCommands() as $command => $path) {
                $reflection = null;

                if (!\str_contains((string) $command, '/') && \method_exists($controller, (string) $command)) {
                    $reflection = new ReflectionMethod($controller, (string) $command);
                } else {
                    $closure = $module->getClosureLoader()->getClosure((string) $path, $controller);

                    if ($closure !== null) {
                        $reflection = new ReflectionFunction($closure);
                    }
                }

                if ($reflection === null) {
                    continue; // handler unresolvable — nothing honest to describe, skip
                }

                $name = $this->toolName((string) $info['code'], (string) $command);
                $unique = $name;
                $n = 2;

                while (isset($index[$unique])) {
                    $unique = $name . '_' . $n++;
                }

                $index[$unique] = ['module' => (string) $info['code'], 'command' => (string) $command, 'reflection' => $reflection];
            }
        }

        return $this->index = $index;
    }

    /**
     * MCP tool-name charset is [A-Za-z0-9_-]; module codes carry '/' and
     * '.', so they map to separators, with the mapping retained in the
     * index (names are display, the index is truth).
     */
    private function toolName(string $moduleCode, string $command): string
    {
        return \preg_replace('/[^A-Za-z0-9_-]/', '_', "{$moduleCode}.{$command}") ?? "{$moduleCode}_{$command}";
    }

    /**
     * @return array{0: array<int, mixed>, 1: string|null} positional values and a refusal reason
     */
    private function bindArguments(ReflectionFunctionAbstract $r, array $arguments): array
    {
        $positional = [];
        $seenGiven = false;

        foreach ($r->getParameters() as $param) {
            $given = \array_key_exists($param->getName(), $arguments);

            if ($given) {
                $positional[] = $arguments[$param->getName()];
                $seenGiven = true;

                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $positional[] = $param->getDefaultValue();

                continue;
            }

            if ($seenGiven) {
                // A named hole before later values cannot be positionalized
                // honestly — the call is refused, never silently shifted.
                return [[], "Missing value for required parameter \${$param->getName()} of this tool."];
            }
        }

        return [$positional, null];
    }

    /**
     * @return array<string, mixed> a minimal, honest JSON schema
     */
    private function schemaFor(ReflectionFunctionAbstract $r): array
    {
        $properties = [];
        $required = [];

        foreach ($r->getParameters() as $param) {
            $type = $param->getType();
            $jsonType = null;

            if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                $jsonType = match ($type->getName()) {
                    'int' => 'integer',
                    'float' => 'number',
                    'string' => 'string',
                    'bool' => 'boolean',
                    'array' => 'array',
                    'true', 'false' => 'boolean',
                    default => null,
                };
            }

            $properties[$param->getName()] = $jsonType === null
                ? new stdClass() // honestly "any"
                : ['type' => $jsonType];

            if (!$param->isDefaultValueAvailable() && !$param->isOptional()) {
                $required[] = $param->getName();
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * The handler file's first meaningful comment line becomes the tool
     * description — read live from the handler source, so the doc IS the
     * code's own words (a lying README cannot masquerade as a tool).
     */
    private function describeHandler(ReflectionFunctionAbstract $r, string $fallback): string
    {
        $file = $r->getFileName();

        if ($file === false || !\is_file($file)) {
            return $fallback;
        }

        $lines = @\file($file);

        if ($lines === false) {
            return $fallback;
        }

        $limit = \max(0, (int) $r->getStartLine() - 1);

        for ($i = 0; $i <= $limit && $i < \count($lines); ++$i) {
            $text = \trim($lines[$i]);

            if ($text === '' || \str_starts_with($text, '<?php') || \str_starts_with($text, 'namespace') || \str_starts_with($text, 'use ')) {
                continue;
            }

            // Only comments may speak for a tool; the first code line ends
            // the search (a bare `return function` is not a description).
            if (!\str_starts_with($text, '/*') && !\str_starts_with($text, '*') && !\str_starts_with($text, '//')) {
                break;
            }

            $text = \trim($text, " \t*/#");

            if ($text === '' || \str_starts_with($text, '@') || \str_starts_with($text, 'This file is part of')) {
                continue;
            }

            return \mb_substr($text, 0, 200);
        }

        return $fallback;
    }
}

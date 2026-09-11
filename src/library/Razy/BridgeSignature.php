<?php

namespace Razy;

/**
 * HMAC signing for cross-distributor bridge payloads (2026 audit §S3, roadmap v1.2).
 *
 * The bridge surface lets one distributor invoke another's gated commands, but
 * the `$sourceDistributor` identity is self-declared: without a signature, any
 * caller can impersonate a trusted source and walk straight past the
 * __onBridgeCall allow-list (which gates on that very string).
 *
 * This class is the signing primitive; enforcement lives in
 * Module::executeBridgeCommand() and activates whenever the operator sets
 * RAZY_BRIDGE_SECRET. Endpoint implementers that build their own HTTP bridge
 * transport call verifyPayload() on inbound requests and sign() on outbound.
 *
 * Replay stance: the ±window is intentionally small (default 60s); nonce-based
 * single-use replay protection needs shared state the framework does not
 * guarantee, so replays inside the window remain possible — keep the window tight.
 */
class BridgeSignature
{
    /** Default max clock skew tolerance, in seconds. */
    public const DEFAULT_TOLERANCE = 60;

    /**
     * The configured bridge secret (env RAZY_BRIDGE_SECRET), or '' when HMAC
     * enforcement is disabled. Framework boot defines env(); falls back to the
     * standard superglobals so the class is usable under unit tests too.
     */
    public static function secretFromEnv(): string
    {
        if (\function_exists('env')) {
            return (string) \env('RAZY_BRIDGE_SECRET', '');
        }

        return (string) ($_ENV['RAZY_BRIDGE_SECRET'] ?? $_SERVER['RAZY_BRIDGE_SECRET'] ?? \getenv('RAZY_BRIDGE_SECRET') ?: '');
    }

    /** Whether the operator enabled bridge HMAC enforcement. */
    public static function isEnabled(): bool
    {
        return self::secretFromEnv() !== '';
    }

    /**
     * Deterministic canonical form of a bridge call, signed over every element.
     *
     * Keys are recursively sorted so argument-map ordering can never change the
     * signature; JSON_UNESCAPED_* keeps the payload encoding stable across locales.
     */
    public static function canonicalPayload(string $sourceDistributor, string $module, string $command, array $args, int $timestamp, string $nonce): string
    {
        $normalized = self::sortRecursive([
            'source' => $sourceDistributor,
            'module' => $module,
            'command' => $command,
            'args' => $args,
            'ts' => $timestamp,
            'nonce' => $nonce,
        ]);

        return (string) \json_encode($normalized, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * Produce the hex HMAC-SHA256 signature for a bridge call.
     */
    public static function sign(string $secret, string $sourceDistributor, string $module, string $command, array $args, int $timestamp, string $nonce): string
    {
        return \hash_hmac('sha256', self::canonicalPayload($sourceDistributor, $module, $command, $args, $timestamp, $nonce), $secret);
    }

    /**
     * Constant-time signature + freshness verification.
     *
     * @param int $toleranceSeconds max |now - ts| skew accepted
     */
    public static function verify(string $secret, string $sourceDistributor, string $module, string $command, array $args, int $timestamp, string $nonce, string $signature, int $toleranceSeconds = self::DEFAULT_TOLERANCE, ?int $now = null): bool
    {
        if ($secret === '' || $nonce === '') {
            return false;
        }

        $now ??= \time();
        if (\abs($now - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = self::sign($secret, $sourceDistributor, $module, $command, $args, $timestamp, $nonce);

        return \hash_equals($expected, $signature);
    }

    /**
     * Verify a complete inbound payload envelope:
     * ["source" => ..., "module" => ..., "command" => ..., "args" => [...],
     *  "ts" => 123, "nonce" => "...", "sig" => "..."]
     *
     * @param array $payload decoded request body
     * @param string|null $secret defaults to env RAZY_BRIDGE_SECRET
     */
    public static function verifyPayload(array $payload, ?string $secret = null, int $toleranceSeconds = self::DEFAULT_TOLERANCE, ?int $now = null): bool
    {
        $secret ??= self::secretFromEnv();

        if ($secret === '' || !isset($payload['source'], $payload['module'], $payload['command'], $payload['ts'], $payload['nonce'], $payload['sig'])) {
            return false;
        }

        if (!\is_array($payload['args'] ?? []) || !\is_int($payload['ts']) || !\is_string($payload['nonce']) || !\is_string($payload['sig'])) {
            return false;
        }

        return self::verify(
            $secret,
            (string) $payload['source'],
            (string) $payload['module'],
            (string) $payload['command'],
            $payload['args'],
            $payload['ts'],
            $payload['nonce'],
            $payload['sig'],
            $toleranceSeconds,
            $now,
        );
    }

    /**
     * Build the signed outbound envelope (transport-agnostic).
     *
     * @return array<string, mixed> payload ready for json_encode + transport
     */
    public static function signedPayload(string $secret, string $sourceDistributor, string $module, string $command, array $args = [], ?string $nonce = null, ?int $timestamp = null): array
    {
        $nonce ??= \bin2hex(\random_bytes(16));
        $timestamp ??= \time();

        return [
            'source' => $sourceDistributor,
            'module' => $module,
            'command' => $command,
            'args' => $args,
            'ts' => $timestamp,
            'nonce' => $nonce,
            'sig' => self::sign($secret, $sourceDistributor, $module, $command, $args, $timestamp, $nonce),
        ];
    }

    /**
     * Recursive key-sort so JSON encoding is order-independent.
     */
    private static function sortRecursive(array $data): array
    {
        \ksort($data);

        foreach ($data as $key => $value) {
            if (\is_array($value) && self::isAssociative($value)) {
                $data[$key] = self::sortRecursive($value);
            }
        }

        return $data;
    }

    private static function isAssociative(array $array): bool
    {
        return $array !== [] && !\array_is_list($array);
    }
}

<?php

/**
 * CLI Command: bridge.
 *
 * Execute internal API commands across distributors via JSON payload.
 * This command enables programmatic inter-process communication with
 * the Razy framework by accepting a JSON-encoded payload containing
 * the distributor code, module code, API command, and arguments.
 *
 * Usage:
 *   php Razy.phar bridge <json_payload>
 *
 * Payload Format:
 *   {"dist": "distributor_code", "module": "module_code", "command": "api_command", "args": []}
 *
 * Response Format:
 *   {"ok": true, "data": <result>}
 *   {"ok": false, "status": "unknown|failed", "error": "short message"}   (a command that is not registered, or threw)
 *   {"ok": false, "error": "error message"}                                (bad payload, distributor or module not found)
 *
 * Trust: this is the local CLI. It consults neither __onAPICall nor __onBridgeCall nor RAZY_BRIDGE_SECRET
 * (those apply to Module::executeBridgeCommand); shell access is full bridge access.
 *
 * @license MIT
 */

namespace Razy;

use Throwable;

return function (string $payload = '') {
    // Validate that a JSON payload was provided
    if (!$payload) {
        echo \json_encode(['ok' => false, 'error' => 'Missing payload']);
        return false;
    }

    // Decode and validate the JSON payload structure
    $data = \json_decode($payload, true);
    if (!\is_array($data)) {
        echo \json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
        return false;
    }

    // Extract required fields from the payload
    $distCode = (string) ($data['dist'] ?? '');
    $moduleCode = (string) ($data['module'] ?? '');
    $command = (string) ($data['command'] ?? '');
    $args = \is_array($data['args'] ?? null) ? $data['args'] : [];

    if (!$distCode || !$moduleCode || !$command) {
        echo \json_encode(['ok' => false, 'error' => 'Missing dist, module, or command']);
        return false;
    }

    try {
        // Initialize the application and find the distributor
        ($app = new Application())->host('localhost');
        Application::Lock();

        $distributor = $app->getDistributor($distCode);
        if (!$distributor) {
            echo \json_encode(['ok' => false, 'error' => "Distributor '$distCode' not found"]);
            return false;
        }

        // Get the module and execute the command
        $module = $distributor->getRegistry()->getLoadedAPIModule($moduleCode);
        if (!$module) {
            echo \json_encode(['ok' => false, 'error' => "Module '$moduleCode' not found"]);
            return false;
        }

        // RZ-52: say what happened (RZ-53 typed outcome). A real null is ok; an unknown or failed command is not.
        // The failing command's message and trace stay off stdout (the trace goes to stderr via __onError).
        $outcome = $module->executeInternalChecked($command, $args);
        if ($outcome['status'] !== 'ok') {
            $why = $outcome['status'] === 'unknown' ? "Unknown command '" . \substr($command, 0, 100) . "'" : 'Command failed.';
            echo \json_encode(['ok' => false, 'status' => $outcome['status'], 'error' => $why], JSON_INVALID_UTF8_SUBSTITUTE); // the cut name may end inside a character
            return false;
        }
        echo \json_encode(['ok' => true, 'data' => $outcome['value']]);
    } catch (Throwable $e) {
        echo \json_encode([
            'ok' => false,
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);
        return false;
    }

    return true;
};

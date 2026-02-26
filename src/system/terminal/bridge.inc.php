<?php
/**
 * CLI Command: bridge
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
 *   {"ok": false, "error": "error message"}
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

return function (string $payload = '') {
    // Validate that a JSON payload was provided
    if (!$payload) {
        echo json_encode(['ok' => false, 'error' => 'Missing payload']);
        return false;
    }

    // Decode and validate the JSON payload structure
    $data = json_decode($payload, true);
    if (!is_array($data)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
        return false;
    }

    // Extract required fields from the payload
    $distCode = (string)($data['dist'] ?? '');
    $moduleCode = (string)($data['module'] ?? '');
    $command = (string)($data['command'] ?? '');
    $args = is_array($data['args'] ?? null) ? $data['args'] : [];

    if (!$distCode || !$moduleCode || !$command) {
        echo json_encode(['ok' => false, 'error' => 'Missing dist, module, or command']);
        return false;
    }

    try {
        // Initialize the application and find the distributor
        ($app = new Application())->host('localhost');
        Application::Lock();

        $distributor = $app->getDistributor($distCode);
        if (!$distributor) {
            echo json_encode(['ok' => false, 'error' => "Distributor '$distCode' not found"]);
            return false;
        }

        // Get the module and execute the command
        $module = $distributor->getRegistry()->getLoadedAPIModule($moduleCode);
        if (!$module) {
            echo json_encode(['ok' => false, 'error' => "Module '$moduleCode' not found"]);
            return false;
        }

        $result = $module->executeInternalCommand($command, $args);
        echo json_encode(['ok' => true, 'data' => $result]);
    } catch (\Throwable $e) {
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);
        return false;
    }

    return true;
};

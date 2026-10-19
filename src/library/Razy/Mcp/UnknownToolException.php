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

use RuntimeException;

/**
 * Thrown by tool providers when a tools/call names a tool that does not
 * exist — the Server maps it to the JSON-RPC -32602 (invalid params)
 * error response, per the tools spec posture for unknown tool names.
 */
class UnknownToolException extends RuntimeException
{
}

<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Http;

use RuntimeException;

/**
 * Thrown when a request never produced an HTTP response.
 *
 * This is the fail-loud counterpart to the transport layer (OAuth dossier S1;
 * same doctrine as the `queue` CLI Q3 rework): a cURL-level failure — DNS
 * miss, connect timeout, TLS handshake, connection reset — and an insecure
 * URL rejected by the HTTPS-only policy both surface HERE, rather than as the
 * old silent synthetic `HttpResponse(status: 0)`. A "successful" call that
 * never reached the network was the phantom class of bug; it is now loud.
 *
 * Distinct from {@see HttpException}: that one means "the server answered with
 * 4xx/5xx" (there IS a response). This one means "there is no response at all".
 */
class HttpTransportException extends RuntimeException
{
    /**
     * cURL error number (0 when the failure is a policy rejection, not a cURL error).
     */
    private int $curlErrno;

    /**
     * @param string $message Human-readable reason
     * @param int $curlErrno cURL error number, or 0 for a policy rejection
     */
    public function __construct(string $message, int $curlErrno = 0)
    {
        $this->curlErrno = $curlErrno;

        parent::__construct($message, $curlErrno);
    }

    /**
     * The underlying cURL error number (0 for a policy rejection).
     */
    public function getCurlErrno(): int
    {
        return $this->curlErrno;
    }
}

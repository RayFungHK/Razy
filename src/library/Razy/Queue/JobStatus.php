<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Queue;

/**
 * Enum representing the lifecycle status of a queued job.
 *
 * @package Razy\Queue
 */
enum JobStatus: string
{
    /** Job is waiting to be processed. */
    case Pending = 'pending';

    /** Job has been reserved by a worker and is currently running. */
    case Reserved = 'reserved';

    /** Job completed successfully. */
    case Completed = 'completed';

    /** Job failed and may be retried. */
    case Failed = 'failed';

    /** Job has been permanently buried (exhausted retries). */
    case Buried = 'buried';
}

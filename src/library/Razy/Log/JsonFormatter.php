<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Log;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use Razy\Contract\Log\LogFormatterInterface;
use Stringable;
use Throwable;

/**
 * RZ-14: one JSON object per line, for log shippers (Loki, CloudWatch,
 * Datadog, `docker logs | jq`).
 *
 * ```json
 * {"ts":"2026-10-21T08:00:00.123456+00:00","level":"error","channel":"razy",
 *  "message":"…","request_id":"…","context":{"error_id":"…"},
 *  "exception":{"class":"…","message":"…","file":"…","line":12}}
 * ```
 *
 * `request_id` is lifted to the top level; any other context goes under
 * `context`. A Throwable in `context.exception` becomes a class/message/
 * file/line object (`trace` too when the formatter is built with
 * `$includeTrace`). Values JSON cannot encode are replaced, never dropped
 * silently with the whole line.
 *
 * @license MIT
 */
final class JsonFormatter implements LogFormatterInterface
{
    private const FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR;

    /** Nesting depth kept for arrays in context; deeper levels become "[depth]". */
    private const MAX_DEPTH = 6;

    public function __construct(private readonly bool $includeTrace = false)
    {
    }

    public function format(string $level, string $message, array $context, string $timestamp, string $channel): string
    {
        $record = [
            'ts' => $this->isoTime($timestamp),
            'level' => $level,
        ];
        if ($channel !== '') {
            $record['channel'] = $channel;
        }
        $record['message'] = $message;

        if (isset($context['request_id']) && \is_scalar($context['request_id'])) {
            $record['request_id'] = (string) $context['request_id'];
        }
        $exception = $context['exception'] ?? null;
        unset($context['request_id'], $context['exception']);

        if ($context !== []) {
            $record['context'] = $this->normalize($context, 0);
        }
        if ($exception instanceof Throwable) {
            $record['exception'] = $this->exception($exception);
        } elseif ($exception !== null) {
            $record['context']['exception'] = $this->normalize($exception, 0);
        }

        $json = \json_encode($record, self::FLAGS);

        return $json === false ? '{"level":"' . $level . '","message":"(unencodable log record)"}' : $json;
    }

    private function isoTime(string $timestamp): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $timestamp);

        return $date === false ? $timestamp : $date->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * @return array<string, mixed>
     */
    private function exception(Throwable $e): array
    {
        $data = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        if ($this->includeTrace) {
            $data['trace'] = $e->getTraceAsString();
        }
        if ($e->getPrevious() !== null) {
            $data['previous'] = $this->exception($e->getPrevious());
        }

        return $data;
    }

    private function normalize(mixed $value, int $depth): mixed
    {
        if ($value === null || \is_scalar($value)) {
            if (\is_float($value) && !\is_finite($value)) {
                // explicit labels: (string) NAN warns on PHP 8.5
                return \is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF');
            }

            return $value;
        }
        if ($depth >= self::MAX_DEPTH) {
            return '[depth]';
        }
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalize($v, $depth + 1);
            }

            return $out;
        }
        if ($value instanceof Throwable) {
            return $this->exception($value);
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::RFC3339_EXTENDED);
        }
        if ($value instanceof JsonSerializable) {
            return $this->normalize($value->jsonSerialize(), $depth + 1);
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return \is_object($value) ? '[object ' . $value::class . ']' : '[' . \get_debug_type($value) . ']';
    }
}

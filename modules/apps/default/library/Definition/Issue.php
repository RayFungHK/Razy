<?php

/**
 * razymod/apps — one validation finding: where, what (a stable code) and a
 * plain-words message. The path is a JSON Pointer into the definition; for
 * PackageLoader findings it is the file inside the package ("/entities/deal.json").
 */

declare(strict_types=1);

namespace razymod\apps\Definition;

final class Issue
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    private function __construct(
        public readonly string $severity,
        public readonly string $path,
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    public static function error(string $path, string $code, string $message): self
    {
        return new self(self::ERROR, $path === '' ? '/' : $path, $code, $message);
    }

    public static function warning(string $path, string $code, string $message): self
    {
        return new self(self::WARNING, $path === '' ? '/' : $path, $code, $message);
    }

    /**
     * @return array{severity: string, path: string, code: string, message: string}
     */
    public function toArray(): array
    {
        return ['severity' => $this->severity, 'path' => $this->path, 'code' => $this->code, 'message' => $this->message];
    }
}

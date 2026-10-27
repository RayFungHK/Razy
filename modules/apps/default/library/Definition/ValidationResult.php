<?php

/**
 * razymod/apps — the outcome of Validator::validate().
 *
 * ok() is true only with zero errors (warnings do not block). The typed
 * expressions and the i18n keys the definition uses are kept so the
 * compiler does not parse twice.
 */

declare(strict_types=1);

namespace razymod\apps\Definition;

final class ValidationResult
{
    /**
     * @param list<Issue> $issues
     * @param array<string, array{src: string, ast: array<string, mixed>, site: string}> $expressions JSON Pointer => typed expression
     * @param list<string> $i18nKeys
     */
    public function __construct(
        private readonly array $issues,
        private readonly array $expressions,
        private readonly array $i18nKeys,
    ) {
    }

    public function ok(): bool
    {
        return $this->errors() === [];
    }

    /**
     * @return list<Issue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<Issue>
     */
    public function errors(): array
    {
        return \array_values(\array_filter($this->issues, static fn (Issue $i): bool => $i->severity === Issue::ERROR));
    }

    /**
     * @return array<string, array{src: string, ast: array<string, mixed>, site: string}>
     */
    public function expressions(): array
    {
        return $this->expressions;
    }

    /**
     * @return list<string>
     */
    public function i18nKeys(): array
    {
        return $this->i18nKeys;
    }

    /**
     * @return list<array{severity: string, path: string, code: string, message: string}>
     */
    public function issuesArray(): array
    {
        return \array_map(static fn (Issue $i): array => $i->toArray(), $this->issues);
    }
}

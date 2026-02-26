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

namespace Razy\Auth;

use Closure;
use InvalidArgumentException;
use Razy\Contract\AuthenticatableInterface;

/**
 * Authorization gate for defining and checking abilities.
 *
 * The Gate provides a fluent API for defining authorization rules (abilities)
 * and checking them against the authenticated user. It also supports
 * policy-based authorization where resource-specific checks are delegated
 * to dedicated policy classes.
 *
 * Usage:
 * ```php
 * $gate = new Gate($auth);
 *
 * // Define a simple ability
 * $gate->define('create-post', fn(AuthenticatableInterface $user) => true);
 *
 * // Define an ability with a resource
 * $gate->define('edit-post', function (AuthenticatableInterface $user, $post) {
 *     return $user->getAuthIdentifier() === $post->author_id;
 * });
 *
 * // Register a policy
 * $gate->policy(Post::class, PostPolicy::class);
 *
 * // Check abilities
 * if ($gate->allows('edit-post', $post)) { ... }
 * if ($gate->denies('delete-post', $post)) { ... }
 *
 * // Throws AccessDeniedException if denied
 * $gate->authorize('edit-post', $post);
 *
 * // Check for a specific user (without changing auth state)
 * $gate->forUser($adminUser)->allows('manage-users');
 * ```
 *
 * @package Razy\Auth
 */
class Gate
{
    /**
     * Defined abilities.
     *
     * @var array<string, Closure>
     */
    private array $abilities = [];

    /**
     * Registered policy class mappings.
     *
     * @var array<string, string> model class => policy class
     */
    private array $policies = [];

    /**
     * Optional before-check interceptor.
     */
    private ?Closure $beforeCallback = null;

    /**
     * Optional after-check interceptor.
     */
    private ?Closure $afterCallback = null;

    /**
     * Explicit user override (for forUser() scoping).
     */
    private ?AuthenticatableInterface $userOverride = null;

    /**
     * Create a new Gate.
     *
     * @param AuthManager $auth The auth manager to resolve the current user
     */
    public function __construct(
        private readonly AuthManager $auth,
    ) {}

    /**
     * Define an authorization ability.
     *
     * @param string  $ability  The ability name (e.g., 'edit-post')
     * @param Closure $callback Closure(AuthenticatableInterface $user, mixed ...$args): bool
     *
     * @return static For method chaining
     */
    public function define(string $ability, Closure $callback): static
    {
        $this->abilities[$ability] = $callback;

        return $this;
    }

    /**
     * Register a policy class for a model class.
     *
     * The policy class should have methods named after abilities
     * (e.g., `edit(AuthenticatableInterface $user, Post $post): bool`).
     *
     * @param string $modelClass  The fully qualified model class name
     * @param string $policyClass The fully qualified policy class name
     *
     * @return static For method chaining
     */
    public function policy(string $modelClass, string $policyClass): static
    {
        $this->policies[$modelClass] = $policyClass;

        return $this;
    }

    /**
     * Register a before-check interceptor.
     *
     * If the callback returns a non-null boolean, that result is used
     * and the normal ability check is skipped. Return null to proceed
     * with the normal check.
     *
     * @param Closure $callback Closure(AuthenticatableInterface $user, string $ability, array $args): ?bool
     *
     * @return static For method chaining
     */
    public function before(Closure $callback): static
    {
        $this->beforeCallback = $callback;

        return $this;
    }

    /**
     * Register an after-check interceptor.
     *
     * Called after the ability check with the result. Can override the result
     * by returning a non-null boolean.
     *
     * @param Closure $callback Closure(AuthenticatableInterface $user, string $ability, bool $result, array $args): ?bool
     *
     * @return static For method chaining
     */
    public function after(Closure $callback): static
    {
        $this->afterCallback = $callback;

        return $this;
    }

    /**
     * Determine if the given ability is allowed for the current user.
     *
     * @param string $ability   The ability name
     * @param mixed  ...$arguments Additional arguments (e.g., the resource)
     *
     * @return bool True if the ability is allowed
     */
    public function allows(string $ability, mixed ...$arguments): bool
    {
        $user = $this->resolveUser();

        if ($user === null) {
            return false;
        }

        // Before interceptor
        if ($this->beforeCallback !== null) {
            $beforeResult = ($this->beforeCallback)($user, $ability, $arguments);
            if ($beforeResult !== null) {
                return (bool) $beforeResult;
            }
        }

        // Try policy first (if a model argument is provided)
        $result = $this->checkPolicy($user, $ability, $arguments);

        // Fall back to defined ability
        if ($result === null) {
            $result = $this->checkAbility($user, $ability, $arguments);
        }

        // Default deny for undefined abilities
        $result ??= false;

        // After interceptor
        if ($this->afterCallback !== null) {
            $afterResult = ($this->afterCallback)($user, $ability, $result, $arguments);
            if ($afterResult !== null) {
                $result = (bool) $afterResult;
            }
        }

        return $result;
    }

    /**
     * Determine if the given ability is denied for the current user.
     *
     * @param string $ability      The ability name
     * @param mixed  ...$arguments Additional arguments
     *
     * @return bool True if the ability is denied
     */
    public function denies(string $ability, mixed ...$arguments): bool
    {
        return !$this->allows($ability, ...$arguments);
    }

    /**
     * Check if ALL of the given abilities are allowed.
     *
     * @param string[] $abilities  Array of ability names
     * @param mixed    ...$arguments Additional arguments
     *
     * @return bool True if ALL abilities are allowed
     */
    public function check(array $abilities, mixed ...$arguments): bool
    {
        foreach ($abilities as $ability) {
            if ($this->denies($ability, ...$arguments)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if ANY of the given abilities are allowed.
     *
     * @param string[] $abilities  Array of ability names
     * @param mixed    ...$arguments Additional arguments
     *
     * @return bool True if at least one ability is allowed
     */
    public function any(array $abilities, mixed ...$arguments): bool
    {
        foreach ($abilities as $ability) {
            if ($this->allows($ability, ...$arguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if NONE of the given abilities are allowed.
     *
     * @param string[] $abilities  Array of ability names
     * @param mixed    ...$arguments Additional arguments
     *
     * @return bool True if no abilities are allowed
     */
    public function none(array $abilities, mixed ...$arguments): bool
    {
        return !$this->any($abilities, ...$arguments);
    }

    /**
     * Authorize the given ability or throw an exception.
     *
     * @param string $ability      The ability name
     * @param mixed  ...$arguments Additional arguments
     *
     * @throws AccessDeniedException If the ability is denied
     */
    public function authorize(string $ability, mixed ...$arguments): void
    {
        if ($this->denies($ability, ...$arguments)) {
            throw new AccessDeniedException("This action is unauthorized: [{$ability}].");
        }
    }

    /**
     * Create a gate scoped to a specific user (without changing auth state).
     *
     * @param AuthenticatableInterface $user The user to check abilities for
     *
     * @return static A new Gate instance scoped to the given user
     */
    public function forUser(AuthenticatableInterface $user): static
    {
        $scoped = clone $this;
        $scoped->userOverride = $user;

        return $scoped;
    }

    /**
     * Check if an ability has been defined.
     *
     * @param string $ability The ability name
     *
     * @return bool True if the ability is defined
     */
    public function has(string $ability): bool
    {
        return isset($this->abilities[$ability]);
    }

    /**
     * Get all defined ability names.
     *
     * @return string[] Array of ability names
     */
    public function abilities(): array
    {
        return array_keys($this->abilities);
    }

    /**
     * Get the policy class for a given model class, if registered.
     *
     * @param string $modelClass The model class name
     *
     * @return string|null The policy class name, or null
     */
    public function getPolicyFor(string $modelClass): ?string
    {
        return $this->policies[$modelClass] ?? null;
    }

    // ── Internal ──────────────────────────────────────────────────

    /**
     * Resolve the current user (user override or auth manager).
     */
    private function resolveUser(): ?AuthenticatableInterface
    {
        if ($this->userOverride !== null) {
            return $this->userOverride;
        }

        return $this->auth->user();
    }

    /**
     * Check a defined ability callback.
     *
     * @return bool|null True/false if defined, null if not defined
     */
    private function checkAbility(AuthenticatableInterface $user, string $ability, array $arguments): ?bool
    {
        if (!isset($this->abilities[$ability])) {
            return null;
        }

        return (bool) ($this->abilities[$ability])($user, ...$arguments);
    }

    /**
     * Check a policy method for the given model argument.
     *
     * Inspects the first argument — if it's an object with a registered policy,
     * delegates to the policy's method named after the ability.
     *
     * @return bool|null True/false if policy found and method exists, null otherwise
     */
    private function checkPolicy(AuthenticatableInterface $user, string $ability, array $arguments): ?bool
    {
        if (empty($arguments)) {
            return null;
        }

        $model = $arguments[0];

        // Support both objects and class name strings
        $modelClass = is_object($model) ? get_class($model) : (is_string($model) ? $model : null);

        if ($modelClass === null || !isset($this->policies[$modelClass])) {
            return null;
        }

        $policyClass = $this->policies[$modelClass];
        $policy = new $policyClass();

        // Convert ability name to method name (e.g., 'edit-post' => 'editPost')
        $method = $this->abilityToMethod($ability);

        if (!method_exists($policy, $method)) {
            return null;
        }

        return (bool) $policy->{$method}($user, ...$arguments);
    }

    /**
     * Convert a kebab-case ability name to a camelCase method name.
     *
     * @param string $ability The ability name (e.g., 'edit-post')
     *
     * @return string The method name (e.g., 'editPost')
     */
    private function abilityToMethod(string $ability): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $ability))));
    }
}

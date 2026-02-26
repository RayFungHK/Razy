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

namespace Razy\Validation;

/**
 * Abstract Form Request — validates incoming request data automatically.
 *
 * Extend this class to define validation rules and authorization logic
 * for a specific form/endpoint.
 *
 * Usage:
 * ```php
 * class CreateUserRequest extends FormRequest
 * {
 *     protected function rules(): array
 *     {
 *         return [
 *             'name'  => [new Required(), new MinLength(2)],
 *             'email' => [new Required(), new Email()],
 *         ];
 *     }
 *
 *     protected function authorize(): bool
 *     {
 *         return true; // or check auth
 *     }
 *
 *     protected function messages(): array
 *     {
 *         return [
 *             'name.required' => 'Please enter your name.',
 *         ];
 *     }
 * }
 *
 * // In controller:
 * $request = CreateUserRequest::fromGlobals();
 * if ($request->fails()) {
 *     return $request->errorsAsJson();
 * }
 * $validated = $request->validated();
 * ```
 */
abstract class FormRequest
{
    /**
     * The validation result.
     */
    private ?ValidationResult $result = null;

    /**
     * Raw input data.
     *
     * @var array<string, mixed>
     */
    private array $data;

    /**
     * Whether the request is authorized.
     */
    private bool $authorized;

    /**
     * Create a new FormRequest with the given data.
     *
     * @param array<string, mixed> $data Input data to validate
     */
    public function __construct(array $data)
    {
        $this->data = $data;
        $this->authorized = $this->authorize();
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    /**
     * Create from PHP globals ($_POST + $_GET + $_FILES).
     *
     * POST data takes precedence over GET data.
     */
    public static function fromGlobals(): static
    {
        $data = \array_merge($_GET, $_POST);

        return new static($data);
    }

    /**
     * Create from a JSON request body.
     */
    public static function fromJson(): static
    {
        $raw = \file_get_contents('php://input') ?: '';
        $data = \json_decode($raw, true) ?? [];

        return new static($data);
    }

    /**
     * Create from specific data (for testing or programmatic use).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    // ═══════════════════════════════════════════════════════════════
    // Validation
    // ═══════════════════════════════════════════════════════════════

    /**
     * Run validation and return the result.
     *
     * Validation is lazy — runs only on first call, then cached.
     */
    public function validate(): ValidationResult
    {
        if ($this->result !== null) {
            return $this->result;
        }

        $data = $this->prepareForValidation($this->data);

        $validator = new Validator($data);
        $validator->defaults($this->defaults());
        $validator->fields($this->rules());

        $this->result = $validator->validate();

        return $this->result;
    }

    /**
     * Whether validation passed.
     */
    public function passes(): bool
    {
        return $this->isAuthorized() && $this->validate()->passes();
    }

    /**
     * Whether validation failed.
     */
    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * Whether the request is authorized.
     */
    public function isAuthorized(): bool
    {
        return $this->authorized;
    }

    /**
     * Get the validated data (only fields that passed).
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validate()->validated();
    }

    /**
     * Get the validation errors.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        if (!$this->isAuthorized()) {
            return ['_authorization' => ['This action is unauthorized.']];
        }

        return $this->validate()->errors();
    }

    /**
     * Get the raw input data.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Get a specific input value.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Get only specified keys from input.
     *
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return \array_intersect_key($this->data, \array_flip($keys));
    }

    /**
     * Get all input except specified keys.
     *
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return \array_diff_key($this->data, \array_flip($keys));
    }

    /**
     * Check if a key exists in the input.
     */
    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    /**
     * Check if a key is present and not empty.
     */
    public function filled(string $key): bool
    {
        return \array_key_exists($key, $this->data) && $this->data[$key] !== '' && $this->data[$key] !== null;
    }

    // ═══════════════════════════════════════════════════════════════
    // Response helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get errors formatted as a JSON string.
     */
    public function errorsAsJson(int $options = 0): string
    {
        return \json_encode([
            'errors' => $this->errors(),
        ], $options);
    }

    /**
     * Get errors as a flat array of messages.
     *
     * @return list<string>
     */
    public function allErrors(): array
    {
        $all = [];
        foreach ($this->errors() as $messages) {
            foreach ($messages as $msg) {
                $all[] = $msg;
            }
        }

        return $all;
    }

    // ═══════════════════════════════════════════════════════════════
    // Abstract methods — subclasses must implement
    // ═══════════════════════════════════════════════════════════════

    /**
     * Define the validation rules.
     *
     * @return array<string, list<ValidationRuleInterface>> Field → rules mapping
     */
    abstract protected function rules(): array;

    // ═══════════════════════════════════════════════════════════════
    // Overridable hooks
    // ═══════════════════════════════════════════════════════════════

    /**
     * Determine if the user is authorized to make this request.
     *
     * Override to add authorization logic.
     *
     * @return bool Default true (all requests authorized)
     */
    protected function authorize(): bool
    {
        return true;
    }

    /**
     * Custom error messages.
     *
     * Override to provide custom messages per field.rule.
     *
     * @return array<string, string> e.g. ['name.required' => 'Name is required']
     */
    protected function messages(): array
    {
        return [];
    }

    /**
     * Default values for missing fields.
     *
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [];
    }

    /**
     * Prepare the data before validation.
     *
     * Override to sanitize/transform input.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> Transformed data
     */
    protected function prepareForValidation(array $data): array
    {
        return $data;
    }
}

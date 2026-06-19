<?php

declare(strict_types=1);

namespace AlphaForge\Core;

use AlphaForge\Exceptions\ValidationException;

/**
 * Fluent input validator with chainable rules per field.
 *
 * Usage:
 *
 *   $data = (new Validator())
 *       ->field('email')->required()->email()->max(255)
 *       ->field('password')->required()->min(12)
 *       ->validate($request->all());
 *
 * Throws ValidationException (HTTP 422) if any rule fails.
 * Call errors() to retrieve the keyed error map without throwing.
 */
final class Validator
{
    /**
     * Rule definitions keyed by field name.
     * Each entry is an ordered list of callable validators.
     *
     * @var array<string, list<array{name: string, args: array<mixed>, message: string|null}>>
     */
    private array $rules = [];

    /** @var array<string, string> Validation errors keyed by field name */
    private array $validationErrors = [];

    /** The field name currently being configured */
    private string $currentField = '';

    /**
     * Begin configuring rules for a specific field.
     *
     * @param string $field The field name in the input array
     * @return static Fluent instance for chaining
     */
    public function field(string $field): static
    {
        $this->currentField = $field;

        if (!isset($this->rules[$field])) {
            $this->rules[$field] = [];
        }

        return $this;
    }

    /**
     * The field must be present and non-empty (not null, '', or []).
     *
     * @return static
     */
    public function required(): static
    {
        return $this->addRule('required', []);
    }

    /**
     * The field value must be a valid e-mail address.
     *
     * @return static
     */
    public function email(): static
    {
        return $this->addRule('email', []);
    }

    /**
     * The field value must have at least $length characters (or $length items for arrays).
     *
     * @param int $length Minimum character/item count
     * @return static
     */
    public function min(int $length): static
    {
        return $this->addRule('min', [$length]);
    }

    /**
     * The field value must not exceed $length characters (or $length items for arrays).
     *
     * @param int $length Maximum character/item count
     * @return static
     */
    public function max(int $length): static
    {
        return $this->addRule('max', [$length]);
    }

    /**
     * The field value must be numeric (integer or float).
     *
     * @return static
     */
    public function numeric(): static
    {
        return $this->addRule('numeric', []);
    }

    /**
     * The field value must be one of the provided allowed values.
     *
     * @param array<mixed> $values Whitelist of accepted values
     * @return static
     */
    public function in(array $values): static
    {
        return $this->addRule('in', [$values]);
    }

    /**
     * The field value must match the given PCRE regular expression.
     *
     * @param string $pattern Full PCRE pattern including delimiters, e.g. '/^\d+$/'
     * @return static
     */
    public function regex(string $pattern): static
    {
        return $this->addRule('regex', [$pattern]);
    }

    /**
     * The field value must be a valid UUID v4 string.
     *
     * @return static
     */
    public function uuid(): static
    {
        return $this->addRule('uuid', []);
    }

    /**
     * The field value must be a valid URL.
     *
     * @return static
     */
    public function url(): static
    {
        return $this->addRule('url', []);
    }

    /**
     * The field value must be a boolean (including '1', 'true', '0', 'false').
     *
     * @return static
     */
    public function boolean(): static
    {
        return $this->addRule('boolean', []);
    }

    /**
     * The field value must be an integer.
     *
     * @return static
     */
    public function integer(): static
    {
        return $this->addRule('integer', []);
    }

    /**
     * The field value must be an array.
     *
     * @return static
     */
    public function array(): static
    {
        return $this->addRule('array', []);
    }

    /**
     * The field value must be a string.
     *
     * @return static
     */
    public function string(): static
    {
        return $this->addRule('string', []);
    }

    /**
     * The field value must match the value of another field.
     *
     * @param string $otherField The name of the field whose value must match
     * @return static
     */
    public function same(string $otherField): static
    {
        return $this->addRule('same', [$otherField]);
    }

    /**
     * Set a custom error message for the most recently added rule.
     *
     * @param string $message
     * @return static
     */
    public function withMessage(string $message): static
    {
        $field = $this->currentField;

        if (isset($this->rules[$field]) && !empty($this->rules[$field])) {
            $lastIdx = count($this->rules[$field]) - 1;
            $this->rules[$field][$lastIdx]['message'] = $message;
        }

        return $this;
    }

    /**
     * Run all configured rules against $data.
     *
     * Returns the validated (and optionally transformed) data subset.
     * Only fields that have rules defined are returned in the output array.
     *
     * @param array<string, mixed> $data Input data to validate
     * @return array<string, mixed> Validated data (same values, possibly cast)
     * @throws \AlphaForge\Exceptions\ValidationException On any rule failure
     */
    public function validate(array $data): array
    {
        $this->validationErrors = [];
        $output                 = [];

        foreach ($this->rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $error = $this->applyRule($rule['name'], $value, $rule['args'], $field, $data);

                if ($error !== null) {
                    $this->validationErrors[$field] = $rule['message'] ?? $error;
                    break; // Stop at first failed rule per field.
                }
            }

            if (!array_key_exists($field, $this->validationErrors)) {
                $output[$field] = $value;
            }
        }

        if (!empty($this->validationErrors)) {
            throw new ValidationException(
                'Validation failed',
                $this->validationErrors,
            );
        }

        return $output;
    }

    /**
     * Return field-level validation errors from the last call to validate().
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Reset all rules and errors.
     *
     * @return static
     */
    public function reset(): static
    {
        $this->rules            = [];
        $this->validationErrors = [];
        $this->currentField     = '';

        return $this;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * @param array<mixed> $args
     */
    private function addRule(string $name, array $args): static
    {
        if ($this->currentField === '') {
            throw new \LogicException('Call field() before adding validation rules.');
        }

        $this->rules[$this->currentField][] = [
            'name'    => $name,
            'args'    => $args,
            'message' => null,
        ];

        return $this;
    }

    /**
     * Apply a single named rule to a value.
     *
     * @param string               $ruleName
     * @param mixed                $value
     * @param array<mixed>         $args
     * @param string               $field
     * @param array<string, mixed> $allData Full input data (needed for cross-field rules)
     * @return string|null Error message, or null if the rule passes
     */
    private function applyRule(
        string $ruleName,
        mixed  $value,
        array  $args,
        string $field,
        array  $allData,
    ): ?string {
        // Allow optional fields to skip non-required rules when value is absent.
        $isMissing = $value === null || $value === '';

        return match ($ruleName) {
            'required' => ($value === null || $value === '' || $value === [])
                ? "The {$field} field is required."
                : null,

            'email' => $isMissing ? null
                : (filter_var($value, FILTER_VALIDATE_EMAIL) === false
                    ? "The {$field} must be a valid email address."
                    : null),

            'min' => $isMissing ? null
                : (is_array($value)
                    ? (count($value) < $args[0] ? "The {$field} must have at least {$args[0]} items." : null)
                    : (mb_strlen((string) $value) < $args[0]
                        ? "The {$field} must be at least {$args[0]} characters."
                        : null)),

            'max' => $isMissing ? null
                : (is_array($value)
                    ? (count($value) > $args[0] ? "The {$field} may not have more than {$args[0]} items." : null)
                    : (mb_strlen((string) $value) > $args[0]
                        ? "The {$field} may not exceed {$args[0]} characters."
                        : null)),

            'numeric' => $isMissing ? null
                : (!is_numeric($value) ? "The {$field} must be a numeric value." : null),

            'integer' => $isMissing ? null
                : (filter_var($value, FILTER_VALIDATE_INT) === false
                    ? "The {$field} must be an integer."
                    : null),

            'in' => $isMissing ? null
                : (!in_array($value, $args[0], strict: true)
                    ? "The {$field} must be one of: " . implode(', ', $args[0]) . '.'
                    : null),

            'regex' => $isMissing ? null
                : (preg_match($args[0], (string) $value) !== 1
                    ? "The {$field} format is invalid."
                    : null),

            'uuid' => $isMissing ? null
                : (preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                    (string) $value,
                ) !== 1
                    ? "The {$field} must be a valid UUID."
                    : null),

            'url' => $isMissing ? null
                : (filter_var($value, FILTER_VALIDATE_URL) === false
                    ? "The {$field} must be a valid URL."
                    : null),

            'boolean' => $isMissing ? null
                : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null
                    ? "The {$field} must be a boolean value."
                    : null),

            'array' => $isMissing ? null
                : (!is_array($value) ? "The {$field} must be an array." : null),

            'string' => $isMissing ? null
                : (!is_string($value) ? "The {$field} must be a string." : null),

            'same' => $isMissing ? null
                : ($value !== ($allData[$args[0]] ?? null)
                    ? "The {$field} must match {$args[0]}."
                    : null),

            default => null,
        };
    }
}

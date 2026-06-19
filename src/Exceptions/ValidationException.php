<?php

declare(strict_types=1);

namespace AlphaForge\Exceptions;

/**
 * Thrown when user-supplied input fails validation rules.
 *
 * Carries a field-keyed error map that controllers can forward directly
 * to Response::validationError() for a consistent 422 response.
 */
final class ValidationException extends \RuntimeException
{
    /** @var array<string, string> Field-level validation error messages */
    private array $fieldErrors;

    /**
     * @param string               $message     General validation failure message
     * @param array<string, string> $fieldErrors Field → error message map
     * @param int                  $code        Exception code (unused by HTTP layer)
     * @param \Throwable|null      $previous    Chained exception
     */
    public function __construct(
        string     $message     = 'Validation failed',
        array      $fieldErrors = [],
        int        $code        = 422,
        ?\Throwable $previous   = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->fieldErrors = $fieldErrors;
    }

    /**
     * Return all field-level validation errors.
     *
     * @return array<string, string>
     */
    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }
}

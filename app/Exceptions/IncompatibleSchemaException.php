<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The ISPConfig database does not match what the API needs (spec 010). Carries
 * only identifiers and levels, so rendering it can never leak SQL or values.
 */
class IncompatibleSchemaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $table = null,
        public readonly ?string $column = null,
        public readonly ?int $foundDbversion = null,
        public readonly ?int $requiredDbversion = null,
    ) {
        parent::__construct($message);
    }
}

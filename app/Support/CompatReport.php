<?php

namespace App\Support;

use App\Exceptions\IncompatibleSchemaException;

/**
 * Result of comparing the ISPConfig schema level with the supported minimum
 * (spec 010 data-model: CompatReport).
 */
final class CompatReport
{
    public const OK = 'ok';

    public const INCOMPATIBLE = 'incompatible';

    /** The level could not be read (no server row, null dbversion). */
    public const UNKNOWN = 'unknown';

    /** The database could not be queried. */
    public const UNREACHABLE = 'unreachable';

    public function __construct(
        public readonly string $state,
        public readonly int $requiredDbversion,
        public readonly string $minVersion,
        public readonly ?int $dbversion = null,
        public readonly ?string $version = null,
    ) {}

    public function compatible(): bool
    {
        return $this->state === self::OK;
    }

    /** Informational only: the release string never decides compatibility. */
    public function versionBelowMinimum(): ?bool
    {
        return $this->version === null ? null : version_compare($this->version, $this->minVersion, '<');
    }

    public function toException(): IncompatibleSchemaException
    {
        $hint = " Run ISPConfig's updater.";

        if ($this->state === self::INCOMPATIBLE) {
            return new IncompatibleSchemaException(
                "ISPConfig schema level {$this->dbversion} is below the required {$this->requiredDbversion}.".$hint,
                foundDbversion: $this->dbversion,
                requiredDbversion: $this->requiredDbversion,
            );
        }

        return new IncompatibleSchemaException(
            "The ISPConfig schema level could not be determined (required: {$this->requiredDbversion}); writes are refused until it can be read.",
            requiredDbversion: $this->requiredDbversion,
        );
    }
}

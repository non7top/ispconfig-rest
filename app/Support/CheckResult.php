<?php

namespace App\Support;

/**
 * One readiness check (spec 010 data-model: CheckResult). `detail` is for
 * `doctor` only; probes expose the state alone.
 */
final class CheckResult
{
    public const OK = 'ok';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $name,
        public readonly string $state,
        public readonly ?string $detail = null,
    ) {}
}

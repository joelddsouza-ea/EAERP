<?php

namespace App\Modules\Workshop\Workflow;

use RuntimeException;

/**
 * Thrown when a status change is not allowed: unknown edge, missing permission,
 * missing notes, or failed guards.
 */
class TransitionBlockedException extends RuntimeException
{
    /**
     * @param GuardFailure[] $failures
     */
    public function __construct(
        string $message,
        public readonly array $failures = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function errors(): array
    {
        return ['guards' => array_map(fn (GuardFailure $f) => $f->toArray(), $this->failures)];
    }
}

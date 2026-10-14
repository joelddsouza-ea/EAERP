<?php

namespace App\Modules\Workshop\Workflow;

class GuardFailure
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $details = [],
    ) {}

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}

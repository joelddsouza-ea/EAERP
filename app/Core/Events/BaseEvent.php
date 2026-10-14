<?php

namespace App\Core\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base Event class for all application events.
 */
abstract class BaseEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The time the event was fired.
     */
    public \DateTimeInterface $firedAt;

    /**
     * The user who triggered the event.
     */
    public ?int $userId;

    public function __construct()
    {
        $this->firedAt = now();
        $this->userId = auth()->id();
    }
}

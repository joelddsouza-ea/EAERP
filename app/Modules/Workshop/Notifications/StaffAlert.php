<?php

namespace App\Modules\Workshop\Notifications;

use App\Modules\Workshop\Models\JobCard;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app notification for workshop staff (bell in the app header).
 * kind: parts_price_requested | parts_quoted | parts_to_procure | estimate_answered
 */
class StaffAlert extends Notification
{
    use Queueable;

    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public string $link,
        public ?int $jobCardId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
            'job_card_id' => $this->jobCardId,
        ];
    }

    public static function partsPriceRequested(JobCard $jobCard, array $partNames, string $advisor): self
    {
        return new self(
            'parts_price_requested',
            "Parts price needed — {$jobCard->job_number}",
            "{$advisor} asked for prices: " . implode(', ', $partNames),
            '/parts-desk?tab=requests',
            $jobCard->id,
        );
    }

    public static function partsQuoted(JobCard $jobCard, array $partNames): self
    {
        return new self(
            'parts_quoted',
            "Parts prices ready — {$jobCard->job_number}",
            'Quoted: ' . implode(', ', $partNames) . '. Add them to the estimate.',
            "/job-cards/{$jobCard->id}/estimate",
            $jobCard->id,
        );
    }

    public static function partsToProcure(JobCard $jobCard, array $partNames): self
    {
        return new self(
            'parts_to_procure',
            "Customer approved parts — {$jobCard->job_number}",
            'Procure: ' . implode(', ', $partNames),
            '/parts-desk?tab=procure',
            $jobCard->id,
        );
    }

    public static function estimateAnswered(JobCard $jobCard, int $approved, int $declined, int $pending): self
    {
        $parts = array_filter([
            $approved ? "{$approved} approved" : null,
            $declined ? "{$declined} declined" : null,
            $pending ? "{$pending} still pending" : null,
        ]);

        return new self(
            'estimate_answered',
            "Customer answered the estimate — {$jobCard->job_number}",
            ucfirst(implode(', ', $parts)) . '.',
            "/job-cards/{$jobCard->id}/estimate",
            $jobCard->id,
        );
    }
}

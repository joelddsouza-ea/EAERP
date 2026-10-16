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

    public static function technicianNeedsParts(JobCard $jobCard, string $technician, array $partNames): self
    {
        return new self(
            'technician_needs_parts',
            "Technician needs parts — {$jobCard->job_number}",
            "{$technician} sent: " . implode(', ', $partNames) . '. Review and send them to the spare parts coordinator.',
            "/job-cards/{$jobCard->id}/estimate",
            $jobCard->id,
        );
    }

    public static function jobAssigned(JobCard $jobCard, ?string $assignedBy): self
    {
        $car = trim(implode(' ', array_filter([
            $jobCard->vehicle?->registration_no,
            $jobCard->vehicle?->make?->name,
            $jobCard->vehicle?->model?->name,
        ])));

        return new self(
            'job_assigned',
            "New job assigned — {$jobCard->job_number}",
            trim(($car ? "{$car}. " : '') . ($assignedBy ? "Assigned by {$assignedBy}. " : '') . 'Open the job sheet to start.'),
            "/job-cards/{$jobCard->id}/sheet",
            $jobCard->id,
        );
    }

    public static function partsReceived(JobCard $jobCard, array $partNames, bool $forTechnician): self
    {
        return new self(
            'parts_received',
            "Parts arrived — {$jobCard->job_number}",
            'Received: ' . implode(', ', $partNames) . ($forTechnician ? '. Fit them and carry on with the job sheet.' : '.'),
            $forTechnician ? "/job-cards/{$jobCard->id}/sheet" : "/job-cards/{$jobCard->id}",
            $jobCard->id,
        );
    }

    public static function wrongPart(JobCard $jobCard, string $partName, string $technician, ?string $note): self
    {
        return new self(
            'wrong_part',
            "Wrong part received — {$jobCard->job_number}",
            "{$technician} says {$partName} is not the right part" . ($note ? ": {$note}" : '') . '. Return it to the supplier and get the right one.',
            '/parts-desk?tab=procure',
            $jobCard->id,
        );
    }

    public static function jobSheetSubmitted(JobCard $jobCard, string $sheetName, string $technician): self
    {
        return new self(
            'job_sheet_submitted',
            "Job sheet submitted — {$jobCard->job_number}",
            "{$technician} submitted {$sheetName}.",
            "/job-cards/{$jobCard->id}",
            $jobCard->id,
        );
    }

    public static function observationsSubmitted(JobCard $jobCard, string $technician, int $count): self
    {
        return new self(
            'observations_submitted',
            "Observations for the report — {$jobCard->job_number}",
            "{$technician} submitted {$count} observation(s) for the customer's report. Check the remarks and release the report.",
            "/job-cards/{$jobCard->id}/sheet",
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

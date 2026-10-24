<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\Role;
use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\WorkflowStage;
use App\Modules\Workshop\Notifications\StaffAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * "Send update": a technician (after a job-sheet section), a driver (after a trip step) or a
 * manager finishes a step and asks for the customer to be told. The WhatsApp message is made
 * ready and handed to the job's advisor, who reads it and sends it from the bell. Staff who
 * send customer messages themselves (advisors, managers) get it ready to send at once.
 */
class CustomerUpdateService
{
    public const KIND_SECTION = 'section';
    public const KIND_STAGE = 'stage';

    public function __construct(
        protected CustomerMessageService $messages,
        protected CustomerGalleryService $gallery,
    ) {}

    /**
     * @return array{message: CustomerMessage, to_advisor: bool}
     *
     * @throws JobSheetException
     */
    public function request(JobCard $jobCard, User $user, string $kind, ?int $sheetId = null, ?string $section = null): array
    {
        if ($jobCard->isClosed()) {
            throw new JobSheetException('This job card is closed.');
        }

        [$template, $text, $params] = match ($kind) {
            self::KIND_SECTION => $this->sectionMessage($jobCard, $sheetId, $section),
            self::KIND_STAGE => $this->stageMessage($jobCard),
            default => throw new JobSheetException('Unknown update.'),
        };

        // One update per step: not asked twice
        $existing = CustomerMessage::where('job_card_id', $jobCard->id)->where('template', $template)
            ->whereIn('status', [CustomerMessage::PREPARED, CustomerMessage::SENT])
            ->latest('id')->first();
        if ($existing?->status === CustomerMessage::SENT) {
            throw new JobSheetException('The customer has already been told about this step.');
        }

        $selfSend = $user->hasPermission('workshop.customer-links.manage');
        $message = $existing ?? $this->messages->prepareText($jobCard, $template, $text, $params, $user);
        if ($message->status === CustomerMessage::FAILED) {
            throw new JobSheetException($message->error ?? 'This message cannot be sent.');
        }

        $recipients = $selfSend ? collect([$user]) : $this->advisors($jobCard);
        $message->update(['assigned_to' => $recipients->first()?->id]);

        if (!$selfSend) {
            if ($recipients->isEmpty()) {
                throw new JobSheetException('The job has no advisor to send the update.');
            }
            Notification::send($recipients, StaffAlert::customerUpdate(
                $jobCard, $message, $user->name, $this->messages->shareUrl($message), false,
            ));
        }

        return ['message' => $message, 'to_advisor' => !$selfSend];
    }

    /**
     * "Washing & Cleaning done …" for a finished job-sheet section, with the photos/videos link.
     *
     * @return array{0: string, 1: string, 2: array}
     */
    protected function sectionMessage(JobCard $jobCard, ?int $sheetId, ?string $section): array
    {
        $sheet = JobCardChecklist::where('job_card_id', $jobCard->id)->find($sheetId);
        $items = $sheet ? $sheet->items()->where('section_name', $section)->get() : collect();
        if ($items->isEmpty()) {
            throw new JobSheetException('That section is not on this job.');
        }
        if ($items->contains('status', JobCardChecklistItem::STATUS_PENDING)) {
            throw new JobSheetException('Finish every activity in the section first.');
        }

        $text = (string) (WorkshopSettings::get('updates.section_message') ?: config('workshop.updates.section_message'));

        return ["update:section:{$sheet->id}:" . Str::slug($section), $text, ['section' => $section]];
    }

    /**
     * The current stage's message (made by the status change, or from the stage master).
     *
     * @return array{0: string, 1: string, 2: array}
     */
    protected function stageMessage(JobCard $jobCard): array
    {
        $status = $jobCard->status;
        $text = trim((string) WorkflowStage::for($status)?->message);
        if ($text === '') {
            $text = (string) (WorkshopSettings::get('updates.stage_message') ?: config('workshop.updates.stage_message'));
        }
        $params = [
            'stage' => WorkflowStage::for($status)?->label ?? str_replace('_', ' ', $status),
            ...app(TripService::class)->messageParams($jobCard),
        ];

        return ["stage:{$status}", $text, $params];
    }

    /**
     * The job's advisor; without one, the branch's managers.
     */
    protected function advisors(JobCard $jobCard): Collection
    {
        if ($jobCard->service_advisor_id && ($advisor = User::active()->find($jobCard->service_advisor_id))) {
            return collect([$advisor]);
        }

        return User::active()
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::MANAGER, Role::BODY_SHOP_MANAGER]))
            ->get()
            ->filter(fn (User $u) => !$jobCard->branch_id || $u->hasAccessToBranch($jobCard->branch_id))
            ->values();
    }

    /**
     * Sent from the bell / job card: WhatsApp opened with the text (share link), then marked sent.
     */
    public function markSent(CustomerMessage $message, User $user): CustomerMessage
    {
        if ($message->status !== CustomerMessage::PREPARED) {
            throw new JobSheetException($message->status === CustomerMessage::SENT ? 'Already sent.' : 'This message was skipped.');
        }

        return $this->messages->markSent($message, $user);
    }

    public function skip(CustomerMessage $message, User $user): CustomerMessage
    {
        if ($message->status !== CustomerMessage::PREPARED) {
            throw new JobSheetException('This message is no longer waiting.');
        }
        $message->update(['status' => CustomerMessage::DISMISSED, 'sent_by' => $user->id]);

        return $message;
    }
}

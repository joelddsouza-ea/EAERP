<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Models\JobOutsideWork;
use App\Modules\Workshop\Models\ServicePartner;
use App\Modules\Workshop\Notifications\StaffAlert;
use App\Core\Services\WhatsApp\ShareLinkChannel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Cars sent to outside partners (washing, wheel alignment & balancing):
 * the technician sends the car to washing from the job sheet, the advisor ticks wheel
 * alignment on the job card; the partner uploads photos/videos from their login and marks it
 * done; the technician / advisor confirms the car is back. Until then the job can't be submitted.
 */
class OutsideWorkService
{
    public function __construct(protected JobSheetService $sheets) {}

    /**
     * @throws JobSheetException
     */
    public function send(JobCard $jobCard, string $kind, int $partnerId, User $user, ?string $note = null): JobOutsideWork
    {
        if ($jobCard->isClosed()) {
            throw new JobSheetException('This job card is closed.');
        }
        if (!array_key_exists($kind, ServicePartner::KINDS)) {
            throw new JobSheetException('Unknown outside work.');
        }
        $partner = ServicePartner::where('kind', $kind)->where('is_active', true)->forBranch($jobCard->branch_id)->find($partnerId);
        if (!$partner) {
            throw new JobSheetException('Choose a ' . strtolower(ServicePartner::KINDS[$kind]) . ' partner.', ['service_partner_id' => ['Choose a partner.']]);
        }
        if ($this->open($jobCard, $kind)) {
            throw new JobSheetException('The car is already with a ' . strtolower(ServicePartner::KINDS[$kind]) . ' partner.');
        }

        $work = JobOutsideWork::create([
            'job_card_id' => $jobCard->id,
            'kind' => $kind,
            'service_partner_id' => $partner->id,
            'status' => JobOutsideWork::SENT,
            'note' => $note,
            'sent_by' => $user->id,
            'sent_at' => now(),
        ]);

        $partner->user?->notify(StaffAlert::outsideWorkSent($jobCard, $work));

        return $work;
    }

    /**
     * The partner's photo / video of their work (from their login), or the technician's from the
     * gallery (what the washer sent on WhatsApp). Shown to the customer under the work.
     */
    public function attachMedia(JobOutsideWork $work, UploadedFile $file, User $user, ?UploadedFile $poster = null, array $meta = []): JobCardMedia
    {
        if ($work->status === JobOutsideWork::CANCELLED) {
            throw new JobSheetException('This outside work was cancelled.');
        }
        $jobCard = $work->jobCard;

        return $this->sheets->storeMedia(
            $jobCard, $file, 'outside_work', $work->id, "outside/{$work->id}",
            "{$work->kindLabel()} — {$jobCard->job_number}", $user, $poster, $meta,
        );
    }

    /**
     * The partner has finished; the technician and the advisor are told.
     */
    public function done(JobOutsideWork $work, User $user, ?string $note = null): JobOutsideWork
    {
        if ($work->status !== JobOutsideWork::SENT) {
            throw new JobSheetException('This work is not waiting for you.');
        }
        // The washing points of the job (Washing & Cleaning) must all be filled, with their photos / videos
        $open = $this->partnerItems($work)->filter(fn (JobCardChecklistItem $i) => $i->status === JobCardChecklistItem::STATUS_PENDING);
        if ($open->isNotEmpty()) {
            throw new JobSheetException('Finish every point first: ' . $open->pluck('item_name')->implode(', ') . '.');
        }
        if ($this->partnerItems($work)->isEmpty() && !$work->media()->exists()) {
            throw new JobSheetException('Add at least one photo or video of the work first.');
        }

        $work->update(['status' => JobOutsideWork::DONE, 'done_at' => now(), 'partner_note' => $note]);

        $jobCard = $work->jobCard;
        $ids = collect([$jobCard->technician_id, $jobCard->service_advisor_id, $work->sent_by])
            ->merge($jobCard->technicians()->pluck('users.id'))
            ->filter()->unique();
        Notification::send(User::whereIn('id', $ids)->get(), StaffAlert::outsideWorkDone($jobCard, $work));

        return $work;
    }

    /**
     * The car is back at the workshop (the technician then finishes the section and submits).
     */
    public function returned(JobOutsideWork $work, User $user): JobOutsideWork
    {
        if (!$work->isOpen()) {
            throw new JobSheetException('This outside work is already closed.');
        }

        $work->update(['status' => JobOutsideWork::RETURNED, 'returned_by' => $user->id, 'returned_at' => now()]);

        return $work;
    }

    public function cancel(JobOutsideWork $work, User $user): JobOutsideWork
    {
        if (!$work->isOpen()) {
            throw new JobSheetException('This outside work is already closed.');
        }

        DB::transaction(function () use ($work) {
            $work->update(['status' => JobOutsideWork::CANCELLED, 'cancelled_at' => now()]);
        });

        return $work;
    }

    /**
     * The job-sheet points the partner fills (the Washing & Cleaning section of the job).
     *
     * @return \Illuminate\Support\Collection<int, JobCardChecklistItem>
     */
    public function partnerItems(JobOutsideWork $work): \Illuminate\Support\Collection
    {
        return JobCardChecklistItem::with('media')
            ->whereHas('jobCardChecklist', fn ($q) => $q->where('job_card_id', $work->job_card_id))
            ->where('outside_kind', $work->kind)
            ->orderBy('sort_order')
            ->get();
    }

    public function open(JobCard $jobCard, ?string $kind = null): ?JobOutsideWork
    {
        return JobOutsideWork::where('job_card_id', $jobCard->id)
            ->whereIn('status', JobOutsideWork::OPEN)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->first();
    }

    /**
     * WhatsApp text for the partner's own phone (the advisor / technician sends it).
     */
    public function partnerShareUrl(JobOutsideWork $work): ?string
    {
        $phone = ShareLinkChannel::normalizePhone($work->partner?->phone);
        if (!$phone) {
            return null;
        }
        $jobCard = $work->jobCard()->with('vehicle.make:id,name', 'vehicle.model:id,name')->first();
        $car = trim(implode(' ', array_filter([$jobCard?->vehicle?->make?->name, $jobCard?->vehicle?->model?->name])));
        $text = "{$work->kindLabel()}: {$jobCard?->vehicle?->registration_no} {$car} (job {$jobCard?->job_number})."
            . ($work->note ? " {$work->note}" : '')
            . ' Please add the photos and videos in the EA Workshop app and tap Done.';

        return (new ShareLinkChannel())->shareUrl($phone, $text);
    }

    public function present(JobOutsideWork $work, bool $forPartner = false): array
    {
        $work->loadMissing(['partner:id,name,phone,kind', 'media', 'sentBy:id,name', 'returnedBy:id,name']);
        $job = $work->jobCard()->with('vehicle.make:id,name', 'vehicle.model:id,name')->first();

        return [
            'id' => $work->id,
            'kind' => $work->kind,
            'kind_label' => $work->kindLabel(),
            'status' => $work->status,
            'note' => $work->note,
            'partner' => $work->partner ? ['id' => $work->partner->id, 'name' => $work->partner->name, 'phone' => $forPartner ? null : $work->partner->phone] : null,
            'partner_note' => $work->partner_note,
            'sent_by' => $work->sentBy?->name,
            'sent_at' => $work->sent_at?->toIso8601String(),
            'done_at' => $work->done_at?->toIso8601String(),
            'returned_by' => $work->returnedBy?->name,
            'returned_at' => $work->returned_at?->toIso8601String(),
            // The partner sees the car, never the customer or prices
            'job' => $job ? [
                'id' => $job->id,
                'job_number' => $job->job_number,
                'registration_no' => $job->vehicle?->registration_no,
                'car' => trim(implode(' ', array_filter([$job->vehicle?->make?->name, $job->vehicle?->model?->name]))),
            ] : null,
            'media' => $work->media->map(fn (JobCardMedia $m) => [
                'id' => $m->id,
                'media_type' => $m->media_type,
                'url' => $m->url(),
                'thumbnail_url' => $m->url(true),
            ])->values(),
            'partner_whatsapp_url' => $forPartner ? null : $this->partnerShareUrl($work),
        ];
    }
}

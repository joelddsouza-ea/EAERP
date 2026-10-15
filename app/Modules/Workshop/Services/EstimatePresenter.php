<?php

namespace App\Modules\Workshop\Services;

use App\Core\Support\IndianCurrency;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateLine as Line;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Models\JobCardObservation;

/**
 * Array shape of an estimate for the staff screens, the customer page and the PDF.
 * The customer view leaves out internal fields and only shows customer-visible media.
 */
class EstimatePresenter
{
    public function __construct(protected EstimateCalculator $calculator) {}

    public function present(JobCardEstimate $estimate, bool $forCustomer = false): array
    {
        $estimate->loadMissing(['lines.media', 'lines.decidedBy:id,name', 'comments.user:id,name', 'sentBy:id,name']);

        $plan = $estimate->lines->where('section', Line::SECTION_PLAN)->values();
        $additional = $estimate->lines->where('section', Line::SECTION_ADDITIONAL)->values();
        $extrasBase = $additional->where('approval_status', '!=', Line::DECLINED)->sum(fn (Line $l) => (float) $l->amount);

        $data = [
            'id' => $estimate->id,
            'job_card_id' => $estimate->job_card_id,
            'version' => $estimate->version,
            'status' => $estimate->status,
            'package_id' => $estimate->package_id,
            'category_id' => $estimate->category_id,
            'oil_type_id' => $estimate->oil_type_id,
            'package_name' => $estimate->package_name,
            'category_name' => $estimate->category_name,
            'oil_type_name' => $estimate->oil_type_name,
            'package_price' => (float) $estimate->package_price,
            'plan_lines' => $plan->map(fn (Line $l) => $this->line($l, $forCustomer))->all(),
            'additional_lines' => $additional->map(fn (Line $l) => $this->line($l, $forCustomer))->all(),
            'plan_discount_pct' => (float) $estimate->plan_discount_pct,
            'plan_discount_flat' => (float) $estimate->plan_discount_flat,
            'plan_discount' => round((float) $estimate->package_price - (float) $estimate->plan_total, 2),
            'plan_total' => (float) $estimate->plan_total,
            'extras_discount_pct' => (float) $estimate->extras_discount_pct,
            'extras_discount_flat' => (float) $estimate->extras_discount_flat,
            'extras_subtotal' => round($extrasBase, 2),
            'extras_discount' => round($extrasBase - (float) $estimate->extras_total, 2),
            'extras_total' => (float) $estimate->extras_total,
            'grand_total' => (float) $estimate->grand_total,
            'grand_total_words' => IndianCurrency::inWords((float) $estimate->grand_total),
            'pending_count' => $additional->where('approval_status', Line::PENDING)->count(),
            'next_service_km' => $estimate->next_service_km,
            'next_service_date' => $estimate->next_service_date?->toDateString(),
            'payment_mode' => $estimate->payment_mode,
            'notes' => $estimate->notes,
            'sent_at' => $estimate->sent_at?->toIso8601String(),
            'customer_viewed_at' => $estimate->customer_viewed_at?->toIso8601String(),
            'responded_at' => $estimate->responded_at?->toIso8601String(),
            'declined_label' => Line::DECLINED_LABEL,
            // Observations & recommendations with their remarks; work the customer did not approve
            // is listed here (not under Additional Parts & Labour)
            'observations' => JobCardObservation::with('remark')
                ->where('job_card_id', $estimate->job_card_id)
                ->when($forCustomer, fn ($q) => $q->where('customer_visible', true))
                ->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(fn (JobCardObservation $o) => [
                    'id' => $o->id,
                    'text' => $o->text,
                    'source' => $o->source,
                    'remark' => $o->remark?->text($o->remark_days),
                    'estimate_line_key' => $o->estimate_line_key,
                ])->all(),
            'comments' => $estimate->comments->map(fn ($c) => [
                'id' => $c->id,
                'author_type' => $c->author_type,
                'author' => $c->author_type === 'customer' ? null : $c->user?->name,
                'body' => $c->body,
                'created_at' => $c->created_at?->toIso8601String(),
            ])->all(),
        ];

        if (!$forCustomer) {
            $data['sent_by'] = $estimate->sentBy?->only(['id', 'name']);
            $data['warnings'] = $this->calculator->warnings($estimate);
        }

        return $data;
    }

    protected function line(Line $line, bool $forCustomer): array
    {
        $media = $line->media->when($forCustomer, fn ($m) => $m->where('customer_visible', true));

        $data = [
            'id' => $line->id,
            'line_key' => $line->line_key,
            'section' => $line->section,
            'kind' => $line->kind,
            'description' => $line->description,
            'quantity' => (float) $line->quantity,
            'rate' => (float) $line->rate,
            'amount' => (float) $line->amount,
            'approval_status' => $line->approval_status,
            'approval_label' => match ($line->approval_status) {
                Line::INCLUDED => 'Included in plan',
                Line::PENDING => 'Waiting for approval',
                Line::APPROVED => 'Approved',
                Line::DECLINED => Line::DECLINED_LABEL,
                default => $line->approval_status,
            },
            'decided_via' => $line->decided_via,
            'decided_by' => $line->decidedBy?->name,
            'decided_at' => $line->decided_at?->toIso8601String(),
            'media' => $media->map(fn (JobCardMedia $m) => [
                'id' => $m->id,
                'media_type' => $m->media_type,
                'description' => $m->description,
                'url' => $m->url(),
                'thumbnail_url' => $m->thumbnail_path ? $m->url(true) : null,
            ])->values()->all(),
        ];

        if (!$forCustomer) {
            $data['job_card_additional_job_id'] = $line->job_card_additional_job_id;
            $data['job_card_issue_id'] = $line->job_card_issue_id;
            // Workshop-only: never part of the customer's view or PDF
            $data['labour_service_id'] = $line->labour_service_id;
            $data['spare_part_request_id'] = $line->spare_part_request_id;
            $data['unit_cost'] = $line->unit_cost === null ? null : (float) $line->unit_cost;
            $data['margin_pct'] = $line->margin_pct === null ? null : (float) $line->margin_pct;
        }

        return $data;
    }
}

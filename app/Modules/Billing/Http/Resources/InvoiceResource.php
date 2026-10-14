<?php

namespace App\Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status,

            // Customer
            'customer' => $this->whenLoaded('customer', fn() => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'email' => $this->customer->email,
                'gstin' => $this->customer->gstin,
                'address' => $this->customer->address,
            ]),

            // Job Card
            'job_card' => $this->whenLoaded('jobCard', fn() => [
                'id' => $this->jobCard->id,
                'job_number' => $this->jobCard->job_number,
                'vehicle' => $this->jobCard->vehicle ? [
                    'registration_no' => $this->jobCard->vehicle->registration_no,
                    'full_name' => $this->jobCard->vehicle->full_name,
                ] : null,
            ]),

            // Branch
            'branch' => $this->whenLoaded('branch', fn() => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
                'gstin' => $this->branch->gstin,
                'address' => $this->branch->address,
            ]),

            // Amounts
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'discount_type' => $this->discount_type,
            'taxable_amount' => $this->taxable_amount,
            'cgst' => $this->cgst,
            'sgst' => $this->sgst,
            'igst' => $this->igst,
            'total_tax' => $this->total_tax,
            'total_amount' => $this->total_amount,
            'paid_amount' => $this->paid_amount,
            'balance_amount' => $this->balance_amount,

            // GST
            'is_igst' => $this->is_igst,
            'place_of_supply' => $this->place_of_supply,

            // Notes
            'notes' => $this->notes,
            'terms' => $this->terms,

            // Items
            'items' => $this->whenLoaded('items', fn() =>
                $this->items->map(fn($item) => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'hsn_code' => $item->hsn_code,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'discount' => $item->discount,
                    'taxable_amount' => $item->taxable_amount,
                    'tax_rate' => $item->tax_rate,
                    'cgst' => $item->cgst,
                    'sgst' => $item->sgst,
                    'igst' => $item->igst,
                    'total' => $item->total,
                ])
            ),

            // Payments
            'payments' => $this->whenLoaded('payments', fn() =>
                $this->payments->map(fn($payment) => [
                    'id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'amount' => $payment->amount,
                    'method' => $payment->method,
                    'payment_date' => $payment->payment_date->toDateString(),
                    'reference_number' => $payment->reference_number,
                ])
            ),

            // Tracking
            'created_by' => $this->whenLoaded('createdBy', fn() => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}

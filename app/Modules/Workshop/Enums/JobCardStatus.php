<?php

namespace App\Modules\Workshop\Enums;

/**
 * Job card lifecycle statuses (Customer Car Life Cycle + Plan & Spec workflow).
 *
 * Stored as a string column so later phases can enable statuses without schema changes;
 * statuses not yet backed by features are listed in config('workshop.workflow.disabled_statuses').
 */
enum JobCardStatus: string
{
    case PickupScheduled = 'pickup_scheduled';
    case PickupEnRoute = 'pickup_en_route';
    case VehicleCollected = 'vehicle_collected';
    case IntakePending = 'intake_pending';
    case IntakeInProgress = 'intake_in_progress';
    case AwaitingApproval = 'awaiting_approval';
    case WorkInProgress = 'work_in_progress';
    case AwaitingParts = 'awaiting_parts';
    case PostWorkTrial = 'post_work_trial';
    case WashPending = 'wash_pending';
    case QcPending = 'qc_pending';
    case ReadyForDelivery = 'ready_for_delivery';
    case Invoiced = 'invoiced';
    case GatePassGenerated = 'gate_pass_generated';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PickupScheduled => 'Pickup Scheduled',
            self::PickupEnRoute => 'Pickup En Route',
            self::VehicleCollected => 'Vehicle Collected',
            self::IntakePending => 'Intake Pending',
            self::IntakeInProgress => 'Intake In Progress',
            self::AwaitingApproval => 'Awaiting Approval',
            self::WorkInProgress => 'Work In Progress',
            self::AwaitingParts => 'Awaiting Parts',
            self::PostWorkTrial => 'Trial Drive',
            self::WashPending => 'Wash Pending',
            self::QcPending => 'Final QC',
            self::ReadyForDelivery => 'Ready For Delivery',
            self::Invoiced => 'Invoiced',
            self::GatePassGenerated => 'Gate Pass Generated',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Coarse grouping used by dashboards.
     */
    public function group(): string
    {
        return match ($this) {
            self::PickupScheduled, self::PickupEnRoute, self::VehicleCollected => 'pickup',
            self::IntakePending, self::IntakeInProgress => 'intake',
            self::AwaitingApproval, self::WorkInProgress, self::AwaitingParts => 'work',
            self::PostWorkTrial, self::WashPending, self::QcPending => 'qc',
            self::ReadyForDelivery, self::Invoiced, self::GatePassGenerated => 'delivery',
            self::Delivered, self::Cancelled => 'closed',
        };
    }

    /**
     * @return string[]
     */
    public static function terminal(): array
    {
        return [self::Delivered->value, self::Cancelled->value];
    }

    /**
     * Statuses from which an invoice may be raised.
     *
     * @return string[]
     */
    public static function invoiceable(): array
    {
        return [self::ReadyForDelivery->value, self::Invoiced->value];
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

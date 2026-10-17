<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Crm\Models\Booking;
use App\Modules\Crm\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends ApiController
{
    public function __construct(protected BookingService $bookings) {}

    /**
     * A day (or a week) of bookings for a branch, with the slots and how full they are.
     */
    public function index(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->toDateString());
        $to = $request->query('to', $from);
        $branchId = $request->integer('branch_id') ?: app('branch.context')->getCurrentBranchId();

        $rows = Booking::query()
            ->whereBetween('booking_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('booking_date')
            ->orderBy('slot_from')
            ->get();

        return $this->success([
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'rows' => $rows->map(fn ($b) => $this->bookings->present($b))->values(),
            'slots' => $branchId && $from === $to ? $this->bookings->availability($branchId, $from) : [],
            'pending_confirmation' => Booking::where('status', Booking::BOOKED)->where('booked_via', '!=', 'staff')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->count(),
        ]);
    }

    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => 'nullable|integer|exists:branches,id', 'date' => 'required|date']);
        $branchId = (int) ($data['branch_id'] ?? app('branch.context')->getCurrentBranchId());

        return $this->success($this->bookings->availability($branchId, $data['date']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_id' => 'nullable|integer|exists:leads,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'booking_date' => 'required|date',
            'slot_from' => 'nullable|date_format:H:i',
            'service_type' => 'nullable|string|max:30',
            'package_id' => 'nullable|integer|exists:service_packages,id',
            'oil_type_id' => 'nullable|integer|exists:oil_types,id',
            'service_mode' => 'nullable|in:drive_in,pickup_drop,home',
            'address' => 'nullable|string|max:1000',
            'landmark' => 'nullable|string|max:255',
            'maps_url' => 'nullable|url|max:500',
            'notes' => 'nullable|string|max:1000',
        ]);

        $booking = $this->bookings->create($data, $request->user());

        return $this->created($this->bookings->present($booking), "Booking {$booking->number} made" . ($booking->job_card_id ? ' — job card opened for the pickup' : ''));
    }

    public function confirm(Request $request, Booking $booking): JsonResponse
    {
        $booking = $this->bookings->confirm($booking, $request->user());

        return $this->success($this->bookings->present($booking), 'Booking confirmed');
    }

    public function reschedule(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate(['booking_date' => 'required|date', 'slot_from' => 'nullable|date_format:H:i']);

        return $this->success($this->bookings->present($this->bookings->reschedule($booking, $data['booking_date'], $data['slot_from'] ?? null, $request->user())), 'Booking moved');
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        return $this->success($this->bookings->present($this->bookings->cancel($booking, $data['reason'], $request->user())), 'Booking cancelled');
    }

    public function noShow(Request $request, Booking $booking): JsonResponse
    {
        return $this->success($this->bookings->present($this->bookings->noShow($booking, $request->user())), 'Marked as did not come');
    }

    /**
     * The car is here: customer and car are made from the lead if needed, and the job card
     * form opens filled in.
     */
    public function arrive(Request $request, Booking $booking): JsonResponse
    {
        $car = $request->validate([
            'registration_no' => 'nullable|string|max:20',
            'make_id' => 'nullable|integer|exists:vehicle_makes,id',
            'model_id' => 'nullable|integer|exists:vehicle_models,id',
            'category_id' => 'nullable|integer|exists:vehicle_categories,id',
            'fuel_type' => 'nullable|string|max:20',
        ]);

        return $this->success($this->bookings->prefill($booking, $car), 'Fill in the intake to open the job card');
    }
}

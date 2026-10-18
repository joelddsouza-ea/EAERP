<?php

namespace App\Modules\CustomerApp\Services;

use App\Core\Models\Branch;
use App\Core\Support\Modules;
use App\Modules\Crm\Models\Booking;
use App\Modules\Crm\Services\BookingService;
use App\Modules\Crm\Services\CrmException;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\PackagePricing;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Models\VehicleMake;
use App\Modules\Workshop\Models\VehicleModel;

/**
 * Book in three taps from the app: the car → a plan at that car's prices (or a repair type)
 * → drive-in / pickup / home and a free slot. Uses the CRM's bookings (slots, desk alerts,
 * confirmation message), so it needs the CRM module.
 */
class CustomerBookingService
{
    public const REPAIR_TYPES = [
        'general_repair' => 'General / mechanical repair',
        'ac_service' => 'AC service & repair',
        'electrical' => 'Electrical',
        'body_shop' => 'Body shop / accident repair',
        'detailing' => 'Detailing',
    ];

    public function __construct(protected BookingService $bookings) {}

    public function available(): bool
    {
        return Modules::crm();
    }

    /**
     * Plans with this car's prices (lowest oil grade first), oil grades and repair types.
     */
    public function options(Customer $customer, ?int $vehicleId): array
    {
        $vehicle = $vehicleId ? Vehicle::where('customer_id', $customer->id)->find($vehicleId) : null;
        $categoryId = $vehicle?->category_id;

        $prices = PackagePricing::query()
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->where('price', '>', 0)
            ->get(['package_id', 'oil_type_id', 'price']);
        $oils = OilType::whereIn('id', $prices->pluck('oil_type_id')->filter()->unique())->get(['id', 'name'])->keyBy('id');

        $plans = ServicePackage::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->map(function (ServicePackage $p) use ($prices, $oils, $categoryId) {
                $rows = $prices->where('package_id', $p->id)->sortBy('price');
                if ($rows->isEmpty()) {
                    return null;
                }

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'description' => $p->description,
                    'activity_points' => $p->activity_points,
                    'home_service' => (bool) $p->home_service_available,
                    // Exact for the car's type; "from" when the car type isn't known
                    'price_from' => (float) $rows->first()->price,
                    'exact' => (bool) $categoryId,
                    'oils' => $categoryId ? $rows->map(fn ($r) => ['id' => $r->oil_type_id, 'name' => $oils[$r->oil_type_id]->name ?? 'Standard', 'price' => (float) $r->price])->values() : [],
                ];
            })->filter()->values();

        return [
            'available' => $this->available(),
            'vehicle' => $vehicle ? ['id' => $vehicle->id, 'registration_no' => $vehicle->registration_no, 'category_known' => (bool) $categoryId] : null,
            'plans' => $plans,
            'repair_types' => collect(self::REPAIR_TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'modes' => [
                ['key' => JobCard::MODE_DRIVE_IN, 'label' => 'I will bring the car'],
                ['key' => JobCard::MODE_PICKUP_DROP, 'label' => 'Pick up & drop my car'],
                ['key' => JobCard::MODE_HOME, 'label' => 'Service at my home'],
            ],
            'branches' => Branch::active()->orderBy('id')->get(['id', 'name', 'address', 'city'])->map(fn ($b) => $b->only(['id', 'name', 'address', 'city']))->values(),
            'default_branch_id' => $this->lastBranch($customer),
            'address' => $customer->address,
        ];
    }

    public function slots(int $branchId, string $date): array
    {
        return $this->bookings->availability($branchId, $date)->values()->all();
    }

    public function book(Customer $customer, array $data): Booking
    {
        if (!$this->available()) {
            throw new CustomerAppException('Booking in the app is not open right now. Please call us to book.');
        }
        $vehicle = Vehicle::where('customer_id', $customer->id)->findOrFail($data['vehicle_id']);

        try {
            return $this->bookings->create([
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'branch_id' => $data['branch_id'] ?? $this->lastBranch($customer) ?? Branch::active()->orderBy('id')->value('id'),
                'booking_date' => $data['booking_date'],
                'slot_from' => $data['slot_from'] ?? null,
                'service_type' => !empty($data['package_id']) ? 'scheduled' : ($data['service_type'] ?? 'general_repair'),
                'package_id' => $data['package_id'] ?? null,
                'oil_type_id' => $data['oil_type_id'] ?? null,
                'service_mode' => $data['service_mode'],
                'address' => $data['address'] ?? null,
                'landmark' => $data['landmark'] ?? null,
                'maps_url' => $data['maps_url'] ?? null,
                'notes' => isset($data['notes']) ? mb_substr((string) $data['notes'], 0, 1000) : null,
            ], null, 'app');
        } catch (CrmException $e) {
            throw new CustomerAppException($e->getMessage(), $e->errors);
        }
    }

    public function list(Customer $customer): array
    {
        return Booking::with(['vehicle:id,registration_no', 'package:id,name', 'branch:id,name'])
            ->where('customer_id', $customer->id)->latest('booking_date')->limit(30)->get()
            ->map(fn (Booking $b) => [
                'id' => $b->id,
                'number' => $b->number,
                'date' => $b->booking_date->toDateString(),
                'slot' => $b->slot_from ? substr($b->slot_from, 0, 5) . '–' . substr((string) $b->slot_to, 0, 5) : null,
                'status' => $b->status,
                'status_label' => Booking::STATUSES[$b->status] ?? $b->status,
                'vehicle' => $b->vehicle?->registration_no,
                'work' => $b->package?->name ?? (self::REPAIR_TYPES[$b->service_type] ?? ucwords(str_replace('_', ' ', (string) $b->service_type))),
                'mode' => $b->service_mode,
                'branch' => $b->branch?->name,
                'can_cancel' => in_array($b->status, [Booking::BOOKED, Booking::CONFIRMED], true) && $b->booking_date->gte(today()),
                'job_card_id' => $b->job_card_id,
            ])->values()->all();
    }

    public function cancel(Customer $customer, int $bookingId, ?string $reason): Booking
    {
        $booking = Booking::where('customer_id', $customer->id)->findOrFail($bookingId);
        if (!in_array($booking->status, [Booking::BOOKED, Booking::CONFIRMED], true)) {
            throw new CustomerAppException('This booking can no longer be cancelled here. Please call us.');
        }

        return $this->bookings->cancel($booking, 'Cancelled by the customer in the app' . ($reason ? ": {$reason}" : ''), null);
    }

    /**
     * Add a car to the customer's garage.
     */
    public function addVehicle(Customer $customer, array $data): Vehicle
    {
        $reg = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $data['registration_no']));
        if (Vehicle::where('registration_no', $reg)->exists()) {
            throw new CustomerAppException('This car is already registered with us. Please call us to link it to your number.', ['registration_no' => ['Already registered.']]);
        }
        $model = !empty($data['model_id']) ? VehicleModel::find($data['model_id']) : null;

        return Vehicle::create([
            'customer_id' => $customer->id,
            'make_id' => $data['make_id'] ?? $model?->make_id,
            'model_id' => $model?->id,
            'category_id' => $model?->default_category_id,
            'registration_no' => $reg,
            'year' => $data['year'] ?? null,
            'fuel_type' => $data['fuel_type'] ?? null,
            'is_active' => true,
        ]);
    }

    public function makes(): array
    {
        return VehicleMake::orderBy('name')->get(['id', 'name'])->map(fn ($m) => [
            'id' => $m->id,
            'name' => $m->name,
        ])->values()->all();
    }

    public function models(int $makeId): array
    {
        return VehicleModel::where('make_id', $makeId)->orderBy('name')->get(['id', 'name'])->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values()->all();
    }

    protected function lastBranch(Customer $customer): ?int
    {
        return JobCard::withoutGlobalScopes()->where('customer_id', $customer->id)->latest('id')->value('branch_id');
    }
}

<?php

namespace App\Modules\Fleet\Services;

use App\Core\Models\User;
use App\Core\Services\WhatsApp\ShareLinkChannel;
use App\Modules\CustomerApp\Models\CustomerSession;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\Vehicle;

/**
 * A fleet's drivers and the cabs they drive. The fleet owner manages them in the app,
 * staff from the customer page. A driver is never deleted (their approvals and reports
 * keep the name): they are switched off, which signs them out.
 */
class FleetDriverService
{
    /**
     * @return array<int, array>
     */
    public function list(Customer $fleet): array
    {
        $drivers = FleetDriver::with('vehicles:id,registration_no')->where('customer_id', $fleet->id)
            ->orderByDesc('is_active')->orderBy('name')->get();
        $lastSeen = CustomerSession::whereIn('fleet_driver_id', $drivers->pluck('id'))
            ->selectRaw('fleet_driver_id, MAX(last_used_at) AS seen')->groupBy('fleet_driver_id')->pluck('seen', 'fleet_driver_id');

        return $drivers->map(fn (FleetDriver $d) => $this->present($d) + [
            'last_seen_at' => isset($lastSeen[$d->id]) ? \Illuminate\Support\Carbon::parse($lastSeen[$d->id])->toIso8601String() : null,
        ])->values()->all();
    }

    public function present(FleetDriver $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'phone' => $d->phone,
            'is_active' => $d->is_active,
            'notes' => $d->notes,
            'created_via' => $d->created_via,
            'vehicles' => $d->vehicles->map(fn (Vehicle $v) => ['id' => $v->id, 'registration_no' => $v->registration_no])->values()->all(),
            'vehicle_ids' => $d->vehicles->pluck('id')->values()->all(),
        ];
    }

    /**
     * Add or change a driver: name, mobile, notes, cabs (all of them must be the fleet's).
     *
     * @param array{name: string, phone: string, notes?: ?string, vehicle_ids?: array<int>, is_active?: bool} $data
     */
    public function save(Customer $fleet, array $data, ?FleetDriver $driver = null, string $via = FleetDriver::VIA_STAFF, ?User $user = null): FleetDriver
    {
        if (!$fleet->is_fleet) {
            throw new FleetException('Mark this customer as a fleet first.');
        }
        if ($driver && $driver->customer_id !== $fleet->id) {
            throw new FleetException('That driver is not with this fleet.', [], 404);
        }

        $phone = ShareLinkChannel::normalizePhone((string) $data['phone']);
        if (!$phone) {
            throw new FleetException('Enter a valid 10-digit mobile number.', ['phone' => ['Not a valid mobile number.']]);
        }
        $ten = substr($phone, -10);
        $clash = FleetDriver::where('customer_id', $fleet->id)->where('is_active', true)
            ->when($driver, fn ($q) => $q->whereKeyNot($driver->id))
            ->get(['id', 'phone'])->first(fn ($d) => substr((string) ShareLinkChannel::normalizePhone($d->phone), -10) === $ten);
        if ($clash) {
            throw new FleetException('A driver with this mobile number is already on the fleet.', ['phone' => ['Already a driver.']]);
        }

        $vehicleIds = array_values(array_unique(array_map('intval', $data['vehicle_ids'] ?? [])));
        if ($vehicleIds && Vehicle::where('customer_id', $fleet->id)->whereIn('id', $vehicleIds)->count() !== count($vehicleIds)) {
            throw new FleetException('Choose cabs of this fleet only.', ['vehicle_ids' => ['Not a cab of this fleet.']]);
        }

        $attributes = [
            'name' => trim((string) $data['name']),
            'phone' => $ten,
            'notes' => isset($data['notes']) ? (trim((string) $data['notes']) ?: null) : $driver?->notes,
        ];
        if ($driver) {
            $driver->update($attributes);
        } else {
            $driver = FleetDriver::create($attributes + [
                'customer_id' => $fleet->id,
                'is_active' => true,
                'created_via' => $via,
                'created_by' => $user?->id,
            ]);
        }
        if (array_key_exists('vehicle_ids', $data)) {
            $driver->vehicles()->sync($vehicleIds);
        }
        if (array_key_exists('is_active', $data)) {
            $this->setActive($driver, (bool) $data['is_active']);
        }

        return $driver->load('vehicles:id,registration_no');
    }

    public function setActive(FleetDriver $driver, bool $active): FleetDriver
    {
        $driver->update(['is_active' => $active]);
        if (!$active) {
            $this->signOut($driver);
        }

        return $driver;
    }

    /**
     * Sign the driver out on every phone (e.g. a lost phone, or they left).
     */
    public function signOut(FleetDriver $driver): int
    {
        return CustomerSession::where('fleet_driver_id', $driver->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}

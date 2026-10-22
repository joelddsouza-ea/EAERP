<?php

namespace App\Modules\CustomerApp\Support;

use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\Vehicle;

/**
 * Who is using the customer app: the customer, or one of a fleet's drivers (then the customer
 * is the fleet). A driver sees only the cabs linked to them, and none of the owner's screens
 * (wallet, adding cars, drivers, fleet dashboard).
 */
class AppViewer
{
    /** @var array<int>|null */
    protected ?array $vehicleIds = null;

    public function __construct(public readonly Customer $customer, public readonly ?FleetDriver $driver = null) {}

    public function isDriver(): bool
    {
        return $this->driver !== null;
    }

    public function isFleetOwner(): bool
    {
        return !$this->driver && $this->customer->is_fleet && \App\Core\Support\Modules::fleet();
    }

    public function driverId(): ?int
    {
        return $this->driver?->id;
    }

    /**
     * The cars this viewer may see: null = all of the customer's.
     *
     * @return array<int>|null
     */
    public function vehicleIds(): ?array
    {
        if (!$this->driver) {
            return null;
        }

        return $this->vehicleIds ??= $this->driver->vehicles()->where('vehicles.customer_id', $this->customer->id)->pluck('vehicles.id')->all();
    }

    public function canSeeVehicle(?int $vehicleId): bool
    {
        $ids = $this->vehicleIds();

        return $ids === null || ($vehicleId !== null && in_array($vehicleId, $ids, true));
    }

    /**
     * Narrow a query on a table with vehicle_id to the viewer's cars.
     */
    public function scope($query, string $column = 'vehicle_id')
    {
        $ids = $this->vehicleIds();

        return $ids === null ? $query : $query->whereIn($column, $ids ?: [0]);
    }

    public function present(): array
    {
        return [
            'role' => $this->driver ? 'driver' : ($this->isFleetOwner() ? 'fleet_owner' : 'customer'),
            'driver' => $this->driver ? ['id' => $this->driver->id, 'name' => $this->driver->name, 'phone' => $this->driver->phone] : null,
            'fleet_name' => $this->customer->is_fleet ? ($this->customer->company_name ?: $this->customer->name) : null,
        ];
    }

    public function vehicle(int $id): Vehicle
    {
        abort_unless($this->canSeeVehicle($id), 404);

        return Vehicle::where('customer_id', $this->customer->id)->findOrFail($id);
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardItem;
use App\Modules\Workshop\Models\JobCardAddon;
use App\Modules\Workshop\Models\JobCardPhoto;
use App\Modules\Workshop\Models\JobCardStatusHistory;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\SparePartRequest;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\Bay;
use App\Modules\Workshop\Models\ChecklistTemplate;
use App\Modules\Workshop\Models\ChecklistItem;
use App\Modules\Workshop\Models\ChecklistSection;
use App\Modules\Workshop\Models\ServiceAddon;
use App\Core\Models\Branch;
use App\Core\Models\User;
use Carbon\Carbon;

class JobCardsSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();
        $packages = ServicePackage::all();
        $oilTypes = OilType::all();
        $vehicles = Vehicle::with('customer')->get();

        // Get users by role
        $advisors = User::whereHas('roles', fn($q) => $q->whereIn('slug', ['service-advisor', 'owner', 'manager']))->get();
        $technicians = User::whereHas('roles', fn($q) => $q->whereIn('slug', ['technician', 'service-advisor']))->get();

        if ($vehicles->isEmpty() || $packages->isEmpty()) {
            $this->command->info('Please run CustomersVehiclesSeeder and ServicePackagesSeeder first');
            return;
        }

        $statuses = ['draft', 'received', 'in_progress', 'qc', 'ready', 'delivered'];
        $serviceTypes = ['scheduled', 'general_repair', 'ac_service', 'detailing'];

        $jobCardData = [
            // Recent jobs in various statuses
            [
                'status' => 'delivered',
                'days_ago' => 15,
                'service_type' => 'scheduled',
                'complaints' => 'Regular 10,000 km service due',
            ],
            [
                'status' => 'delivered',
                'days_ago' => 12,
                'service_type' => 'scheduled',
                'complaints' => 'Annual maintenance service',
            ],
            [
                'status' => 'delivered',
                'days_ago' => 10,
                'service_type' => 'ac_service',
                'complaints' => 'AC not cooling properly',
            ],
            [
                'status' => 'delivered',
                'days_ago' => 8,
                'service_type' => 'scheduled',
                'complaints' => 'First free service',
            ],
            [
                'status' => 'delivered',
                'days_ago' => 5,
                'service_type' => 'scheduled',
                'complaints' => 'Oil change and general checkup',
            ],
            [
                'status' => 'ready',
                'days_ago' => 1,
                'service_type' => 'detailing',
                'complaints' => 'Full service with detailing',
            ],
            [
                'status' => 'qc',
                'days_ago' => 0,
                'service_type' => 'scheduled',
                'complaints' => 'Regular service and brake check',
            ],
            [
                'status' => 'in_progress',
                'days_ago' => 0,
                'service_type' => 'general_repair',
                'complaints' => 'Engine noise and vibration',
            ],
            [
                'status' => 'in_progress',
                'days_ago' => 0,
                'service_type' => 'scheduled',
                'complaints' => 'Periodic maintenance',
            ],
            [
                'status' => 'received',
                'days_ago' => 0,
                'service_type' => 'scheduled',
                'complaints' => 'Oil change required',
            ],
            [
                'status' => 'draft',
                'days_ago' => 0,
                'service_type' => 'scheduled',
                'complaints' => 'Pre-purchase inspection',
            ],
        ];

        $jobNumber = 1;

        foreach ($jobCardData as $index => $data) {
            $vehicle = $vehicles[$index % $vehicles->count()];
            $branch = $branches[$index % $branches->count()];
            $package = $packages[$index % $packages->count()];
            $oilType = $oilTypes[$index % $oilTypes->count()];
            $advisor = $advisors[$index % $advisors->count()];
            $technician = $technicians[$index % $technicians->count()];
            $bay = Bay::where('branch_id', $branch->id)->inRandomOrder()->first();

            $createdAt = Carbon::now()->subDays($data['days_ago']);
            $timeIn = $createdAt->copy()->setHour(rand(9, 14))->setMinute(rand(0, 59));

            $jobCard = JobCard::create([
                'branch_id' => $branch->id,
                'job_number' => 'JC' . str_pad($jobNumber++, 6, '0', STR_PAD_LEFT),
                'customer_id' => $vehicle->customer_id,
                'vehicle_id' => $vehicle->id,
                'package_id' => $package->id,
                'oil_type_id' => $oilType->id,
                'service_advisor_id' => $advisor->id,
                'technician_id' => in_array($data['status'], ['in_progress', 'qc', 'ready', 'delivered']) ? $technician->id : null,
                'bay_id' => in_array($data['status'], ['in_progress', 'qc', 'ready', 'delivered']) ? $bay?->id : null,
                'status' => $data['status'],
                'service_type' => $data['service_type'],
                'priority' => $index % 3 === 0 ? 'high' : ($index % 2 === 0 ? 'urgent' : 'normal'),
                'customer_complaints' => $data['complaints'],
                'advisor_observations' => in_array($data['status'], ['qc', 'ready', 'delivered']) ? 'All systems checked. ' . $this->getRandomObservation() : null,
                'time_in' => $timeIn,
                'time_out' => $data['status'] === 'delivered' ? $timeIn->copy()->addHours(rand(3, 8)) : null,
                'estimated_delivery' => $timeIn->copy()->addHours(rand(4, 24)),
                'km_reading' => rand(10000, 80000),
                'fuel_level' => ['empty', 'quarter', 'half', 'three_quarter', 'full'][rand(0, 4)],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            // Add job card items
            $this->addJobCardItems($jobCard, $package);

            // Add addons for some jobs
            if ($index % 2 === 0) {
                $this->addJobCardAddons($jobCard);
            }

            // Add status history
            $this->addStatusHistory($jobCard, $data['status'], $advisor, $technician, $createdAt);

            // Add spare part requests for repair jobs
            if ($data['service_type'] === 'repair' || $index % 3 === 0) {
                $this->addSparePartRequests($jobCard, $advisor, $technician);
            }

            // Add checklist for in_progress and beyond
            if (in_array($data['status'], ['in_progress', 'qc', 'ready', 'delivered'])) {
                $this->addChecklists($jobCard, $package, $technician);
            }
        }
    }

    private function addJobCardItems(JobCard $jobCard, ServicePackage $package): void
    {
        // Labor charge
        JobCardItem::create([
            'job_card_id' => $jobCard->id,
            'type' => 'labor',
            'description' => $package->name . ' - Labor Charges',
            'quantity' => 1,
            'unit' => 'job',
            'unit_price' => rand(500, 2000),
            'tax_rate' => 18,
        ]);

        // Parts
        $parts = [
            ['name' => 'Engine Oil (4L)', 'price' => rand(1500, 3500)],
            ['name' => 'Oil Filter', 'price' => rand(200, 500)],
            ['name' => 'Air Filter', 'price' => rand(300, 800)],
            ['name' => 'AC Filter', 'price' => rand(400, 900)],
        ];

        foreach ($parts as $index => $part) {
            if ($index < 2 || rand(0, 1)) {
                JobCardItem::create([
                    'job_card_id' => $jobCard->id,
                    'type' => 'part',
                    'description' => $part['name'],
                    'part_number' => 'P' . rand(10000, 99999),
                    'quantity' => 1,
                    'unit' => 'nos',
                    'unit_price' => $part['price'],
                    'tax_rate' => 18,
                ]);
            }
        }
    }

    private function addJobCardAddons(JobCard $jobCard): void
    {
        $addons = ServiceAddon::inRandomOrder()->limit(rand(1, 2))->get();

        foreach ($addons as $addon) {
            JobCardAddon::create([
                'job_card_id' => $jobCard->id,
                'addon_id' => $addon->id,
                'addon_name' => $addon->name,
                'price' => $addon->price,
                'discount' => rand(0, 1) ? rand(50, 200) : 0,
                'final_price' => $addon->price - (rand(0, 1) ? rand(50, 200) : 0),
            ]);
        }
    }

    private function addStatusHistory(JobCard $jobCard, string $currentStatus, User $advisor, User $technician, Carbon $createdAt): void
    {
        $statuses = ['draft', 'received', 'in_progress', 'qc', 'ready', 'delivered'];
        $currentIndex = array_search($currentStatus, $statuses);

        $time = $createdAt->copy();

        for ($i = 0; $i <= $currentIndex; $i++) {
            JobCardStatusHistory::create([
                'job_card_id' => $jobCard->id,
                'from_status' => $i > 0 ? $statuses[$i - 1] : null,
                'to_status' => $statuses[$i],
                'notes' => $this->getStatusNote($statuses[$i]),
                'changed_by' => $i < 2 ? $advisor->id : $technician->id,
                'created_at' => $time,
            ]);

            $time->addHours(rand(1, 3));
        }
    }

    private function addSparePartRequests(JobCard $jobCard, User $advisor, User $technician): void
    {
        $parts = [
            ['name' => 'Brake Pads (Front)', 'qty' => 1],
            ['name' => 'Wiper Blades', 'qty' => 2],
            ['name' => 'Spark Plugs', 'qty' => 4],
            ['name' => 'Coolant (1L)', 'qty' => 2],
        ];

        $selectedParts = array_slice($parts, 0, rand(1, 3));
        $statuses = ['requested', 'approved', 'issued', 'received'];

        foreach ($selectedParts as $part) {
            $status = $statuses[rand(0, 3)];

            SparePartRequest::create([
                'job_card_id' => $jobCard->id,
                'part_name' => $part['name'],
                'part_number' => 'SP' . rand(10000, 99999),
                'quantity' => $part['qty'],
                'unit' => 'nos',
                'status' => $status,
                'requested_by' => $technician->id,
                'requested_at' => now(),
                'approved_by' => in_array($status, ['approved', 'issued', 'received']) ? $advisor->id : null,
                'approved_at' => in_array($status, ['approved', 'issued', 'received']) ? now() : null,
                'issued_by' => in_array($status, ['issued', 'received']) ? $advisor->id : null,
                'issued_at' => in_array($status, ['issued', 'received']) ? now() : null,
                'issued_quantity' => in_array($status, ['issued', 'received']) ? $part['qty'] : null,
            ]);
        }
    }

    private function addChecklists(JobCard $jobCard, ServicePackage $package, User $technician): void
    {
        $templates = ChecklistTemplate::where('package_id', $package->id)->get();

        if ($templates->isEmpty()) {
            $templates = ChecklistTemplate::limit(2)->get();
        }

        foreach ($templates as $template) {
            $checklist = JobCardChecklist::create([
                'job_card_id' => $jobCard->id,
                'template_id' => $template->id,
                'completed_by' => $technician->id,
                'status' => in_array($jobCard->status, ['qc', 'ready', 'delivered']) ? 'completed' : 'in_progress',
                'started_at' => $jobCard->time_in,
                'completed_at' => in_array($jobCard->status, ['qc', 'ready', 'delivered']) ? now() : null,
            ]);

            // Get checklist items through sections
            $sections = ChecklistSection::where('template_id', $template->id)->get();

            foreach ($sections as $section) {
                $items = ChecklistItem::where('section_id', $section->id)->get();

                foreach ($items as $item) {
                    $isCompleted = in_array($jobCard->status, ['qc', 'ready', 'delivered']) || rand(0, 1);

                    JobCardChecklistItem::create([
                        'job_card_checklist_id' => $checklist->id,
                        'checklist_item_id' => $item->id,
                        'is_completed' => $isCompleted,
                        'remarks' => $isCompleted && rand(0, 2) === 0 ? 'OK' : null,
                        'completed_by' => $isCompleted ? $technician->id : null,
                        'completed_at' => $isCompleted ? now() : null,
                    ]);
                }
            }
        }
    }

    private function getRandomObservation(): string
    {
        $observations = [
            'Brake pads at 60% life remaining.',
            'Tyre pressure adjusted to recommended levels.',
            'Minor oil leak observed, customer informed.',
            'AC gas topped up.',
            'Battery terminals cleaned.',
            'Wiper blades need replacement soon.',
            'All lights functioning properly.',
            'Suspension in good condition.',
        ];

        return $observations[array_rand($observations)];
    }

    private function getStatusNote(string $status): string
    {
        return match ($status) {
            'draft' => 'Job card created',
            'received' => 'Vehicle received at workshop',
            'in_progress' => 'Work started by technician',
            'qc' => 'Sent for quality check',
            'ready' => 'Ready for delivery',
            'delivered' => 'Vehicle delivered to customer',
            default => 'Status updated',
        };
    }
}

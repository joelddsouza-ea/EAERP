<?php

namespace App\Modules\CustomerApp\Services\Ai;

use App\Core\Support\Modules;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\LeadService;
use App\Modules\CustomerApp\Models\AiExplanation;
use App\Modules\CustomerApp\Models\AiRequest;
use App\Modules\CustomerApp\Services\CustomerAppException;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateLine;
use App\Modules\Workshop\Models\JobCardObservation;
use App\Modules\Workshop\Models\LabourService;
use App\Modules\Workshop\Models\PackagePricing;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\Vehicle;
use Illuminate\Http\UploadedFile;

/**
 * The customer app's assistant (Claude). It answers only from the workshop's own price lists
 * and the car's history: likely causes (never a diagnosis), price ranges (never a quote),
 * safety first. Every call is logged with its cost; a daily limit per customer and a monthly
 * budget stop it before it gets expensive. Off until the owner adds a key and switches it on.
 */
class AiAssistant
{
    public const MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5 (best answers)',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (half the cost)',
        'claude-haiku-5-5' => 'Claude Haiku 5.5 (cheapest)',
    ];

    // US$ per million tokens: input, output, cache read, cache write
    public const PRICES = [
        'claude-opus-5-5' => [4.00, 20.00, 0.20, 5.00],
        'claude-sonnet-5-5' => [2.00, 10.00, 0.20, 2.50],
        'claude-haiku-5-5' => [0.10, 0.50, 0.01, 0.125],
    ];

    public const MAX_PHOTOS = 3;

    public function __construct(protected ?ClaudeClient $client = null) {}

    public function enabled(): bool
    {
        return Modules::customerApp() && (bool) WorkshopSettings::get('ai.enabled') && AiSettings::apiKey() !== null;
    }

    public function status(Customer $customer): array
    {
        return [
            'enabled' => $this->enabled(),
            'left_today' => max(0, $this->dailyLimit() - $this->usedToday($customer)),
            'paused' => $this->enabled() && $this->overBudget(),
        ];
    }

    // ------------------------------------------------------------------ 1. what's wrong

    /**
     * @param array<int, UploadedFile> $photos
     */
    public function diagnose(Customer $customer, ?Vehicle $vehicle, string $question, array $photos = []): AiRequest
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'urgency', 'urgency_advice', 'likely_causes', 'suggested_services', 'questions_to_check', 'needs_inspection'],
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'Two or three plain sentences for the car owner.'],
                'urgency' => ['type' => 'string', 'enum' => ['ok_to_drive', 'book_soon', 'stop_driving']],
                'urgency_advice' => ['type' => 'string'],
                'likely_causes' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['cause', 'why'],
                    'properties' => ['cause' => ['type' => 'string'], 'why' => ['type' => 'string']],
                ]],
                'suggested_services' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'price_min', 'price_max', 'note'],
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Exactly as named in the workshop price list.'],
                        'price_min' => ['type' => ['integer', 'null']],
                        'price_max' => ['type' => ['integer', 'null']],
                        'note' => ['type' => 'string'],
                    ],
                ]],
                'questions_to_check' => ['type' => 'array', 'items' => ['type' => 'string']],
                'needs_inspection' => ['type' => 'boolean'],
            ],
        ];

        $task = "The customer describes a problem with their car" . ($photos ? ' and sent photos' : '') . ". Work out what is most likely wrong, how urgent it is, and which of the workshop's services would fix it.\n\nCustomer's words:\n" . trim($question);

        return $this->ask($customer, $vehicle, 'diagnose', $question, $task, $photos, $schema);
    }

    // ------------------------------------------------------------------ 2. explain an estimate line

    public function explainLine(Customer $customer, JobCard $job, string $lineKey): string
    {
        $estimate = JobCardEstimate::where('job_card_id', $job->id)->where('status', JobCardEstimate::STATUS_SENT)->latest('version')->firstOrFail();
        $cached = AiExplanation::where('estimate_id', $estimate->id)->where('line_key', $lineKey)->value('text');
        if ($cached) {
            return $cached;
        }

        $line = JobCardEstimateLine::where('estimate_id', $estimate->id)->where('line_key', $lineKey)->firstOrFail();
        $vehicle = $job->vehicle_id ? Vehicle::find($job->vehicle_id) : null;
        $schema = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['explanation'],
            'properties' => ['explanation' => ['type' => 'string', 'description' => 'Two or three short sentences.']],
        ];
        $task = "The customer is looking at their estimate and asks why this item is needed. Explain, for this car, what the part or job does, why it is usually replaced or done, and what happens if it is left. Do not mention prices, do not promise anything, do not say it is definitely needed (the technician found it on inspection).\n\nEstimate line: {$line->description}" . ($line->kind ? " ({$line->kind})" : '') . ($line->quantity ? ", quantity {$line->quantity}" : '');

        $request = $this->ask($customer, $vehicle, 'explain', $line->description, $task, [], $schema);
        $text = $request->answer['explanation'] ?? null;
        if (!$text) {
            throw new CustomerAppException($request->error ?: 'No explanation right now. Ask your service advisor.');
        }
        AiExplanation::updateOrCreate(['estimate_id' => $estimate->id, 'line_key' => $lineKey], ['text' => $text]);

        return $text;
    }

    // ------------------------------------------------------------------ 3. accident help

    /**
     * @param array<int, UploadedFile> $photos
     */
    public function accident(Customer $customer, ?Vehicle $vehicle, string $description, array $photos): AiRequest
    {
        $schema = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['summary', 'damage', 'estimate_min', 'estimate_max', 'estimate_note', 'safe_to_drive', 'claim_advice', 'claim_steps', 'documents_needed'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'damage' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Panels / parts that look damaged.'],
                'estimate_min' => ['type' => ['integer', 'null']],
                'estimate_max' => ['type' => ['integer', 'null']],
                'estimate_note' => ['type' => 'string'],
                'safe_to_drive' => ['type' => 'string', 'enum' => ['yes', 'check_first', 'no']],
                'claim_advice' => ['type' => 'string', 'description' => 'Whether an insurance claim is likely worth it versus paying directly, and why.'],
                'claim_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                'documents_needed' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
        $task = "The customer's car was in an accident or got damaged. From the photos and description: list what looks damaged, give a rough repair range using only the body-shop guide below (null when the guide doesn't cover it), say whether it looks safe to drive, and explain the insurance claim steps in India (inform the insurer, survey, cashless at the workshop, deductible / depreciation, no-claim bonus).\n\nCustomer's words:\n" . trim($description);

        return $this->ask($customer, $vehicle, 'accident', $description, $task, $photos, $schema);
    }

    /**
     * "Get it inspected": a body-shop lead for the advisors, with the assistant's summary.
     */
    public function accidentLead(Customer $customer, AiRequest $request): ?Lead
    {
        if (!Modules::crm() || $request->customer_id !== $customer->id || $request->feature !== 'accident') {
            return null;
        }
        $vehicle = $request->vehicle_id ? Vehicle::find($request->vehicle_id) : null;
        [$lead] = app(LeadService::class)->capture([
            'name' => $customer->name,
            'phone' => $customer->whatsapp_phone ?: $customer->phone,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle?->id,
            'registration_no' => $vehicle?->registration_no,
            'make_id' => $vehicle?->make_id,
            'model_id' => $vehicle?->model_id,
            'category_id' => $vehicle?->category_id,
            'interest' => 'body_shop',
            'branch_id' => JobCard::withoutGlobalScopes()->where('customer_id', $customer->id)->latest('id')->value('branch_id'),
            'requirement' => mb_strimwidth('Accident (customer app): ' . ($request->answer['summary'] ?? $request->question), 0, 480, '…'),
            'note' => 'From the customer app assistant (accident help)',
            'source' => 'customer_app',
        ], null);
        $request->update(['outcome' => 'lead']);

        return $lead;
    }

    public function markBooked(Customer $customer, int $requestId): void
    {
        AiRequest::where('customer_id', $customer->id)->whereKey($requestId)->update(['outcome' => 'booked']);
    }

    // ------------------------------------------------------------------ the call

    /**
     * @param array<int, UploadedFile> $photos
     */
    protected function ask(Customer $customer, ?Vehicle $vehicle, string $feature, string $question, string $task, array $photos, array $schema): AiRequest
    {
        if (!$this->enabled()) {
            throw new CustomerAppException('The assistant is not available right now. Please call us.', [], 404);
        }
        if ($feature !== 'explain' && $this->usedToday($customer) >= $this->dailyLimit()) {
            throw new CustomerAppException("You've asked the assistant {$this->dailyLimit()} times today. Please call us, or try again tomorrow.", [], 429);
        }
        if ($this->overBudget()) {
            throw new CustomerAppException('The assistant is resting for now. Please call us; we are happy to help.', [], 503);
        }

        $model = AiSettings::model();
        $content = [];
        foreach (array_slice($photos, 0, self::MAX_PHOTOS) as $photo) {
            [$data, $mime] = $this->shrink($photo);
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($data)]];
        }
        $content[] = ['type' => 'text', 'text' => $this->carContext($customer, $vehicle) . "\n\n" . $task];

        $result = $this->client()->json($model, $this->systemPrompt($vehicle), $content, $schema, $feature === 'explain' ? 1000 : 4000);

        return AiRequest::create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle?->id,
            'feature' => $feature,
            'model' => $result['model'] ?: $model,
            'question' => mb_substr($question, 0, 2000),
            'photos' => min(count($photos), self::MAX_PHOTOS),
            'answer' => $result['data'],
            'stop_reason' => $result['stop_reason'],
            'input_tokens' => $result['usage']['input'],
            'output_tokens' => $result['usage']['output'],
            'cache_read_tokens' => $result['usage']['cache_read'],
            'cache_write_tokens' => $result['usage']['cache_write'],
            'cost_inr' => $this->cost($model, $result['usage']),
            'error' => $result['stop_reason'] === 'refusal' ? 'The assistant could not answer this one.' : ($result['error'] ? mb_substr($result['error'], 0, 255) : null),
        ]);
    }

    /**
     * Stable instructions and the price lists first (cached), so repeat questions cost little.
     *
     * @return array<int, array<string, mixed>>
     */
    public function systemPrompt(?Vehicle $vehicle): array
    {
        $workshop = \App\Core\Models\Organization::instance()?->name ?: config('app.name');
        $rules = <<<TXT
You are the car-care assistant in the {$workshop} customer app (a multi-brand car service workshop in India). You talk to car owners, not mechanics.

How to answer:
- Use plain, friendly English a non-technical owner understands. Short sentences.
- Give likely causes, never a definite diagnosis: the workshop confirms on inspection.
- Safety first. Brake problems, steering problems, overheating, smoke, burning or fuel smell, a flashing engine light, or tyre damage mean "stop_driving" (or "no" for accidents): tell them to stop and call the workshop for pickup.
- Prices: only from the workshop's price lists below, as ranges in rupees. Labour charges there exclude parts; say so when parts are likely. If the lists don't cover it, leave the price empty and say the workshop will quote after inspection. Never invent a service the lists don't have; name services exactly as listed.
- Do not recommend other garages, brands of parts, or do-it-yourself repairs beyond simple safe checks (tyre pressure, fluid levels, a loose fuel cap).
- Photos may be unclear: say what you can and cannot see.
TXT;

        $blocks = [['type' => 'text', 'text' => $rules], ['type' => 'text', 'text' => $this->priceLists($vehicle)]];
        // Cache everything up to here: rules + this car type's price lists
        $blocks[1]['cacheControl'] = ['type' => 'ephemeral'];

        return $blocks;
    }

    /**
     * The workshop's prices for the car's type (all types when it isn't known).
     */
    public function priceLists(?Vehicle $vehicle): string
    {
        $categoryId = $vehicle?->category_id;
        $fuel = in_array(strtolower((string) $vehicle?->fuel_type), ['diesel'], true) ? 'diesel' : 'petrol';
        $lines = ["WORKSHOP PRICE LISTS (rupees, GST included)" . ($vehicle?->category?->name ? " for {$vehicle->category->name}" : '')];

        $lines[] = "\nService plans (price depends on engine oil grade):";
        $prices = PackagePricing::query()->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))->where('price', '>', 0)->get(['package_id', 'price']);
        foreach (ServicePackage::where('is_active', true)->orderBy('sort_order')->get() as $p) {
            $rows = $prices->where('package_id', $p->id);
            if ($rows->isEmpty()) {
                continue;
            }
            $lines[] = "- {$p->name}: Rs " . number_format((float) $rows->min('price')) . ($rows->max('price') > $rows->min('price') ? '–' . number_format((float) $rows->max('price')) : '')
                . ($p->activity_points ? " ({$p->activity_points} checks)" : '') . ($p->description ? ' — ' . mb_strimwidth(strip_tags((string) $p->description), 0, 160, '…') : '');
        }

        $lines[] = "\nLabour charges (parts extra):";
        $labour = LabourService::with(['rates' => fn ($q) => $q->when($categoryId, fn ($w) => $w->where('category_id', $categoryId))])
            ->where('is_active', true)->orderBy('sort_order')->get();
        foreach ($labour as $s) {
            $rates = $s->rates->filter(fn ($r) => $r->rate !== null && (!$categoryId || $r->fuel_group === $fuel))->pluck('rate');
            if ($rates->isEmpty()) {
                $rates = $s->rates->pluck('rate')->filter();
            }
            if ($rates->isEmpty()) {
                continue;
            }
            $lines[] = "- {$s->name}: Rs " . number_format((float) $rates->min()) . ($rates->max() > $rates->min() ? '–' . number_format((float) $rates->max()) : '') . ($s->unit === 'hour' ? ' per hour' : '');
        }

        $guide = trim((string) WorkshopSettings::get('ai.body_shop_guide'));
        $lines[] = "\nBody shop guide:\n" . ($guide !== '' ? $guide : '(none: body work is quoted after inspection)');

        return implode("\n", $lines);
    }

    protected function carContext(Customer $customer, ?Vehicle $vehicle): string
    {
        if (!$vehicle) {
            return 'Car: not chosen.';
        }
        $vehicle->loadMissing(['make', 'model', 'category']);
        $lastJob = JobCard::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->latest('id')->first(['id', 'km_reading', 'created_at']);
        $observations = $lastJob
            ? JobCardObservation::where('job_card_id', $lastJob->id)->where('customer_visible', true)->limit(8)->pluck('text')->filter()->implode('; ')
            : '';

        return trim(implode("\n", array_filter([
            'Car: ' . trim(($vehicle->make?->name ?? '') . ' ' . ($vehicle->model?->name ?? '')) . ($vehicle->year ? " ({$vehicle->year})" : '') . ($vehicle->fuel_type ? ", {$vehicle->fuel_type}" : '') . ($vehicle->transmission ? ", {$vehicle->transmission}" : ''),
            $vehicle->category?->name ? "Car type: {$vehicle->category->name}" : null,
            $lastJob ? 'Last visit: ' . $lastJob->created_at->format('M Y') . ($lastJob->km_reading ? ", {$lastJob->km_reading} km" : '') : 'No visits yet.',
            $observations ? "Recommended at the last visit (not done): {$observations}" : null,
        ])));
    }

    // ------------------------------------------------------------------ limits and cost

    public function dailyLimit(): int
    {
        return max(1, (int) WorkshopSettings::get('ai.daily_limit'));
    }

    public function usedToday(Customer $customer): int
    {
        return AiRequest::where('customer_id', $customer->id)->where('feature', '!=', 'explain')->where('created_at', '>=', today())->count();
    }

    public function monthCost(): float
    {
        return round((float) AiRequest::where('created_at', '>=', now()->startOfMonth())->sum('cost_inr'), 2);
    }

    public function overBudget(): bool
    {
        $budget = (float) WorkshopSettings::get('ai.monthly_budget_inr');

        return $budget > 0 && $this->monthCost() >= $budget;
    }

    public function cost(string $model, array $usage): float
    {
        [$in, $out, $read, $write] = self::PRICES[$model] ?? self::PRICES['claude-opus-5-5'];
        $usd = ($usage['input'] * $in + $usage['output'] * $out + $usage['cache_read'] * $read + $usage['cache_write'] * $write) / 1_000_000;

        return round($usd * (float) (WorkshopSettings::get('ai.usd_inr') ?: 88), 2);
    }

    protected function client(): ClaudeClient
    {
        return $this->client ?? app(ClaudeClient::class);
    }

    protected function imageType(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();

        return in_array($mime, ['image/png', 'image/gif', 'image/webp'], true) ? $mime : 'image/jpeg';
    }

    /**
     * Photos go no larger than 1568 px on the long side (bigger only costs more), as JPEG.
     *
     * @return array{0: string, 1: string} data and media type
     */
    protected function shrink(UploadedFile $file): array
    {
        $raw = (string) file_get_contents($file->getRealPath());
        $img = function_exists('imagecreatefromstring') ? @imagecreatefromstring($raw) : false;
        if (!$img) {
            return [$raw, $this->imageType($file)];
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        $scale = min(1, 1568 / max($w, $h));
        $out = $scale < 1 ? imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))) : $img;
        ob_start();
        imagejpeg($out ?: $img, null, 82);

        return [(string) ob_get_clean(), 'image/jpeg'];
    }
}

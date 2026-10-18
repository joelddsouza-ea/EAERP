<?php

namespace App\Modules\CustomerApp\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Support\WorkshopSettings;
use App\Modules\CustomerApp\Models\AiRequest;
use App\Modules\CustomerApp\Models\CustomerNotification;
use App\Modules\CustomerApp\Models\CustomerOtp;
use App\Modules\CustomerApp\Models\CustomerSession;
use App\Modules\CustomerApp\Services\Ai\AiAssistant;
use App\Modules\CustomerApp\Services\CustomerAppException;
use App\Modules\CustomerApp\Services\CustomerAuthService;
use App\Modules\CustomerApp\Services\CustomerBookingService;
use App\Modules\CustomerApp\Services\CustomerExtrasService;
use App\Modules\CustomerApp\Services\CustomerNotifier;
use App\Modules\CustomerApp\Services\CustomerPortalService;
use App\Modules\CustomerApp\Services\PaymentClaimService;
use App\Modules\Workshop\Models\Customer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The customer app's API. Sign-in routes are public; everything else needs a customer session
 * (middleware auth.customer) and only ever touches the signed-in customer's own records.
 */
class CustomerAppController extends ApiController
{
    public function __construct(
        protected CustomerAuthService $auth,
        protected CustomerPortalService $portal,
    ) {}

    protected function customer(Request $request): Customer
    {
        return $request->attributes->get('customer');
    }

    protected function guarded(callable $fn, ?string $message = null): JsonResponse
    {
        try {
            return $this->success($fn(), $message ?? 'Success');
        } catch (CustomerAppException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors);
        }
    }

    // ------------------------------------------------------------------ sign in

    public function signInWithLink(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => 'required|in:job,crm', 'token' => 'required|string|max:100']);
        $customer = $this->auth->fromLink($data['kind'], $data['token']);
        if (!$customer) {
            return $this->error('This link has expired. Sign in with your mobile number instead.', 404);
        }

        $response = $this->session($customer, $data['kind'] === 'job' ? 'job_link' : 'crm_link', $request);
        // A job link opens that job in the app
        if ($data['kind'] === 'job') {
            $payload = $response->getData(true);
            $payload['data']['job_id'] = app(\App\Modules\Workshop\Services\CustomerLinkService::class)->resolve($data['token'])?->job_card_id;
            $response->setData($payload);
        }

        return $response;
    }

    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => 'required|string|max:20']);
        $result = $this->auth->requestCode($data['phone']);

        if (!empty($result['limited'])) {
            return $this->error('Too many codes asked for. Please wait an hour or call us.', 429);
        }

        // Same answer whether or not the number is ours (no number fishing)
        return $this->success([
            'via' => $result['via'] ?? CustomerOtp::VIA_STAFF,
            'support_phone' => WorkshopSettings::get('customer_app.support_phone'),
        ], ($result['via'] ?? null) === 'interakt'
            ? 'We have sent a code to your WhatsApp.'
            : 'Ask the workshop for your code: they can see it on your customer page. If your number is new to us, call us or book a service.');
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => 'required|string|max:20', 'code' => 'required|string|max:10']);
        $customer = $this->auth->verifyCode($data['phone'], $data['code']);
        if (!$customer) {
            return $this->error('That code is not right or has expired.', 422, ['code' => ['Wrong or expired code.']]);
        }

        return $this->session($customer, 'otp', $request);
    }

    protected function session(Customer $customer, string $via, Request $request): JsonResponse
    {
        $issued = $this->auth->issue($customer, $via, $request->userAgent());

        return $this->success([
            'token' => $issued['token'],
            'customer' => ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone],
        ], "Welcome, {$customer->name}");
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var CustomerSession $session */
        $session = $request->attributes->get('customer_session');
        $this->auth->revoke($session);

        return $this->success(null, 'Signed out');
    }

    // ------------------------------------------------------------------ home, jobs, cars

    public function home(Request $request): JsonResponse
    {
        return $this->success($this->portal->home($this->customer($request)) + [
            'assistant' => app(AiAssistant::class)->status($this->customer($request)),
            'push_key' => WorkshopSettings::get('customer_app.push_enabled') ? app(CustomerNotifier::class)->publicKey() : null,
        ]);
    }

    public function jobs(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $jobs = \App\Modules\Workshop\Models\JobCard::withoutGlobalScopes()->with(['vehicle.make', 'vehicle.model', 'package'])
            ->where('customer_id', $customer->id)->latest('id')->limit(50)->get();

        return $this->success($jobs->map(fn ($j) => $this->portal->jobCard($j))->values());
    }

    public function job(Request $request, int $job): JsonResponse
    {
        return $this->success($this->portal->jobScreen($this->customer($request), $job));
    }

    public function claimPayment(Request $request, int $job, PaymentClaimService $claims): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1|max:10000000',
            'utr' => 'required|string|max:30',
            'screenshot' => 'nullable|image|max:' . (int) config('workshop.job_sheets.max_photo_kb'),
        ]);
        $jobCard = $this->portal->job($this->customer($request), $job);

        return $this->guarded(fn () => $claims->present($claims->claim($this->customer($request), $jobCard, (float) $data['amount'], $data['utr'], $request->file('screenshot'))),
            'Thank you! We will confirm once it reaches our bank (usually within a few hours).');
    }

    public function serviceBook(Request $request, int $vehicle): JsonResponse
    {
        return $this->success($this->portal->serviceBook($this->customer($request), $vehicle));
    }

    /**
     * The car's service history as a PDF (for resale, insurance).
     */
    public function serviceBookPdf(Request $request, int $vehicle): Response
    {
        $customer = $this->customer($request);
        $book = $this->portal->serviceBook($customer, $vehicle);
        $pdf = Pdf::loadView('exports.service-history', [
            'book' => $book,
            'customer' => $customer,
            'workshop' => config('app.name'),
            'generated' => now(),
        ])->setPaper('a4');

        return $pdf->download('service-history-' . preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $book['vehicle']['registration_no'])) . '.pdf');
    }

    public function addVehicle(Request $request, CustomerBookingService $bookings): JsonResponse
    {
        $data = $request->validate([
            'registration_no' => 'required|string|min:6|max:15',
            'make_id' => 'nullable|integer|exists:vehicle_makes,id',
            'model_id' => 'nullable|integer|exists:vehicle_models,id',
            'year' => 'nullable|integer|min:1980|max:' . (now()->year + 1),
            'fuel_type' => 'nullable|in:petrol,diesel,cng,electric,hybrid',
        ]);

        return $this->guarded(fn () => ['id' => $bookings->addVehicle($this->customer($request), $data)->id], 'Car added');
    }

    public function makes(CustomerBookingService $bookings): JsonResponse
    {
        return $this->success($bookings->makes());
    }

    public function models(int $make, CustomerBookingService $bookings): JsonResponse
    {
        return $this->success($bookings->models($make));
    }

    // ------------------------------------------------------------------ bookings

    public function bookingOptions(Request $request, CustomerBookingService $bookings): JsonResponse
    {
        return $this->success($bookings->options($this->customer($request), $request->integer('vehicle_id') ?: null));
    }

    public function slots(Request $request, CustomerBookingService $bookings): JsonResponse
    {
        $data = $request->validate(['branch_id' => 'required|integer|exists:branches,id', 'date' => 'required|date|after_or_equal:today']);

        return $this->success($bookings->slots((int) $data['branch_id'], $data['date']));
    }

    public function book(Request $request, CustomerBookingService $bookings): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => 'required|integer',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'booking_date' => 'required|date|after_or_equal:today',
            'slot_from' => 'nullable|date_format:H:i',
            'package_id' => 'nullable|integer|exists:service_packages,id',
            'oil_type_id' => 'nullable|integer|exists:oil_types,id',
            'service_type' => 'nullable|in:' . implode(',', array_keys(CustomerBookingService::REPAIR_TYPES)),
            'service_mode' => 'required|in:drive_in,pickup_drop,home',
            'address' => 'required_unless:service_mode,drive_in|nullable|string|max:1000',
            'landmark' => 'nullable|string|max:255',
            'maps_url' => 'nullable|url|max:500',
            'notes' => 'nullable|string|max:1000',
            'ai_request_id' => 'nullable|integer',
        ], ['address.required_unless' => 'Enter the address.']);
        $customer = $this->customer($request);

        return $this->guarded(function () use ($bookings, $customer, $data) {
            $booking = $bookings->book($customer, $data);
            if (!empty($data['ai_request_id'])) {
                app(AiAssistant::class)->markBooked($customer, (int) $data['ai_request_id']);
            }

            return ['id' => $booking->id, 'number' => $booking->number, 'date' => $booking->booking_date->toDateString(), 'slot' => $booking->slot_from ? substr($booking->slot_from, 0, 5) : null];
        }, 'Booked! We will call you to confirm.');
    }

    public function bookings(Request $request, CustomerBookingService $bookings): JsonResponse
    {
        return $this->success($bookings->list($this->customer($request)));
    }

    public function cancelBooking(Request $request, int $booking, CustomerBookingService $bookings): JsonResponse
    {
        $reason = $request->validate(['reason' => 'nullable|string|max:255'])['reason'] ?? null;

        return $this->guarded(fn () => ['status' => $bookings->cancel($this->customer($request), $booking, $reason)->status], 'Booking cancelled');
    }

    // ------------------------------------------------------------------ inbox and push

    public function notifications(Request $request): JsonResponse
    {
        $rows = CustomerNotification::where('customer_id', $this->customer($request)->id)->latest('id')->limit(100)->get();

        return $this->success($rows->map(fn (CustomerNotification $n) => [
            'id' => $n->id, 'kind' => $n->kind, 'title' => $n->title, 'body' => $n->body, 'link' => $n->link,
            'read' => $n->read_at !== null, 'created_at' => $n->created_at?->toIso8601String(),
        ])->values());
    }

    public function readNotifications(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => 'nullable|array', 'ids.*' => 'integer'])['ids'] ?? null;
        CustomerNotification::where('customer_id', $this->customer($request)->id)->whereNull('read_at')
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))->update(['read_at' => now()]);

        return $this->success(null);
    }

    public function subscribePush(Request $request, CustomerNotifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => 'required|url|max:1000',
            'keys.p256dh' => 'required|string|max:200',
            'keys.auth' => 'required|string|max:100',
        ]);
        $notifier->subscribe($this->customer($request)->id, $data, $request->userAgent());

        return $this->success(null, 'Notifications on');
    }

    // ------------------------------------------------------------------ wallet and documents

    public function wallet(Request $request, CustomerExtrasService $extras): JsonResponse
    {
        return $this->success($extras->wallet($this->customer($request)));
    }

    public function documents(Request $request, CustomerExtrasService $extras): JsonResponse
    {
        return $this->success($extras->documents($this->customer($request)));
    }

    public function addDocument(Request $request, CustomerExtrasService $extras): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:insurance,puc,rc,licence,other',
            'vehicle_id' => 'nullable|integer',
            'title' => 'nullable|string|max:120',
            'number' => 'nullable|string|max:60',
            'expiry_date' => 'nullable|date',
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,heic,pdf|max:10240',
        ]);

        return $this->guarded(fn () => $extras->presentDocument($extras->addDocument($this->customer($request), $data, $request->file('file'))), 'Saved');
    }

    public function deleteDocument(Request $request, int $document, CustomerExtrasService $extras): JsonResponse
    {
        $extras->deleteDocument($this->customer($request), $document);

        return $this->success(null, 'Deleted');
    }

    // ------------------------------------------------------------------ assistant

    public function assistantStatus(Request $request, AiAssistant $ai): JsonResponse
    {
        return $this->success($ai->status($this->customer($request)));
    }

    public function diagnose(Request $request, AiAssistant $ai): JsonResponse
    {
        $data = $this->assistantInput($request);
        $customer = $this->customer($request);
        $vehicle = !empty($data['vehicle_id']) ? $this->portal->vehicle($customer, (int) $data['vehicle_id']) : null;

        return $this->guarded(fn () => $this->presentAi($ai->diagnose($customer, $vehicle, $data['question'], $request->file('photos') ?? [])));
    }

    public function accident(Request $request, AiAssistant $ai): JsonResponse
    {
        $data = $this->assistantInput($request);
        $customer = $this->customer($request);
        $vehicle = !empty($data['vehicle_id']) ? $this->portal->vehicle($customer, (int) $data['vehicle_id']) : null;

        return $this->guarded(fn () => $this->presentAi($ai->accident($customer, $vehicle, $data['question'], $request->file('photos') ?? [])));
    }

    public function accidentLead(Request $request, int $aiRequest, AiAssistant $ai): JsonResponse
    {
        $customer = $this->customer($request);
        $row = AiRequest::where('customer_id', $customer->id)->findOrFail($aiRequest);
        $lead = $ai->accidentLead($customer, $row);

        return $lead
            ? $this->success(['lead_id' => $lead->id], 'Our body shop team will call you shortly.')
            : $this->error('Please call us to arrange an inspection.', 422);
    }

    public function explain(Request $request, int $job, AiAssistant $ai): JsonResponse
    {
        $data = $request->validate(['line_key' => 'required|string|max:80']);
        $customer = $this->customer($request);
        $jobCard = $this->portal->job($customer, $job);

        return $this->guarded(fn () => ['text' => $ai->explainLine($customer, $jobCard, $data['line_key'])]);
    }

    protected function assistantInput(Request $request): array
    {
        return $request->validate([
            'question' => 'required|string|min:5|max:2000',
            'vehicle_id' => 'nullable|integer',
            'photos' => 'nullable|array|max:' . AiAssistant::MAX_PHOTOS,
            'photos.*' => 'image|max:15360',
        ], ['question.min' => 'Tell us a little more about it.']);
    }

    protected function presentAi(AiRequest $r): array
    {
        return ['id' => $r->id, 'feature' => $r->feature, 'answer' => $r->answer, 'error' => $r->error, 'created_at' => $r->created_at?->toIso8601String()];
    }
}

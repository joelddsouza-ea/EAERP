<?php

namespace App\Modules\CustomerApp\Services;

use App\Core\Models\WhatsAppTemplate;
use App\Core\Services\WhatsApp\InteraktChannel;
use App\Core\Services\WhatsApp\ShareLinkChannel;
use App\Core\Services\WhatsApp\WhatsAppSettings;
use App\Core\Support\Modules;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\CrmLink;
use App\Modules\CustomerApp\Models\CustomerOtp;
use App\Modules\CustomerApp\Models\CustomerSession;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Notifications\StaffAlert;
use App\Modules\Workshop\Services\CustomerLinkService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Customer app sign-in. Customers have no passwords:
 *  - any job or CRM link the workshop sent them signs them in (the link is already their key);
 *  - or their mobile number and a one-time code, sent by WhatsApp (Interakt) when that is on,
 *    otherwise shown to staff on the customer's page to read out.
 * Sessions use their own tokens (hashed), never staff logins.
 *
 * A fleet's drivers sign in the same ways (their own mobile number, or their own job link);
 * their session is the fleet's customer plus the driver. One number can be several accounts
 * (a driver who also has his own car, or drives for two fleets): the app then asks which.
 */
class CustomerAuthService
{
    public const SESSION_DAYS = 180;
    public const MAX_CODES_PER_HOUR = 3;

    public function __construct(protected CustomerLinkService $jobLinks) {}

    /**
     * @return array{token: string, session: CustomerSession}
     */
    public function issue(Customer $customer, string $via, ?string $device = null, ?FleetDriver $driver = null): array
    {
        $token = Str::random(64);
        $session = CustomerSession::create([
            'customer_id' => $customer->id,
            'fleet_driver_id' => $driver?->id,
            'token_hash' => hash('sha256', $token),
            'via' => $via,
            'device' => $device ? mb_substr($device, 0, 250) : null,
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::SESSION_DAYS),
        ]);

        return ['token' => $token, 'session' => $session];
    }

    public function resolve(?string $token): ?CustomerSession
    {
        if (!$token) {
            return null;
        }
        $session = CustomerSession::with(['customer', 'driver'])->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        if (!$session || !$session->customer || !$session->customer->is_active) {
            return null;
        }
        // A driver: still active, still with this fleet, and fleets switched on
        if ($session->fleet_driver_id && (!$session->driver?->is_active || $session->driver->customer_id !== $session->customer_id
            || !$session->customer->is_fleet || !Modules::fleet())) {
            return null;
        }
        // Touch at most every few minutes
        if (!$session->last_used_at || $session->last_used_at->lt(now()->subMinutes(5))) {
            $session->forceFill(['last_used_at' => now()])->save();
        }

        return $session;
    }

    public function revoke(CustomerSession $session): void
    {
        $session->update(['revoked_at' => now()]);
    }

    /**
     * Sign in with a link the customer received: a job page (/j/…) or a CRM link (/c/…).
     * A driver's job link signs that driver in.
     *
     * @return array{customer: Customer, driver: ?FleetDriver}|null
     */
    public function accountFromLink(string $kind, string $token): ?array
    {
        if ($kind === 'job') {
            $link = $this->jobLinks->resolve($token);
            if ($link?->fleet_driver_id) {
                $driver = FleetDriver::with('customer')->find($link->fleet_driver_id);

                return $driver && $this->driverUsable($driver) ? ['customer' => $driver->customer, 'driver' => $driver] : null;
            }
        }
        $customer = $this->fromLink($kind, $token);

        return $customer ? ['customer' => $customer, 'driver' => null] : null;
    }

    public function fromLink(string $kind, string $token): ?Customer
    {
        if ($kind === 'job') {
            $link = $this->jobLinks->resolve($token);
            if ($link?->fleet_driver_id) {
                return null;
            }
            $jobCard = $link ? JobCard::withoutGlobalScopes()->find($link->job_card_id) : null;

            return $jobCard?->customer_id ? Customer::find($jobCard->customer_id) : null;
        }

        $link = strlen($token) === 32 ? CrmLink::with('lead')->where('token_hash', CrmLink::hashToken($token))->first() : null;
        if (!$link || !$link->isUsable()) {
            return null;
        }

        return $link->customer_id ? Customer::find($link->customer_id) : ($link->lead?->customer_id ? Customer::find($link->lead->customer_id) : null);
    }

    /**
     * Every account this number signs in to: the customer with this number, and each fleet
     * it drives for.
     *
     * @return array<int, array{customer: Customer, driver: ?FleetDriver}>
     */
    public function accountsForPhone(string $phone): array
    {
        $accounts = [];
        if ($customer = $this->findByPhone($phone)) {
            $accounts[] = ['customer' => $customer, 'driver' => null];
        }
        foreach ($this->driversByPhone($phone) as $driver) {
            $accounts[] = ['customer' => $driver->customer, 'driver' => $driver];
        }

        return $accounts;
    }

    /**
     * @return \Illuminate\Support\Collection<int, FleetDriver>
     */
    public function driversByPhone(string $phone)
    {
        $normal = ShareLinkChannel::normalizePhone($phone);
        if (!$normal || !Modules::fleet()) {
            return collect();
        }
        $ten = substr($normal, -10);

        return FleetDriver::with('customer')->where('is_active', true)
            ->whereRaw("REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?", ["%{$ten}"])
            ->orderBy('id')->get()
            ->filter(fn (FleetDriver $d) => $this->driverUsable($d))->values();
    }

    public function driverUsable(FleetDriver $driver): bool
    {
        return $driver->is_active && $driver->customer?->is_active && $driver->customer->is_fleet && Modules::fleet();
    }

    /**
     * The key the app sends back to pick one account: "c12" (customer) or "d5" (driver).
     */
    public static function accountKey(array $account): string
    {
        return $account['driver'] ? 'd' . $account['driver']->id : 'c' . $account['customer']->id;
    }

    public static function presentAccount(array $account): array
    {
        $c = $account['customer'];

        return [
            'key' => self::accountKey($account),
            'label' => $account['driver'] ? 'Driver for ' . ($c->company_name ?: $c->name) : 'My own cars',
            'name' => $account['driver']?->name ?? $c->name,
        ];
    }

    /**
     * After a right code on a number with several accounts: a short-lived ticket to pick one.
     */
    public function choiceTicket(string $phone): string
    {
        return Crypt::encryptString(json_encode(['phone' => ShareLinkChannel::normalizePhone($phone), 'until' => now()->addMinutes(10)->timestamp]));
    }

    /**
     * @return array{customer: Customer, driver: ?FleetDriver}|null
     */
    public function choose(string $ticket, string $key): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($ticket), true);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data) || ($data['until'] ?? 0) < now()->timestamp || empty($data['phone'])) {
            return null;
        }

        return collect($this->accountsForPhone($data['phone']))->first(fn ($a) => self::accountKey($a) === $key);
    }

    public function findByPhone(string $phone): ?Customer
    {
        $normal = ShareLinkChannel::normalizePhone($phone);
        if (!$normal) {
            return null;
        }
        $ten = substr($normal, -10);

        return Customer::where('is_active', true)
            // Numbers are stored as typed (spaces, dashes, +91)
            ->where(fn ($q) => $q->whereRaw("REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?", ["%{$ten}"])
                ->orWhereRaw("REPLACE(REPLACE(REPLACE(COALESCE(whatsapp_phone, ''), ' ', ''), '-', ''), '+', '') LIKE ?", ["%{$ten}"]))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Send (or prepare for staff) a sign-in code. Says nothing about whether the number is known.
     *
     * @return array{sent: bool, via: ?string, known: bool, limited?: bool}
     */
    public function requestCode(string $phone): array
    {
        $first = $this->accountsForPhone($phone)[0] ?? null;
        if (!$first) {
            return ['sent' => false, 'via' => null, 'known' => false];
        }
        $customer = $first['customer'];
        $normal = ShareLinkChannel::normalizePhone($phone);

        $recent = CustomerOtp::where('phone', $normal)->where('created_at', '>', now()->subHour())->count();
        if ($recent >= self::MAX_CODES_PER_HOUR) {
            return ['sent' => false, 'via' => null, 'known' => true, 'limited' => true];
        }

        // Earlier codes stop working
        CustomerOtp::where('phone', $normal)->whereNull('used_at')->update(['used_at' => now()]);

        $code = (string) random_int(100000, 999999);
        $via = $this->sendByWhatsApp($normal, $code) ? CustomerOtp::VIA_INTERAKT : CustomerOtp::VIA_STAFF;

        CustomerOtp::create([
            'customer_id' => $customer->id,
            'fleet_driver_id' => $first['driver']?->id,
            'phone' => $normal,
            'code_hash' => hash('sha256', $normal . '|' . $code),
            'code_encrypted' => Crypt::encryptString($code),
            'delivered_via' => $via,
            'expires_at' => now()->addMinutes(max(3, (int) WorkshopSettings::get('customer_app.otp_minutes'))),
        ]);

        if ($via === CustomerOtp::VIA_STAFF) {
            $this->tellStaff($customer, $first['driver']);
        }

        return ['sent' => true, 'via' => $via, 'known' => true];
    }

    /**
     * A right code: every account of the number (empty when wrong or expired).
     *
     * @return array<int, array{customer: Customer, driver: ?FleetDriver}>
     */
    public function verifyCode(string $phone, string $code): array
    {
        $normal = ShareLinkChannel::normalizePhone($phone);
        if (!$normal) {
            return [];
        }
        $otp = CustomerOtp::where('phone', $normal)->whereNull('used_at')->latest('id')->first();
        if (!$otp || !$otp->isOpen()) {
            return [];
        }
        if (!hash_equals($otp->code_hash, hash('sha256', $normal . '|' . preg_replace('/\D/', '', $code)))) {
            $otp->increment('attempts');

            return [];
        }
        $otp->update(['used_at' => now()]);

        return $this->accountsForPhone($normal);
    }

    /**
     * The open code for staff to read out (only codes that weren't sent by WhatsApp).
     */
    public function openCodeFor(Customer $customer): ?array
    {
        $otp = CustomerOtp::where('customer_id', $customer->id)->where('delivered_via', CustomerOtp::VIA_STAFF)
            ->whereNull('used_at')->where('expires_at', '>', now())->where('attempts', '<', 5)->latest('id')->first();

        return $otp ? [
            'code' => $otp->code(),
            'phone' => substr($otp->phone, -10),
            // A fleet's page also shows the codes its drivers asked for
            'driver' => $otp->fleet_driver_id ? FleetDriver::whereKey($otp->fleet_driver_id)->value('name') : null,
            'expires_at' => $otp->expires_at->toIso8601String(),
            'valid_till' => $otp->expires_at->format('H:i'),
        ] : null;
    }

    protected function sendByWhatsApp(string $phone, string $code): bool
    {
        if (!WhatsAppSettings::apiOn()) {
            return false;
        }
        $template = WhatsAppTemplate::where('key', 'customer_otp')->first();
        if (!$template?->isMapped()) {
            return false;
        }
        $values = ['code' => $code, 'minutes' => (string) WorkshopSettings::get('customer_app.otp_minutes')];
        $body = array_map(fn ($p) => $values[$p] ?? $code, $template->body_params ?: ['code']);
        // Authentication templates carry the code on their copy-code button too
        $buttons = $template->button_param ? ['0' => [$values[$template->button_param] ?? $code]] : [];

        return app(InteraktChannel::class)->sendTemplate($phone, $template->provider_name, $template->language, $body, [], $buttons)['ok'];
    }

    /**
     * The advisor of the customer's latest job (else the branch's booking desk) gets a bell.
     */
    protected function tellStaff(Customer $customer, ?FleetDriver $driver = null): void
    {
        $job = JobCard::withoutGlobalScopes()->where('customer_id', $customer->id)->latest('id')->first(['id', 'service_advisor_id', 'branch_id']);
        $alert = $driver
            ? new StaffAlert('app_sign_in_code', "App sign-in code — driver {$driver->name}", "{$driver->name}, a driver for {$customer->name}, asked for a code to sign in to the app. Open the fleet's page to read it out.", "/customers/{$customer->id}")
            : new StaffAlert('app_sign_in_code', "App sign-in code — {$customer->name}", 'The customer asked for a code to sign in to the app. Open their page to read it out.', "/customers/{$customer->id}");

        $to = $job?->service_advisor_id ? \App\Core\Models\User::whereKey($job->service_advisor_id)->get() : collect();
        if ($to->isEmpty()) {
            $to = \App\Core\Models\User::active()
                ->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'workshop.customers.view'))
                ->when($job?->branch_id, fn ($q, $b) => $q->whereHas('branches', fn ($w) => $w->where('branches.id', $b)))
                ->limit(10)->get();
        }
        $to->each->notify($alert);
    }
}

<?php

namespace App\Modules\CustomerApp\Services;

use App\Core\Models\WhatsAppTemplate;
use App\Core\Services\WhatsApp\InteraktChannel;
use App\Core\Services\WhatsApp\ShareLinkChannel;
use App\Core\Services\WhatsApp\WhatsAppSettings;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\CrmLink;
use App\Modules\CustomerApp\Models\CustomerOtp;
use App\Modules\CustomerApp\Models\CustomerSession;
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
 */
class CustomerAuthService
{
    public const SESSION_DAYS = 180;
    public const MAX_CODES_PER_HOUR = 3;

    public function __construct(protected CustomerLinkService $jobLinks) {}

    /**
     * @return array{token: string, session: CustomerSession}
     */
    public function issue(Customer $customer, string $via, ?string $device = null): array
    {
        $token = Str::random(64);
        $session = CustomerSession::create([
            'customer_id' => $customer->id,
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
        $session = CustomerSession::with('customer')->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        if (!$session || !$session->customer || !$session->customer->is_active) {
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
     */
    public function fromLink(string $kind, string $token): ?Customer
    {
        if ($kind === 'job') {
            $link = $this->jobLinks->resolve($token);
            $jobCard = $link ? JobCard::withoutGlobalScopes()->find($link->job_card_id) : null;

            return $jobCard?->customer_id ? Customer::find($jobCard->customer_id) : null;
        }

        $link = strlen($token) === 32 ? CrmLink::with('lead')->where('token_hash', CrmLink::hashToken($token))->first() : null;
        if (!$link || !$link->isUsable()) {
            return null;
        }

        return $link->customer_id ? Customer::find($link->customer_id) : ($link->lead?->customer_id ? Customer::find($link->lead->customer_id) : null);
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
        $customer = $this->findByPhone($phone);
        if (!$customer) {
            return ['sent' => false, 'via' => null, 'known' => false];
        }
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
            'phone' => $normal,
            'code_hash' => hash('sha256', $normal . '|' . $code),
            'code_encrypted' => Crypt::encryptString($code),
            'delivered_via' => $via,
            'expires_at' => now()->addMinutes(max(3, (int) WorkshopSettings::get('customer_app.otp_minutes'))),
        ]);

        if ($via === CustomerOtp::VIA_STAFF) {
            $this->tellStaff($customer);
        }

        return ['sent' => true, 'via' => $via, 'known' => true];
    }

    public function verifyCode(string $phone, string $code): ?Customer
    {
        $normal = ShareLinkChannel::normalizePhone($phone);
        if (!$normal) {
            return null;
        }
        $otp = CustomerOtp::where('phone', $normal)->whereNull('used_at')->latest('id')->first();
        if (!$otp || !$otp->isOpen()) {
            return null;
        }
        if (!hash_equals($otp->code_hash, hash('sha256', $normal . '|' . preg_replace('/\D/', '', $code)))) {
            $otp->increment('attempts');

            return null;
        }
        $otp->update(['used_at' => now()]);

        return Customer::find($otp->customer_id);
    }

    /**
     * The open code for staff to read out (only codes that weren't sent by WhatsApp).
     */
    public function openCodeFor(Customer $customer): ?array
    {
        $otp = CustomerOtp::where('customer_id', $customer->id)->where('delivered_via', CustomerOtp::VIA_STAFF)
            ->whereNull('used_at')->where('expires_at', '>', now())->where('attempts', '<', 5)->latest('id')->first();

        return $otp ? ['code' => $otp->code(), 'phone' => substr($otp->phone, -10), 'expires_at' => $otp->expires_at->toIso8601String(), 'valid_till' => $otp->expires_at->format('H:i')] : null;
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
    protected function tellStaff(Customer $customer): void
    {
        $job = JobCard::withoutGlobalScopes()->where('customer_id', $customer->id)->latest('id')->first(['id', 'service_advisor_id', 'branch_id']);
        $alert = new StaffAlert('app_sign_in_code', "App sign-in code — {$customer->name}", 'The customer asked for a code to sign in to the app. Open their page to read it out.', "/customers/{$customer->id}");

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

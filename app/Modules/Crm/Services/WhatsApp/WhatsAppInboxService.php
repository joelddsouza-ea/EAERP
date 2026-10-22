<?php

namespace App\Modules\Crm\Services\WhatsApp;

use App\Core\Models\User;
use App\Core\Models\WhatsAppTemplate;
use App\Core\Services\FileStorageService;
use App\Core\Services\WhatsApp\InteraktChannel;
use App\Core\Services\WhatsApp\ShareLinkChannel;
use App\Core\Services\WhatsApp\WhatsAppDispatcher;
use App\Core\Services\WhatsApp\WhatsAppSettings;
use App\Core\Support\PublicUrl;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\WhatsAppChatMessage as Msg;
use App\Modules\Crm\Models\WhatsAppConversation as Conv;
use App\Modules\Crm\Services\CrmException;
use App\Modules\Crm\Services\LeadService;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The CRM's WhatsApp inbox: one conversation per number with everything that came in and
 * went out (free-form replies and templates). Who sees a chat: the lead's executive, the
 * advisor of the customer's open job, whoever it is assigned to; managers see all.
 *
 * Free-form replies only work within 24 hours of the customer's last message (WhatsApp's
 * rule); after that only an approved template can be sent.
 */
class WhatsAppInboxService
{
    public function __construct(protected InteraktChannel $interakt) {}

    // ------------------------------------------------------------------ conversations

    public function conversation(string $phone, ?string $name = null): Conv
    {
        $conv = Conv::firstOrCreate(['phone' => $phone], ['name' => $name ? mb_substr($name, 0, 120) : null, 'status' => Conv::OPEN]);
        if ($name && !$conv->name) {
            $conv->update(['name' => mb_substr($name, 0, 120)]);
        }

        return $conv;
    }

    /**
     * The number's open lead and customer, and who the chat belongs to.
     */
    public function link(Conv $conv): Conv
    {
        $ten = substr($conv->phone, -10);
        $lead = Lead::open()->where('phone', 'like', "%{$ten}")->latest('id')->first();
        $customer = $conv->customer_id ? Customer::find($conv->customer_id) : self::customerByPhone($conv->phone);
        $job = $customer ? self::openJob($customer->id) : null;

        $conv->fill([
            'lead_id' => $lead?->id ?? $conv->lead_id,
            'customer_id' => $customer?->id ?? $lead?->customer_id ?? $conv->customer_id,
            'branch_id' => $job?->branch_id ?? $lead?->branch_id ?? $conv->branch_id,
            'name' => $conv->name ?: ($customer?->name ?? $lead?->name),
        ]);
        // An open job's advisor answers its customer; otherwise the lead's executive
        $owner = $job?->service_advisor_id ?? $lead?->assigned_to;
        if ($owner && (!$conv->assigned_to || $conv->isDirty('lead_id'))) {
            $conv->assigned_to = $owner;
        }
        $conv->save();

        return $conv;
    }

    public static function customerByPhone(string $phone): ?Customer
    {
        $ten = substr($phone, -10);

        return Customer::where(fn ($q) => $q->whereRaw("REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?", ["%{$ten}"])
            ->orWhereRaw("REPLACE(REPLACE(REPLACE(COALESCE(whatsapp_phone, ''), ' ', ''), '-', ''), '+', '') LIKE ?", ["%{$ten}"]))
            ->orderByDesc('id')->first();
    }

    public static function openJob(int $customerId): ?JobCard
    {
        return JobCard::withoutGlobalScopes()->where('customer_id', $customerId)
            ->whereNotIn('status', [JobCardStatus::Delivered->value, JobCardStatus::Cancelled->value])
            ->latest('id')->first(['id', 'job_number', 'branch_id', 'service_advisor_id', 'customer_id', 'status']);
    }

    public function canSee(User $user, Conv $conv): bool
    {
        if (self::seesAll($user)) {
            return true;
        }
        if ($conv->assigned_to === $user->id || $conv->assigned_to === null) {
            return true;
        }
        if ($conv->lead_id && Lead::whereKey($conv->lead_id)->value('assigned_to') === $user->id) {
            return true;
        }

        return $conv->customer_id && self::openJob($conv->customer_id)?->service_advisor_id === $user->id;
    }

    public static function seesAll(User $user): bool
    {
        return $user->hasPermission('crm.leads.manage') || $user->hasPermission('crm.whatsapp.manage');
    }

    /**
     * @param string $filter mine | unassigned | all | unread
     */
    public function list(User $user, string $filter = 'mine', ?string $search = null, string $status = Conv::OPEN): array
    {
        $all = self::seesAll($user);
        $mineLeadIds = Lead::where('assigned_to', $user->id)->pluck('id');
        $mineCustomerIds = JobCard::withoutGlobalScopes()->where('service_advisor_id', $user->id)
            ->whereNotIn('status', [JobCardStatus::Delivered->value, JobCardStatus::Cancelled->value])->pluck('customer_id');

        $mine = fn ($q) => $q->where('assigned_to', $user->id)->orWhereIn('lead_id', $mineLeadIds)->orWhereIn('customer_id', $mineCustomerIds);

        $rows = Conv::with(['lead:id,name,status,assigned_to', 'customer:id,name,customer_type,company_name', 'assignee:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when(match ($filter) {
                'mine' => 'mine',
                'unassigned' => 'unassigned',
                // all / unread: managers everything, others theirs + nobody's
                default => $all ? null : 'visible',
            }, fn ($q, $scope) => match ($scope) {
                'mine' => $q->where(fn ($w) => $mine($w)),
                'unassigned' => $q->whereNull('assigned_to'),
                'visible' => $q->where(fn ($w) => $mine($w)->orWhereNull('assigned_to')),
            })
            ->when($filter === 'unread', fn ($q) => $q->where('unread', '>', 0))
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w->where('phone', 'like', '%' . preg_replace('/\D/', '', $s) . '%')->orWhere('name', 'like', "%{$s}%")->orWhere('last_preview', 'like', "%{$s}%")))
            ->orderByDesc('last_message_at')->limit(100)->get();

        return $rows->map(fn (Conv $c) => $this->present($c))->values()->all();
    }

    public function present(Conv $c): array
    {
        return [
            'id' => $c->id,
            'phone' => $c->phone,
            'name' => $c->name ?? $c->customer?->name ?? $c->lead?->name,
            'status' => $c->status,
            'unread' => $c->unread,
            'last_preview' => $c->last_preview,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'window_open' => $c->windowOpen(),
            'window_closes_at' => $c->windowOpen() ? $c->windowClosesAt()?->toIso8601String() : null,
            'lead' => $c->lead ? ['id' => $c->lead->id, 'name' => $c->lead->name, 'status' => $c->lead->status] : null,
            'customer' => $c->customer ? ['id' => $c->customer->id, 'name' => $c->customer->display_name] : null,
            'assigned_to' => $c->assignee ? ['id' => $c->assignee->id, 'name' => $c->assignee->name] : null,
            'from_ad' => !empty($c->referral),
        ];
    }

    public function thread(Conv $conv, User $user): array
    {
        $conv->loadMissing(['lead:id,name,status,assigned_to,phone', 'customer:id,name,customer_type,company_name', 'assignee:id,name']);
        if ($conv->unread) {
            $conv->update(['unread' => 0]);
        }
        $job = $conv->customer_id ? self::openJob($conv->customer_id) : null;
        $messages = Msg::with('user:id,name')->where('conversation_id', $conv->id)->orderByDesc('id')->limit(200)->get()->reverse();

        return [
            'conversation' => $this->present($conv) + [
                'job' => $job ? ['id' => $job->id, 'job_number' => $job->job_number, 'status' => $job->status] : null,
                'first_text' => $conv->first_text,
                'referral' => $conv->referral,
                'opted_out' => $this->optedOut($conv),
            ],
            'messages' => $messages->map(fn (Msg $m) => $this->presentMessage($m))->values(),
            'templates' => $conv->windowOpen() ? [] : $this->templates(),
            'api_on' => WhatsAppSettings::apiOn(),
        ];
    }

    public function presentMessage(Msg $m): array
    {
        return [
            'id' => $m->id,
            'direction' => $m->direction,
            'kind' => $m->kind,
            'body' => $m->body,
            'media_url' => $m->media_url,
            'file_name' => $m->file_name,
            'status' => $m->status,
            'error' => $m->error,
            'by' => $m->user?->name,
            'at' => $m->created_at?->toIso8601String(),
        ];
    }

    /**
     * Approved templates a person may send when the 24-hour window is closed: the ones with
     * no values to fill, or only the customer's name.
     */
    public function templates(): array
    {
        return WhatsAppTemplate::where('is_active', true)->whereNotNull('provider_name')->where('provider_name', '!=', '')
            ->where(fn ($q) => $q->whereNull('approval_status')->orWhere('approval_status', 'APPROVED'))
            ->where('header_type', 'none')
            ->orderBy('label')->get()
            ->filter(fn (WhatsAppTemplate $t) => count(array_filter($t->body_params ?? [], fn ($p) => !in_array($p, ['name', 'workshop', 'body'], true))) === 0)
            ->map(fn (WhatsAppTemplate $t) => ['key' => $t->key, 'label' => $t->label, 'name' => $t->provider_name, 'params' => $t->body_params ?? []])
            ->values()->all();
    }

    // ------------------------------------------------------------------ sending

    /**
     * A free-form reply (text, or a file with a caption) inside the 24-hour window.
     */
    public function reply(Conv $conv, User $user, ?string $text, ?UploadedFile $file = null): Msg
    {
        $this->assertCanSend($conv);
        if (!$conv->windowOpen()) {
            throw new CrmException('The 24-hour window has closed: WhatsApp only allows an approved template now. Choose one below, or call them.', ['body' => ['Window closed.']]);
        }
        $text = $text !== null ? trim($text) : null;
        if (($text === null || $text === '') && !$file) {
            throw new CrmException('Type a message or attach a file.', ['body' => ['Required.']]);
        }

        $kind = 'text';
        $mediaUrl = null;
        $fileName = null;
        if ($file) {
            $mime = (string) $file->getMimeType();
            $kind = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'audio/') ? 'audio' : 'document'));
            $fileName = mb_substr($file->getClientOriginalName() ?: 'file', 0, 200);
            $mediaUrl = $this->storeMedia($file);
        }

        $msg = Msg::create([
            'conversation_id' => $conv->id,
            'direction' => Msg::OUT,
            'kind' => $kind,
            'body' => $text ?: null,
            'media_url' => $mediaUrl,
            'file_name' => $fileName,
            'status' => 'queued',
            'user_id' => $user->id,
        ]);

        $result = $this->interakt->sendSession($conv->phone, $kind, $text, $mediaUrl, $fileName, 'chat:' . $msg->id);
        $msg->update($result['ok']
            ? ['status' => 'sent', 'provider_message_id' => $result['id']]
            : ['status' => 'failed', 'error' => mb_substr((string) $result['error'], 0, 300)]);
        if (!empty($result['window_closed'])) {
            // Interakt knows better: the customer's last message was over 24 hours ago
            $conv->update(['last_inbound_at' => now()->subHours(Conv::WINDOW_HOURS + 1)]);
        }
        $this->touch($conv, $text ?: "📎 {$fileName}");
        $this->logOnLead($conv, $text ?: "Sent a file: {$fileName}", $user);

        return $msg->fresh();
    }

    /**
     * An approved template from the chat (the window is closed, or just to send one).
     *
     * @param array<string, string> $values extra values for the template's placeholders
     */
    public function sendTemplate(Conv $conv, User $user, string $key, array $values = []): Msg
    {
        $this->assertCanSend($conv);
        $template = WhatsAppTemplate::where('key', $key)->first();
        if (!$template?->isMapped()) {
            throw new CrmException('That template is not set up in CRM › WhatsApp › Templates.');
        }
        $conv->loadMissing(['customer', 'lead']);
        $replace = [
            'name' => $conv->customer?->name ?? $conv->lead?->name ?? $conv->name ?? '',
            'workshop' => config('app.name'),
            ...$values,
        ];
        $body = collect($template->body_params ?? [])->map(fn ($p) => self::clean($replace[$p] ?? ''))->all();

        $msg = Msg::create([
            'conversation_id' => $conv->id,
            'direction' => Msg::OUT,
            'kind' => 'template',
            'body' => $template->label . ($body ? ' — ' . implode(' · ', $body) : ''),
            'status' => 'queued',
            'user_id' => $user->id,
        ]);
        $result = $this->interakt->sendTemplate($conv->phone, $template->provider_name, $template->language, $body, [], [], 'chat:' . $msg->id);
        $msg->update($result['ok']
            ? ['status' => 'sent', 'provider_message_id' => $result['id']]
            : ['status' => 'failed', 'error' => mb_substr((string) $result['error'], 0, 300)]);
        $this->touch($conv, '📄 ' . $template->label);
        $this->logOnLead($conv, 'Sent the WhatsApp template "' . $template->label . '"', $user);

        return $msg->fresh();
    }

    /**
     * A template message sent from elsewhere (job update, journey, quote) joins the chat.
     */
    public function recordTemplate(CustomerMessage $message): void
    {
        $phone = ShareLinkChannel::normalizePhone($message->phone);
        if (!$phone || Msg::where('customer_message_id', $message->id)->exists()) {
            return;
        }
        $conv = $this->conversation($phone);
        Msg::create([
            'conversation_id' => $conv->id,
            'direction' => Msg::OUT,
            'kind' => 'template',
            'body' => $message->body,
            'media_url' => $message->media_url,
            'provider_message_id' => $message->provider_message_id,
            'customer_message_id' => $message->id,
            'status' => 'sent',
            'user_id' => $message->prepared_by,
        ]);
        $this->touch($conv, mb_strimwidth((string) $message->body, 0, 190, '…'));
        if (!$conv->lead_id && !$conv->customer_id) {
            $conv->forceFill(['lead_id' => $message->lead_id, 'customer_id' => $message->customer_id])->save();
        }
    }

    /**
     * Ticks on our messages in the chat (sent / delivered / read / failed).
     */
    public function statusUpdate(string $type, array $msg): void
    {
        $status = preg_replace('/^message_(api|campaign)_/', '', $type);
        if (!in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }
        $callback = WhatsAppDispatcher::callbackData($msg);
        $row = !empty($msg['id']) ? Msg::where('provider_message_id', (string) $msg['id'])->first() : null;
        if (!$row && $callback && str_starts_with($callback, 'chat:')) {
            $row = Msg::find((int) substr($callback, 5));
        }
        if (!$row && is_numeric($callback)) {
            $row = Msg::where('customer_message_id', (int) $callback)->first();
        }
        if (!$row) {
            return;
        }
        $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];
        if ($status === 'failed') {
            $row->update(['status' => 'failed', 'error' => mb_substr((string) ($msg['channel_failure_reason'] ?? 'Not delivered'), 0, 300)]);
        } elseif (($rank[$status] ?? 0) > ($rank[$row->status] ?? 0)) {
            $row->update(['status' => $status]);
        }
    }

    // ------------------------------------------------------------------ handling

    public function assign(Conv $conv, User $to, User $by): Conv
    {
        $conv->update(['assigned_to' => $to->id]);
        $this->assignInInterakt($conv, $to);
        if ($to->id !== $by->id) {
            $to->notify(new \App\Modules\Workshop\Notifications\StaffAlert('whatsapp_assigned', 'WhatsApp chat for you — ' . ($conv->name ?? $conv->phone), "{$by->name} gave you this chat.", "/crm/whatsapp?c={$conv->id}"));
        }

        return $conv;
    }

    /**
     * With "assign in Interakt" on: the same person gets the chat in Interakt's own inbox.
     */
    public function assignInInterakt(Conv $conv, User $to): void
    {
        if (!WorkshopSettings::get('crm.whatsapp.assign_in_interakt') || !WhatsAppSettings::apiOn() || !$to->email) {
            return;
        }
        $this->interakt->assignChat($conv->phone, $to->email);
    }

    public function setStatus(Conv $conv, string $status): Conv
    {
        $conv->update(['status' => $status === Conv::CLOSED ? Conv::CLOSED : Conv::OPEN, 'unread' => $status === Conv::CLOSED ? 0 : $conv->unread]);

        return $conv;
    }

    /**
     * A chat with no lead: make one (source WhatsApp chat), the first message as the requirement.
     */
    public function createLead(Conv $conv, ?User $user = null, ?int $campaignId = null): Lead
    {
        if ($conv->lead_id && ($lead = Lead::find($conv->lead_id)) && in_array($lead->status, Lead::OPEN, true)) {
            return $lead;
        }
        $campaign = $campaignId ? \App\Modules\Crm\Models\Campaign::find($campaignId) : null;
        [$lead] = app(LeadService::class)->capture([
            'name' => $conv->name,
            'phone' => $conv->phone,
            'lead_source_id' => $campaign?->lead_source_id ?? \App\Modules\Crm\Models\LeadSource::where('slug', 'whatsapp')->value('id'),
            'campaign_id' => $campaign?->id,
            'requirement' => $conv->first_text ? mb_substr($conv->first_text, 0, 1000) : null,
            'tracking' => $conv->referral ? ['whatsapp_referral' => $conv->referral] : null,
            'branch_id' => $conv->branch_id,
            'note' => 'From a WhatsApp chat',
        ], $user);
        $conv->update(['lead_id' => $lead->id]);
        $this->link($conv);

        return $lead;
    }

    public function touch(Conv $conv, ?string $preview, bool $inbound = false): void
    {
        $conv->forceFill([
            'last_message_at' => now(),
            'last_preview' => $preview !== null ? mb_strimwidth($preview, 0, 195, '…') : $conv->last_preview,
            'status' => Conv::OPEN,
        ] + ($inbound ? ['last_inbound_at' => now(), 'unread' => $conv->unread + 1] : []))->save();
    }

    protected function logOnLead(Conv $conv, string $text, ?User $user): void
    {
        if ($conv->lead_id) {
            app(LeadService::class)->log($conv->lead_id, 'whatsapp', null, mb_strimwidth($text, 0, 400, '…'), $user, ['via' => 'inbox']);
        }
    }

    protected function assertCanSend(Conv $conv): void
    {
        if (!WhatsAppSettings::apiOn()) {
            throw new CrmException('Interakt is not switched on (CRM › WhatsApp › Connection). Use the WhatsApp button to reply from the workshop phone.');
        }
    }

    protected function optedOut(Conv $conv): bool
    {
        $ten = substr($conv->phone, -10);

        return Lead::where('phone', 'like', "%{$ten}")->whereNotNull('opted_out_at')->exists()
            || ($conv->customer_id && Customer::whereKey($conv->customer_id)->whereNotNull('marketing_opt_out_at')->exists());
    }

    /**
     * A file for Interakt to fetch: kept private, reachable by an unguessable signed address
     * on the public address (Interakt downloads it when sending).
     */
    protected function storeMedia(UploadedFile $file): string
    {
        $name = Str::uuid() . '.' . ($file->guessExtension() ?: 'bin');
        $file->storeAs('whatsapp-media', $name, 'job_media');

        return PublicUrl::to('/api/v1/public/wa-media/' . $name . '?sig=' . self::mediaSignature($name));
    }

    public static function mediaSignature(string $name): string
    {
        return substr(hash_hmac('sha256', 'wa-media|' . $name, (string) config('app.key')), 0, 32);
    }

    public static function mediaPath(string $name): ?string
    {
        return preg_match('/^[0-9a-f\-]{36}\.[a-z0-9]{1,5}$/', $name) ? 'whatsapp-media/' . $name : null;
    }

    public static function clean(mixed $value): string
    {
        $text = trim(preg_replace(['/[\r\n\t]+/', '/ {3,}/'], [' · ', '  '], (string) $value));

        return $text === '' ? '-' : mb_substr($text, 0, 1000);
    }
}

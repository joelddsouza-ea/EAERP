<?php

namespace App\Modules\Crm\Services\WhatsApp;

use App\Core\Models\User;
use App\Core\Models\WhatsAppTemplate;
use App\Core\Services\WhatsApp\ShareLinkChannel;
use App\Core\Services\WhatsApp\WhatsAppDispatcher;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\WhatsAppChatMessage as Msg;
use App\Modules\Crm\Services\LeadService;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Notifications\StaffAlert;

/**
 * A customer tapped a quick-reply button on one of our templates (Interakt
 * "message_api_clicked"). What a tap does is set per template and button in CRM › WhatsApp ›
 * Templates — lead actions only (the owner's choice):
 *  - interested: the lead is marked interested, follow-up today;
 *  - call_me: follow-up now and a bell to the executive;
 *  - not_now: follow-up in 30 days.
 * Every tap shows in the chat and on the lead.
 */
class WhatsAppButtonService
{
    public const ACTIONS = [
        'interested' => 'Mark the lead interested (follow up today)',
        'call_me' => 'Call me: follow up now + bell',
        'not_now' => 'Not now: follow up in 30 days',
    ];

    public function __construct(
        protected LeadService $leads,
        protected WhatsAppInboxService $inbox,
        protected WhatsAppDispatcher $dispatcher,
    ) {}

    public function handle(array $payload): string
    {
        $data = $payload['data'] ?? [];
        $msg = $data['message'] ?? [];
        // Call-to-action taps come in a top-level "event"
        $event = $payload['event'] ?? [];
        $button = trim((string) ($msg['button_text'] ?? $event['button_text'] ?? $msg['button_payload']['payload']['text'] ?? ''));
        if ($button === '') {
            return 'no button';
        }
        $clickType = strtoupper((string) ($msg['click_type'] ?? $event['click_type'] ?? 'QR'));
        if (!isset($msg['meta_data']) && isset($event['callbackData'])) {
            $msg['callback_data'] = $event['callbackData'];
        }

        $message = $this->dispatcher->findMessage($msg);
        $phone = $message?->phone ? ShareLinkChannel::normalizePhone($message->phone) : WhatsAppDispatcher::senderPhone($data);

        if ($phone) {
            $conv = $this->inbox->link($this->inbox->conversation($phone));
            Msg::create([
                'conversation_id' => $conv->id,
                'direction' => Msg::IN,
                'kind' => 'button',
                'body' => ($clickType === 'CTA' ? 'Opened: ' : 'Tapped: ') . $button,
                'status' => 'received',
                'raw' => ['click' => $msg ?: $event],
            ]);
            $this->inbox->touch($conv, "Tapped: {$button}", inbound: $clickType !== 'CTA');
        }

        if ($clickType === 'CTA') {
            return 'link opened';
        }

        $action = $this->actionFor($message, $button);
        $lead = $this->leadFor($message, $phone);
        if (!$lead) {
            return $action ? 'no lead' : 'tap noted';
        }

        $this->leads->log($lead, 'reply', null, "Tapped \"{$button}\" on WhatsApp", null, ['via' => 'interakt', 'button' => $button]);
        if (!$action) {
            return 'tap noted';
        }

        match ($action) {
            'interested' => $this->interested($lead),
            'call_me' => $this->callMe($lead, $button),
            'not_now' => $lead->update(['next_follow_up_at' => now()->addDays(30)]),
            default => null,
        };

        return "button: {$action}";
    }

    /**
     * The action set for this button on the message's template (its creative's own, else its kind's).
     */
    public function actionFor(?CustomerMessage $message, string $button): ?string
    {
        if (!$message) {
            return null;
        }
        $template = $message->creative_id
            ? WhatsAppTemplate::where('key', "creative:{$message->creative_id}")->first()
            : null;
        $template ??= WhatsAppTemplate::where('key', $message->template)->first();
        $actions = $template?->button_actions ?? [];
        foreach ($actions as $text => $action) {
            if (mb_strtolower(trim((string) $text)) === mb_strtolower($button) && isset(self::ACTIONS[$action])) {
                return $action;
            }
        }

        return null;
    }

    protected function leadFor(?CustomerMessage $message, ?string $phone): ?Lead
    {
        if ($message?->lead_id && ($lead = Lead::find($message->lead_id)) && in_array($lead->status, Lead::OPEN, true)) {
            return $lead;
        }

        return $phone ? Lead::open()->where('phone', 'like', '%' . substr($phone, -10))->latest('id')->first() : null;
    }

    protected function interested(Lead $lead): void
    {
        if (in_array($lead->status, [Lead::NEW, Lead::CONTACTED, Lead::UNREACHABLE], true)) {
            $this->leads->setStatus($lead, Lead::INTERESTED);
        }
        $lead->update(['next_follow_up_at' => now()]);
        $this->leads->bumpScore($lead, 15);
    }

    protected function callMe(Lead $lead, string $button): void
    {
        $lead->update(['next_follow_up_at' => now()]);
        $this->leads->bumpScore($lead, 20);
        if ($lead->assigned_to) {
            User::find($lead->assigned_to)?->notify(new StaffAlert(
                'whatsapp_call_me',
                "Call now — {$lead->name}",
                "Tapped \"{$button}\" on WhatsApp.",
                "/crm/leads/{$lead->id}",
            ));
        }
    }
}

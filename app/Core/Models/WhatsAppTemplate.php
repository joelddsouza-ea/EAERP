<?php

namespace App\Core\Models;

use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\Creative;
use App\Modules\Workshop\Models\WorkflowStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Which approved WhatsApp (Interakt) template a kind of message goes out with, and which of
 * our placeholders fill its {{1}}, {{2}} … A kind without a template name keeps the share
 * link: the message waits in "Messages to send" as before.
 */
class WhatsAppTemplate extends Model
{
    public const HEADER_TYPES = ['none', 'image', 'video', 'document'];

    // Job-card messages from lang/en/customer_messages.php (stage messages are added from the Job stages master)
    public const JOB_KEYS = [
        'estimate_ready' => 'Estimate ready for approval',
        'approval_reminder' => 'Estimate approval reminder',
        'decision_recorded' => 'Decision recorded on the customer\'s behalf',
        'media_shared' => 'Photos / videos shared',
        'gallery_update' => 'New photos / videos (gallery)',
        'payment_received' => 'Payment received',
        'report_ready' => 'Service report ready',
        'drop_en_route' => 'Car on its way back (drop)',
    ];

    // CRM messages: key => [label, setting holding the text]
    public const CRM_KEYS = [
        'crm.quote' => ['Plan quote to a lead', 'crm.messages.plan_quote'],
        'crm.booking_confirmed' => ['Booking confirmed', 'crm.messages.booking_confirmed'],
        'crm.booking_reminder' => ['Booking reminder', 'crm.messages.booking_reminder'],
    ];

    protected $table = 'whatsapp_templates';

    protected $fillable = ['key', 'label', 'provider_name', 'language', 'body_params', 'header_type', 'button_param', 'is_active'];

    protected $casts = [
        'body_params' => 'array',
        'is_active' => 'boolean',
    ];

    public function isMapped(): bool
    {
        return $this->is_active && trim((string) $this->provider_name) !== '';
    }

    /**
     * Every kind of message the system sends now, with its current text (to submit to Interakt
     * for approval) and the placeholders it uses in order.
     *
     * @return array<string, array{label: string, group: string, text: string, header: string}>
     */
    public static function catalog(): array
    {
        $items = [];

        if (Schema::hasTable('workflow_stages')) {
            foreach (WorkflowStage::orderBy('sort_order')->get() as $stage) {
                $text = trim((string) $stage->message);
                if ($text === '' && ($template = config("workshop.customer_channel.status_templates.{$stage->status}"))) {
                    $text = trans("customer_messages.{$template}");
                }
                if ($text === '' && !$stage->notify_customer) {
                    continue;
                }
                $items["stage:{$stage->status}"] = ['label' => 'Stage: ' . $stage->label, 'group' => 'Job stages', 'text' => $text, 'header' => 'none'];
            }
        }

        foreach (self::JOB_KEYS as $key => $label) {
            $items[$key] = ['label' => $label, 'group' => 'Job card', 'text' => trans("customer_messages.{$key}"), 'header' => 'none'];
        }

        foreach (self::CRM_KEYS as $key => [$label, $setting]) {
            $items[$key] = ['label' => $label, 'group' => 'CRM', 'text' => (string) WorkshopSettings::get($setting), 'header' => 'none'];
        }

        // Customer app sign-in (an Authentication template in Interakt, with a copy-code button)
        $items['customer_otp'] = [
            'label' => 'Customer app sign-in code',
            'group' => 'Customer app',
            'text' => ':code is your Express Autocare sign-in code. It is valid for :minutes minutes. Do not share it.',
            'header' => 'none',
        ];

        if (Schema::hasTable('creatives')) {
            foreach (Creative::where('is_active', true)->orderBy('id')->get() as $creative) {
                $items["creative:{$creative->id}"] = [
                    'label' => 'Creative: ' . $creative->title,
                    'group' => 'CRM creatives',
                    'text' => (string) $creative->caption,
                    'header' => match ($creative->kind) { 'image', 'video' => $creative->kind, 'pdf' => 'document', default => 'none' },
                ];
            }
        }

        return $items;
    }

    /**
     * Our placeholders in the order they first appear in a text (":name … :car" → ["name", "car"]).
     *
     * @return array<int, string>
     */
    public static function placeholders(string $text): array
    {
        preg_match_all('/(?<![\w\/]):([a-z][a-z_]*)/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * The text written the way Interakt wants it: placeholders replaced by {{1}}, {{2}} …
     */
    public static function providerText(string $text, array $params): string
    {
        // Longest first, so :name never eats :name_x
        $order = $params;
        usort($order, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($order as $param) {
            $n = array_search($param, $params, true) + 1;
            $text = preg_replace('/(?<![\w\/]):' . preg_quote($param, '/') . '\b/', '{{' . $n . '}}', $text);
        }

        return $text;
    }
}

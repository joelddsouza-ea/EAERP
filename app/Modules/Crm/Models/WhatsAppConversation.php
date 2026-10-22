<?php

namespace App\Modules\Crm\Models;

use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * One WhatsApp number's chat with the workshop: what came in through Interakt and what we
 * sent (free-form replies and templates). Linked to the number's lead and / or customer.
 */
class WhatsAppConversation extends Model
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    /** WhatsApp allows free-form replies this long after the customer's last message */
    public const WINDOW_HOURS = 24;

    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'phone', 'name', 'lead_id', 'customer_id', 'assigned_to', 'branch_id', 'last_inbound_at', 'last_message_at',
        'last_preview', 'unread', 'status', 'first_text', 'referral', 'interakt_customer_id',
    ];

    protected $casts = [
        'last_inbound_at' => 'datetime',
        'last_message_at' => 'datetime',
        'unread' => 'integer',
        'referral' => 'array',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages()
    {
        return $this->hasMany(WhatsAppChatMessage::class, 'conversation_id');
    }

    public function windowOpen(): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subHours(self::WINDOW_HOURS));
    }

    public function windowClosesAt(): ?\Illuminate\Support\Carbon
    {
        return $this->last_inbound_at?->copy()->addHours(self::WINDOW_HOURS);
    }
}

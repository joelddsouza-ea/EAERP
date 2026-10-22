<?php

namespace App\Modules\Crm\Models;

use App\Core\Models\User;
use App\Modules\Workshop\Models\CustomerMessage;
use Illuminate\Database\Eloquent\Model;

/**
 * A message in a WhatsApp conversation, either way.
 */
class WhatsAppChatMessage extends Model
{
    public const IN = 'in';
    public const OUT = 'out';

    protected $table = 'whatsapp_chat_messages';

    protected $fillable = [
        'conversation_id', 'direction', 'kind', 'body', 'media_url', 'file_name', 'provider_message_id',
        'customer_message_id', 'status', 'error', 'user_id', 'raw',
    ];

    protected $casts = ['raw' => 'array'];

    public function conversation()
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customerMessage()
    {
        return $this->belongsTo(CustomerMessage::class);
    }
}

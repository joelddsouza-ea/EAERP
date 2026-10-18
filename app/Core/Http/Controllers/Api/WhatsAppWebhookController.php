<?php

namespace App\Core\Http\Controllers\Api;

use App\Core\Services\WhatsApp\WhatsAppDispatcher;
use App\Core\Services\WhatsApp\WhatsAppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Interakt calls this (Developer Settings › Webhook URL) with delivery updates and replies.
 * The secret in the address is the only key: it is shown in Settings › WhatsApp.
 */
class WhatsAppWebhookController extends ApiController
{
    public function interakt(Request $request, string $secret, WhatsAppDispatcher $dispatcher): JsonResponse
    {
        if (!hash_equals(WhatsAppSettings::webhookSecret(), $secret)) {
            return $this->error('Not found.', 404);
        }

        return $this->success(['handled' => $dispatcher->handleWebhook($request->all())]);
    }
}

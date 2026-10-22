<?php

namespace App\Core\Http\Controllers\Api;

use App\Core\Services\WhatsApp\WhatsAppDispatcher;
use App\Core\Services\WhatsApp\WhatsAppSettings;
use App\Modules\Crm\Models\InteraktWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Interakt calls this (Developer Settings › Webhook URL) with delivery updates, button taps,
 * customers' messages, template approvals and account alerts.
 *
 * The secret in the address is one key; when a signing secret is set (CRM › WhatsApp ›
 * Connection, same as in Interakt) the Interakt-Signature header must match too.
 * Interakt waits at most 3 seconds and switches the webhook off after 5 failures in 10
 * minutes, so the call is logged and answered at once and handled after the response.
 */
class WhatsAppWebhookController extends ApiController
{
    public function interakt(Request $request, string $secret): JsonResponse
    {
        if (!hash_equals(WhatsAppSettings::webhookSecret(), $secret)) {
            return $this->error('Not found.', 404);
        }
        if (!WhatsAppSettings::signatureValid($request->getContent(), $request->header('Interakt-Signature'))) {
            return $this->error('Bad signature.', 401);
        }

        $payload = $request->all();
        $event = InteraktWebhookEvent::create([
            'type' => mb_substr((string) ($payload['type'] ?? $payload['field'] ?? 'unknown'), 0, 60),
            'payload' => $payload,
        ]);
        $id = $event->id;

        dispatch(function () use ($id) {
            // Once only (a long-lived worker could run the same after-response callback again)
            $event = InteraktWebhookEvent::whereNull('processed_at')->find($id);
            if (!$event) {
                return;
            }
            $event->update(['processed_at' => now()]);
            try {
                $result = app(WhatsAppDispatcher::class)->handleWebhook($event->payload ?? []);
                $event->update(['result' => mb_substr($result, 0, 60), 'processed_at' => now()]);
            } catch (\Throwable $e) {
                $event->update(['error' => mb_substr($e->getMessage(), 0, 500), 'processed_at' => now()]);
                if (app()->runningUnitTests()) {
                    throw $e;
                }
                report($e);
            }
        })->afterResponse();

        return $this->success(['received' => $id]);
    }
}

<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\User;
use App\Core\Services\WhatsApp\ShareLinkChannel;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\WhatsAppConversation as Conv;
use App\Modules\Crm\Services\CrmException;
use App\Modules\Crm\Services\WhatsApp\InteraktSyncService;
use App\Modules\Crm\Services\WhatsApp\WhatsAppInboxService;
use App\Modules\Crm\Services\WhatsApp\WhatsAppTemplateSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * CRM › WhatsApp: the inbox (chats with leads and customers through Interakt), and the
 * Interakt tools that belong to the CRM — template fetch, contact / event sync status.
 */
class WhatsAppController extends ApiController
{
    public function __construct(protected WhatsAppInboxService $inbox) {}

    protected function guarded(callable $fn, string $message = 'Success'): JsonResponse
    {
        try {
            return $this->success($fn(), $message);
        } catch (CrmException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors);
        }
    }

    protected function visible(Request $request, Conv $conv): Conv
    {
        abort_unless($this->inbox->canSee($request->user(), $conv), 404);

        return $conv;
    }

    // ------------------------------------------------------------------ inbox

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filter' => 'nullable|in:mine,unassigned,all,unread',
            'status' => 'nullable|in:open,closed,all',
            'search' => 'nullable|string|max:60',
        ]);

        return $this->success([
            'conversations' => $this->inbox->list($request->user(), $data['filter'] ?? 'mine', $data['search'] ?? null, $data['status'] ?? Conv::OPEN),
            'sees_all' => WhatsAppInboxService::seesAll($request->user()),
            'api_on' => \App\Core\Services\WhatsApp\WhatsAppSettings::apiOn(),
        ]);
    }

    /** The nav badge: unread chats of mine (or all, for managers) */
    public function unread(Request $request): JsonResponse
    {
        $rows = $this->inbox->list($request->user(), 'unread');

        return $this->success(['chats' => count($rows)]);
    }

    public function show(Request $request, Conv $conversation): JsonResponse
    {
        return $this->success($this->inbox->thread($this->visible($request, $conversation), $request->user()));
    }

    /**
     * The chat with a lead's number (the lead page), started if there is none yet.
     */
    public function forLead(Request $request, Lead $lead): JsonResponse
    {
        $phone = ShareLinkChannel::normalizePhone($lead->phone);
        if (!$phone) {
            return $this->success(null);
        }
        $conv = Conv::where('phone', $phone)->first();
        if (!$conv) {
            return $this->success(['conversation' => null, 'messages' => [], 'templates' => $this->inbox->templates(), 'api_on' => \App\Core\Services\WhatsApp\WhatsAppSettings::apiOn(), 'phone' => $phone]);
        }

        return $this->success($this->inbox->thread($conv, $request->user()));
    }

    /** Start a chat from the lead page (sending a template opens it) */
    public function startForLead(Request $request, Lead $lead): JsonResponse
    {
        $phone = ShareLinkChannel::normalizePhone($lead->phone) ?? abort(422, 'The lead has no valid mobile number.');
        $conv = $this->inbox->link($this->inbox->conversation($phone, $lead->name));

        return $this->success($this->inbox->thread($conv, $request->user()));
    }

    public function reply(Request $request, Conv $conversation): JsonResponse
    {
        $conv = $this->visible($request, $conversation);
        $data = $request->validate([
            'body' => 'nullable|string|max:4096',
            'file' => 'nullable|file|max:16384|mimetypes:image/jpeg,image/png,image/webp,application/pdf,video/mp4,video/3gpp,audio/mpeg,audio/ogg,audio/aac,audio/mp4,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);

        return $this->guarded(fn () => $this->inbox->presentMessage($this->inbox->reply($conv, $request->user(), $data['body'] ?? null, $request->file('file'))), 'Sent');
    }

    public function template(Request $request, Conv $conversation): JsonResponse
    {
        $conv = $this->visible($request, $conversation);
        $data = $request->validate(['key' => 'required|string|max:60', 'values' => 'nullable|array|max:10', 'values.*' => 'nullable|string|max:500']);

        return $this->guarded(fn () => $this->inbox->presentMessage($this->inbox->sendTemplate($conv, $request->user(), $data['key'], array_filter($data['values'] ?? [], fn ($v) => $v !== null))), 'Sent');
    }

    public function assign(Request $request, Conv $conversation): JsonResponse
    {
        $conv = $this->visible($request, $conversation);
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $me = $request->user();
        // Anyone may take a chat for themselves; giving it to someone else is for managers
        abort_unless((int) $data['user_id'] === $me->id || WhatsAppInboxService::seesAll($me), 403);
        $to = User::active()->findOrFail($data['user_id']);

        return $this->success($this->inbox->present($this->inbox->assign($conv, $to, $me)->fresh(['lead', 'customer', 'assignee'])), "Given to {$to->name}");
    }

    public function status(Request $request, Conv $conversation): JsonResponse
    {
        $conv = $this->visible($request, $conversation);
        $data = $request->validate(['status' => 'required|in:open,closed']);

        return $this->success($this->inbox->present($this->inbox->setStatus($conv, $data['status'])->fresh(['lead', 'customer', 'assignee'])), $data['status'] === 'closed' ? 'Chat closed' : 'Chat reopened');
    }

    public function createLead(Request $request, Conv $conversation): JsonResponse
    {
        $conv = $this->visible($request, $conversation);
        $data = $request->validate(['campaign_id' => 'nullable|integer|exists:campaigns,id']);

        return $this->guarded(fn () => ['lead_id' => $this->inbox->createLead($conv, $request->user(), $data['campaign_id'] ?? null)->id], 'Lead created');
    }

    /** Staff who can answer chats (for "Give to") */
    public function people(): JsonResponse
    {
        return $this->success(User::active()->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'crm.whatsapp.inbox'))
            ->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values());
    }

    // ------------------------------------------------------------------ Interakt tools (manage)

    public function fetchTemplates(WhatsAppTemplateSync $sync): JsonResponse
    {
        $r = $sync->fetch();

        // Not fetched: the screen says why and keeps the names typed in by hand
        return $this->success($r, $r['ok'] ? 'Fetched ' . count($r['templates']) . ' templates from Interakt' : (string) $r['error']);
    }

    public function cachedTemplates(WhatsAppTemplateSync $sync): JsonResponse
    {
        return $this->success(['templates' => $sync->cached()]);
    }

    public function syncStatus(InteraktSyncService $sync): JsonResponse
    {
        return $this->success($sync->status() + ['contacts_on' => InteraktSyncService::contactsOn(), 'events_on' => InteraktSyncService::eventsOn(), 'events' => InteraktSyncService::EVENTS]);
    }

    public function syncAll(InteraktSyncService $sync): JsonResponse
    {
        if (!InteraktSyncService::contactsOn()) {
            return $this->error('Switch on Interakt and "Send contacts to Interakt" first.', 422);
        }
        $n = $sync->queueAll();

        return $this->success($sync->status(), "{$n} contacts queued: they go to Interakt over the next minutes (about 250 every 15 minutes).");
    }

    // ------------------------------------------------------------------ public (Interakt fetches our attachments)

    public function media(Request $request, string $name)
    {
        $path = WhatsAppInboxService::mediaPath($name);
        abort_unless($path && hash_equals(WhatsAppInboxService::mediaSignature($name), (string) $request->query('sig')), 404);
        abort_unless(Storage::disk('job_media')->exists($path), 404);

        return Storage::disk('job_media')->response($path);
    }
}

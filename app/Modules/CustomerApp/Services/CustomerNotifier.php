<?php

namespace App\Modules\CustomerApp\Services;

use App\Core\Support\Modules;
use App\Core\Support\WorkshopSettings;
use App\Modules\CustomerApp\Models\CustomerNotification;
use App\Modules\CustomerApp\Models\CustomerPushSubscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

/**
 * The customer app's inbox, and phone notifications for customers who installed the web app
 * (Web Push, VAPID keys made on first use). A reminder with a dedupe key is written once.
 * For a fleet, $driverId sends it to one of its drivers instead of the owner.
 */
class CustomerNotifier
{
    public function notify(int $customerId, string $kind, string $title, ?string $body = null, ?string $link = null, ?string $dedupe = null, ?int $driverId = null): ?CustomerNotification
    {
        if (!Modules::customerApp()) {
            return null;
        }

        if ($dedupe && CustomerNotification::where('customer_id', $customerId)->where('recipient_key', (int) $driverId)->where('dedupe_key', $dedupe)->exists()) {
            return null;
        }

        try {
            $n = CustomerNotification::create([
                'customer_id' => $customerId,
                'fleet_driver_id' => $driverId,
                'recipient_key' => (int) $driverId,
                'kind' => $kind,
                'title' => mb_substr($title, 0, 150),
                'body' => $body ? mb_substr($body, 0, 500) : null,
                'link' => $link,
                'dedupe_key' => $dedupe,
            ]);
        } catch (QueryException $e) {
            // Same reminder already sent
            if ($dedupe && str_contains($e->getMessage(), 'Duplicate')) {
                return null;
            }
            throw $e;
        }

        // After the change is saved (and not inside a request's transaction)
        DB::afterCommit(fn () => $this->push($n));

        return $n;
    }

    public function push(CustomerNotification $n): void
    {
        if (!WorkshopSettings::get('customer_app.push_enabled')) {
            return;
        }
        $subs = CustomerPushSubscription::where('customer_id', $n->customer_id)
            ->where(fn ($q) => $n->fleet_driver_id ? $q->where('fleet_driver_id', $n->fleet_driver_id) : $q->whereNull('fleet_driver_id'))
            ->get();
        if ($subs->isEmpty()) {
            return;
        }

        try {
            $webPush = new WebPush(['VAPID' => [...$this->vapid(), 'subject' => 'mailto:support@expressautocare.in']], ['TTL' => 86400]);
            $payload = json_encode(['title' => $n->title, 'body' => $n->body, 'url' => $n->link ?: '/app/inbox', 'tag' => $n->kind]);
            foreach ($subs as $sub) {
                $webPush->queueNotification(Subscription::create(['endpoint' => $sub->endpoint, 'keys' => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth]]), $payload);
            }
            foreach ($webPush->flush() as $report) {
                if ($report->isSubscriptionExpired()) {
                    CustomerPushSubscription::where('endpoint_hash', hash('sha256', $report->getEndpoint()))->delete();
                } elseif (!$report->isSuccess()) {
                    CustomerPushSubscription::where('endpoint_hash', hash('sha256', $report->getEndpoint()))->update(['last_error_at' => now()]);
                }
            }
            $n->update(['pushed_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The server's push keys: made once and kept in the settings (private key encrypted).
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public function vapid(): array
    {
        $public = WorkshopSettings::get('customer_app.vapid_public');
        $private = WorkshopSettings::get('customer_app.vapid_private');
        if ($public && $private) {
            return ['publicKey' => $public, 'privateKey' => Crypt::decryptString($private)];
        }

        $keys = $this->newVapidKeys();
        WorkshopSettings::set('customer_app.vapid_public', $keys['publicKey']);
        WorkshopSettings::set('customer_app.vapid_private', Crypt::encryptString($keys['privateKey']));

        return $keys;
    }

    /**
     * The public key the app needs to subscribe, or null when this server can't make push keys.
     */
    public function publicKey(): ?string
    {
        try {
            return $this->vapid()['publicKey'];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * A P-256 key pair in the form Web Push wants (base64url). On Windows, PHP's OpenSSL needs its
     * config file named to make EC keys, so it is passed when the plain call fails.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    protected function newVapidKeys(): array
    {
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = @openssl_pkey_new($options);
        foreach ([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf'] as $config) {
            if ($key === false && $config && is_file($config)) {
                $key = @openssl_pkey_new([...$options, 'config' => $config]);
            }
        }
        if ($key === false) {
            return VAPID::createVapidKeys();
        }

        $ec = openssl_pkey_get_details($key)['ec'];
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        $pad = fn (string $v) => str_pad($v, 32, chr(0), STR_PAD_LEFT);

        return ['publicKey' => $b64(chr(4) . $pad($ec['x']) . $pad($ec['y'])), 'privateKey' => $b64($pad($ec['d']))];
    }

    public function subscribe(int $customerId, array $data, ?string $device, ?int $driverId = null): void
    {
        CustomerPushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            ['customer_id' => $customerId, 'fleet_driver_id' => $driverId, 'endpoint' => $data['endpoint'], 'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth'], 'device' => $device ? mb_substr($device, 0, 250) : null, 'last_error_at' => null],
        );
    }
}

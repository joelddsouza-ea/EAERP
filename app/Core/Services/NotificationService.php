<?php

namespace App\Core\Services;

use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * Notification Service
 *
 * Handles sending notifications via multiple channels:
 * - Email
 * - SMS (via external provider)
 * - Push notifications (via Firebase/FCM)
 */
class NotificationService
{
    /**
     * Send email notification.
     */
    public function sendEmail(
        string $to,
        string $subject,
        string $template,
        array $data = []
    ): bool {
        try {
            Mail::send($template, $data, function ($message) use ($to, $subject) {
                $message->to($to)
                    ->subject($subject);
            });

            return true;
        } catch (\Exception $e) {
            Log::error('Email notification failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send SMS notification.
     *
     * @param string $phone Phone number with country code
     * @param string $message SMS message content
     */
    public function sendSms(string $phone, string $message): bool
    {
        // TODO: Integrate with SMS provider (MSG91, Twilio, etc.)
        // This is a placeholder implementation

        try {
            Log::info('SMS notification', [
                'phone' => $phone,
                'message' => $message,
            ]);

            // Uncomment and configure when SMS provider is integrated
            // $response = Http::post(config('services.sms.url'), [
            //     'api_key' => config('services.sms.key'),
            //     'phone' => $phone,
            //     'message' => $message,
            // ]);

            return true;
        } catch (\Exception $e) {
            Log::error('SMS notification failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send push notification.
     *
     * @param User|array $recipients User or array of users
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data payload
     */
    public function sendPush(
        User|array $recipients,
        string $title,
        string $body,
        array $data = []
    ): bool {
        // TODO: Integrate with Firebase Cloud Messaging
        // This is a placeholder implementation

        try {
            $users = is_array($recipients) ? $recipients : [$recipients];

            Log::info('Push notification', [
                'recipients' => collect($users)->pluck('id'),
                'title' => $title,
                'body' => $body,
            ]);

            // Uncomment and configure when FCM is integrated
            // foreach ($users as $user) {
            //     if ($user->fcm_token) {
            //         Http::post('https://fcm.googleapis.com/fcm/send', [
            //             'to' => $user->fcm_token,
            //             'notification' => [
            //                 'title' => $title,
            //                 'body' => $body,
            //             ],
            //             'data' => $data,
            //         ]);
            //     }
            // }

            return true;
        } catch (\Exception $e) {
            Log::error('Push notification failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send notification to user via their preferred channel.
     */
    public function notifyUser(
        User $user,
        string $subject,
        string $message,
        array $channels = ['email', 'push']
    ): array {
        $results = [];

        if (in_array('email', $channels) && $user->email) {
            $results['email'] = $this->sendEmail(
                $user->email,
                $subject,
                'emails.notification',
                ['message' => $message, 'user' => $user]
            );
        }

        if (in_array('sms', $channels) && $user->phone) {
            $results['sms'] = $this->sendSms($user->phone, $message);
        }

        if (in_array('push', $channels)) {
            $results['push'] = $this->sendPush($user, $subject, $message);
        }

        return $results;
    }

    /**
     * Send job card status update notification.
     */
    public function notifyJobCardStatus(
        Customer $customer,
        string $jobNumber,
        string $status,
        ?string $note = null
    ): void {
        $statusMessages = [
            'received' => "Your vehicle has been received at our workshop. Job Card: {$jobNumber}",
            'in_progress' => "Work has started on your vehicle. Job Card: {$jobNumber}",
            'qc' => "Your vehicle is undergoing quality check. Job Card: {$jobNumber}",
            'ready' => "Your vehicle is ready for pickup! Job Card: {$jobNumber}",
            'delivered' => "Thank you for choosing us! Your vehicle (Job Card: {$jobNumber}) has been delivered.",
        ];

        $message = $statusMessages[$status] ?? "Job Card {$jobNumber} status: {$status}";

        if ($note) {
            $message .= "\n\nNote: {$note}";
        }

        // Customers aren't app users, so there's no push channel; reach them by SMS and email
        if ($customer->phone) {
            $this->sendSms($customer->phone, $message);
        }

        if ($customer->email) {
            $this->sendEmail(
                $customer->email,
                "Vehicle Service Update - {$jobNumber}",
                'emails.notification',
                ['message' => $message, 'user' => $customer]
            );
        }
    }
}

<?php

namespace App\Notifications;

use App\Models\Request;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a staff member a request was just assigned to them.
 *
 * Two channels on purpose: `database` writes a row the in-app bell can
 * read immediately, `mail` sends email — which renders to storage/logs
 * on the local `log` mailer until Phase 3 wires Resend. ShouldQueue
 * defers delivery to the queue worker (`composer run dev` runs one).
 */
class RequestAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  User|null  $assignedBy  who made the assignment; null when automation did (Phase 6)
     */
    public function __construct(
        public Request $request,
        public ?User $assignedBy = null,
    ) {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $assigner = $this->assignedBy->name ?? __('HelpStripe');

        return (new MailMessage)
            ->subject(__('Request #:id assigned to you: :subject', [
                'id' => $this->request->id,
                'subject' => $this->request->subject,
            ]))
            ->line(__(':assigner assigned request #:id to you.', [
                'assigner' => $assigner,
                'id' => $this->request->id,
            ]))
            ->line(__('Subject: :subject', ['subject' => $this->request->subject]))
            ->action(__('View request'), route('requests.show', [
                'current_team' => $this->request->team->slug,
                'request' => $this->request->id,
            ]));
    }

    /**
     * Get the array representation of the notification.
     *
     * This is what the `database` channel stores as JSON — keep it small
     * and denormalized enough for a notification dropdown to render
     * without extra queries.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'subject' => $this->request->subject,
            'team_slug' => $this->request->team->slug,
            'assigned_by' => $this->assignedBy?->name,
        ];
    }
}

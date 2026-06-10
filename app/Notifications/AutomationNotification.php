<?php

namespace App\Notifications;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a staff member an automation rule fired on a request.
 *
 * The payload of the `notify_user` action: "Rule X fired on Request #N." Same
 * two-channel shape as RequestAssignedNotification (database for the in-app
 * bell, mail for email), ShouldQueue so the rule's apply loop never blocks on
 * delivery. Carries the rule's name so the recipient knows *which* automation
 * pinged them, not just that one did.
 */
class AutomationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Request $request,
        public string $ruleName,
    ) {}

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
        return (new MailMessage)
            ->subject(__('Automation: :rule fired on request #:id', [
                'rule' => $this->ruleName,
                'id' => $this->request->id,
            ]))
            ->line(__('The automation rule ":rule" fired on request #:id.', [
                'rule' => $this->ruleName,
                'id' => $this->request->id,
            ]))
            ->line(__('Subject: :subject', ['subject' => $this->request->subject]))
            ->action(__('View request'), route('requests.show', [
                'current_team' => $this->request->team->slug,
                'request' => $this->request->id,
            ]));
    }

    /**
     * Get the array representation of the notification (the `database` channel).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'subject' => $this->request->subject,
            'team_slug' => $this->request->team->slug,
            'rule_name' => $this->ruleName,
        ];
    }
}

<?php

namespace App\Notifications;

use App\Models\Tontine;
use App\Services\NotificationLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * La tontine que le membre a rejointe vient d'être modifiée par son créateur.
 *
 * Sans cette notification, un membre verrait simplement la somme à cotiser
 * changer d'un écran à l'autre, sans explication.
 */
class TontineUpdatedNotification extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $changes  description lisible de ce qui a changé
     */
    public function __construct(
        protected Tontine $tontine,
        protected array $changes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('La tontine '.$this->tontine->name.' a été modifiée')
            ->line('La tontine « '.$this->tontine->name.' » que tu as rejointe a été modifiée avant son démarrage.');

        foreach ($this->changes as $change) {
            $message->line('• '.$change);
        }

        return $message
            ->action('Voir la tontine', NotificationLinks::tontine($this->tontine->id));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'tontine_id' => $this->tontine->id,
            'tontine_name' => $this->tontine->name,
            'changes' => $this->changes,
            'message' => 'La tontine « '.$this->tontine->name.' » a été modifiée : '.implode(', ', $this->changes).'.',
            'url' => NotificationLinks::tontine($this->tontine->id),
        ];
    }
}

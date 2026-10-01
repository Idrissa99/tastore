<?php

namespace App\Notifications;

use App\Services\NotificationLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifie le membre que son adresse e-mail a été validée par un
 * administrateur, sans passer par le lien envoyé à l'inscription.
 *
 * Cas d'usage réel : sur le terrain (ligne Orange, perte d'e-mail), le
 * membre ne reçoit jamais le lien. L'administrateur vérifie alors
 * l'adresse après un contrôle, et le membre doit être prévenu que son
 * accès est rétabli — sinon il reste bloqué devant l'écran « vérifie
 * ton email » sans comprendre pourquoi.
 */
class EmailVerifiedByAdminNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ton adresse e-mail a été vérifiée')
            ->line("Bonjour {$notifiable->name},")
            ->line('Notre équipe a vérifié manuellement ton adresse e-mail. Ton compte est désormais actif : tu peux rejoindre une tontine et régler tes versements.')
            ->action('Accéder à mon espace', NotificationLinks::profile());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Ton adresse e-mail a été vérifiée par notre équipe. Ton compte est actif.',
            'url' => NotificationLinks::profile(),
        ];
    }
}

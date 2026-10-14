<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AgencyInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Invitation $invitation) {}

    // Garde-fou anti-doublon : on n'envoie l'e-mail que pour l'invitation
    // actuellement valide (la plus récente en attente pour cet e-mail + agence).
    public function via($notifiable): array
    {
        $invitation = $this->invitation;

        if ($invitation->status !== 'en_attente' || $invitation->isExpired()) {
            return [];
        }

        $latest = Invitation::where('agency_id', $invitation->agency_id)
            ->where('email', $invitation->email)
            ->where('status', 'en_attente')
            ->orderByDesc('id')
            ->first();

        return $latest && $latest->id === $invitation->id ? ['mail'] : [];
    }

    public function toMail($notifiable): MailMessage
    {
        $agency = $this->invitation->agency;
        $url = config('app.frontend_url').'/accepter-invitation?token='.$this->invitation->token;
        $role = $this->invitation->role === 'admin' ? 'Admin' : 'Membre';
        $inviter = $this->invitation->invitedBy?->name;
        $inviterRole = $inviter
            ? ucfirst((string) $agency->members()->where('user_id', $this->invitation->invited_by)->value('role'))
            : null;

     
     return (new MailMessage)
    ->subject("Invitation à rejoindre {$agency->name}")
    ->greeting('Bonjour !')
    ->line(
        $inviter
            ? "Vous avez été invité(e) par {$inviter}"
                . ($inviterRole ? " ({$inviterRole} de l'agence)" : '')
                . " à rejoindre l'agence « {$agency->name} » en tant que {$role}."
            : "Vous avez été invité(e) à rejoindre l'agence « {$agency->name} » en tant que {$role}."
    )
    ->action('Accepter l\'invitation', $url)
    ->line('Votre future équipe vous attend !')
    ->line('Ce lien est personnel : il ne fonctionnera que pour votre adresse e-mail.');
    }
}

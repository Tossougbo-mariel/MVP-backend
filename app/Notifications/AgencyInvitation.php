<?php

namespace App\Notifications;

use App\Models\Agency;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

class AgencyInvitation extends Notification
{
    use Queueable;

    public function __construct(protected Agency $agency) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $token = Password::createToken($notifiable);
        $url = config('app.frontend_url').'/reinitialiser-mot-de-passe'
             .'?token='.$token.'&email='.urlencode($notifiable->email);

        return (new MailMessage)
            ->subject("Invitation à rejoindre {$this->agency->name}")
            ->line("Vous avez été invité(e) à rejoindre l'agence « {$this->agency->name} ».")
            ->action('Définir mon mot de passe', $url)
            ->line('Ce lien expire dans 60 minutes.');
    }
}

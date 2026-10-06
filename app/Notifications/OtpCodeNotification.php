<?php

namespace App\Notifications;

use App\Models\EmailOtpCode;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class OtpCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $code,
        protected string $purpose,
        protected int $ttlMinutes,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $isTwoFactor = $this->purpose === EmailOtpCode::PURPOSE_TWO_FACTOR;

        $mail = (new MailMessage)
            ->subject($isTwoFactor
                ? 'Votre code de vérification'
                : 'Votre code de connexion');

        $mail->line($isTwoFactor
            ? 'Une connexion a été initiée sur votre compte. Saisissez ce code pour la confirmer.'
            : 'Voici votre code de connexion. Saisissez-le pour accéder à votre compte.');

        // Le code est généré par le serveur (6 chiffres) : aucun risque
        // d'injection, d'où l'affichage en gros caractères.
        $mail->line(new HtmlString(
            '<p style="font-size:32px;letter-spacing:8px;font-weight:bold;margin:24px 0;">'
            .htmlspecialchars($this->code, ENT_QUOTES)
            .'</p>'
        ));

        $mail->line('Ce code expire dans '.$this->ttlMinutes.' minutes et ne peut servir qu\'une fois.');

        $mail->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : '
            .'aucun changement n\'a été effectué sur votre compte.');

        return $mail;
    }
}

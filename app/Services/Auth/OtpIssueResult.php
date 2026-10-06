<?php

namespace App\Services\Auth;

/**
 * Résultat d'une demande de code OTP.
 *
 * `sent` à `false` ne signifie pas « erreur » : c'est le cas normal quand
 * l'utilisateur redemande trop vite un code. Le contrôleur ne le signale pas
 * à l'utilisateur, afin de ne pas révéler si le compte existe.
 */
final class OtpIssueResult
{
    public function __construct(
        public readonly bool $sent,
        public readonly int $retryAfter,
    ) {}
}

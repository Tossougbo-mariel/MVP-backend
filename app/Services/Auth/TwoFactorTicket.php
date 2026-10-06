<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Ticket temporaire échangé contre un token lors d'une connexion à deux
 * facteurs.
 *
 * Le ticket ne donne aucun accès à l'API : il atteste seulement qu'un mot de
 * passe correct a déjà été fourni, et il est consommé au premier usage. Il
 * n'est stocké que sous forme d'empreinte, et il expire au bout de quelques
 * minutes.
 */
final class TwoFactorTicket
{
    private function key(string $plain): string
    {
        return 'auth:2fa:'.hash('sha256', $plain);
    }

    /** Émet un nouveau ticket et rend l'ancien inutilisable. */
    public function issue(User $user): string
    {
        $plain = Str::random(64);
        $this->store($plain, $user);

        return $plain;
    }

    /** Consomme le ticket et renvoie l'utilisateur, ou `null` s'il est invalide. */
    public function consume(string $plain): ?User
    {
        $userId = Cache::pull($this->key($plain));

        if (! is_int($userId) && ! is_string($userId)) {
            return null;
        }

        return User::find($userId);
    }

    /** Lecture sans consommation, pour le renvoi d'un nouveau code. */
    public function peek(string $plain): ?User
    {
        $userId = Cache::get($this->key($plain));

        if (! is_int($userId) && ! is_string($userId)) {
            return null;
        }

        return User::find($userId);
    }

    public function forget(string $plain): void
    {
        Cache::forget($this->key($plain));
    }

    private function store(string $plain, User $user): void
    {
        Cache::put($this->key($plain), $user->id, $this->ttl());
    }

    private function ttl(): int
    {
        return (int) config('auth.otp.two_factor_ticket_ttl', 600);
    }
}

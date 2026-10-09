<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Connexion via Google.
 *
 * Choix métier : si un compte existe déjà avec l'adresse Google, la connexion
 * est acceptée seulement si ce compte n'a pas de mot de passe (créé via Google
 * ou invitation sans MDP) : Google prouve la possession de l'e-mail. S'il
 * possède un mot de passe, le rattachement sans confirmation est refusé pour
 * éviter une porte de contournement ; l'utilisateur est renvoyé vers la
 * connexion classique.
 */
class GoogleAuthController extends Controller
{
    // GET /auth/google/redirect
    public function redirect()
    {
        if (! $this->isConfigured()) {
            return $this->notConfigured();
        }

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    // GET /auth/google/callback
    public function callback(Request $request)
    {
        if (! $this->isConfigured()) {
            return $this->notConfigured();
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::warning('Échec de l\'authentification Google : '.$e->getMessage());

            return $this->backToLogin('google_echec');
        }

        // L'email est normalisé comme partout ailleurs (User::email) : sans
        // cela, "Claire@Gmail.com" ne trouverait pas le compte stocké en
        // minuscules et l'on tenterait de créer un doublon.
        $email = Str::lower(trim((string) $googleUser->getEmail()));

        if (! $email) {
            // Sans adresse on ne peut pas créer de compte exploitable.
            return $this->backToLogin('google_email_absent');
        }

        $existing = User::where('email', $email)->first();

        if ($existing) {
            if ($existing->password !== null) {
                return $this->backToLogin('email_deja_utilise');
            }

            $user = $existing;
        } else {
            $user = $this->createUser($googleUser, $email);
        }
        $token = $user->createToken('auth-token')->plainTextToken;

        // Le token passe par le fragment (#) : il ne traverse ni les logs du
        // serveur, ni l'en-tête Referer.
        return redirect($this->frontendUrl('/connexion').'#token='.urlencode($token));
    }

    // ---------- Interne ----------

    private function createUser($googleUser, string $email): User
    {
        $name = trim((string) ($googleUser->getName() ?: ''));
        $parts = $name !== '' ? explode(' ', $name, 2) : [];

        $user = new User;
        $user->forceFill([
            'name' => $name !== '' ? $name : $email,
            'first_name' => $this->clean($parts[0] ?? null) ?: $this->localPart($email),
            'last_name' => $this->clean($parts[1] ?? null),
            'email' => $email,
            'password' => null, // compte sans mot de passe
            'avatar' => $googleUser->getAvatar(),
            'status' => 'actif',
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    private function notConfigured()
    {
        return response()->json([
            'message' => 'La connexion Google n\'est pas configurée sur ce serveur.',
        ], 503);
    }

    private function backToLogin(string $error): RedirectResponse
    {
        return redirect($this->frontendUrl('/connexion').'?erreur='.urlencode($error));
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function localPart(string $email): string
    {
        return Str::before($email, '@');
    }
}

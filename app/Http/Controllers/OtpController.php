<?php

namespace App\Http\Controllers;

use App\Models\EmailOtpCode;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Auth\TwoFactorTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Connexion sans mot de passe par code email, et second facteur.
 *
 * Deux usages distincts partagent la même table `email_otp_codes` :
 *  - `login`      : l'utilisateur saisit son email et reçoit un code ;
 *  - `two_factor` : le mot de passe a déjà été validé, un code est demandé
 *                   avant d'ouvrir la session.
 */
class OtpController extends Controller
{
    public function __construct(
        protected OtpService $otp,
        protected TwoFactorTicket $tickets,
    ) {}

    // POST /api/auth/otp/request
    public function requestLoginCode(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        if ($throttle = $this->throttle('otp-request', $request)) {
            return $throttle;
        }

        $email = $this->otp->normalizeEmail($data['email']);
        $user = User::where('email', $email)->first();

        if ($user) {
            try {
                $this->otp->issue(
                    $user,
                    EmailOtpCode::PURPOSE_LOGIN,
                    $request->ip(),
                    $request->userAgent(),
                );
            } catch (Throwable $e) {
                // On ne distingue pas un envoi échoué d'un email inconnu :
                // la réponse reste la même dans tous les cas.
                Log::warning('Envoi du code de connexion échoué : '.$e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Si un compte existe pour cette adresse, un code vient d\'être envoyé.',
        ], 202);
    }

    // POST /api/auth/otp/verify
    public function verifyLoginCode(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        if ($throttle = $this->throttle('otp-verify', $request)) {
            return $throttle;
        }

        $row = $this->otp->verify($data['email'], EmailOtpCode::PURPOSE_LOGIN, $data['code']);
        $user = $row?->user;

        if (! $row || ! $user) {
            return response()->json(['message' => 'Code invalide ou expiré.'], 401);
        }

        // Un code valide prouve déjà la possession de la boîte mail : la
        // double authentification n'a donc pas lieu d'être redemandée ici.
        return $this->issueSession($user);
    }

    // POST /api/auth/two-factor/resend
    public function resendTwoFactorCode(Request $request)
    {
        $data = $request->validate(['ticket' => ['required', 'string']]);

        if ($throttle = $this->throttle('otp-request', $request)) {
            return $throttle;
        }

        $user = $this->tickets->peek($data['ticket']);

        if (! $user) {
            return response()->json(['message' => 'Session expirée, reprenez la connexion.'], 401);
        }

        try {
            $this->otp->issue(
                $user,
                EmailOtpCode::PURPOSE_TWO_FACTOR,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (Throwable $e) {
            Log::warning('Envoi du code 2FA échoué : '.$e->getMessage());
        }

        // L'ancien ticket est détruit : seul le plus récent permet de valider
        // un code, ce qui évite qu'un ticket intercepté reste exploitable.
        $this->tickets->forget($data['ticket']);

        return response()->json($this->twoFactorChallenge($this->tickets->issue($user)));
    }

    // POST /api/auth/two-factor/verify
    public function verifyTwoFactorCode(Request $request)
    {
        $data = $request->validate([
            'ticket' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        if ($throttle = $this->throttle('otp-verify', $request)) {
            return $throttle;
        }

        $user = $this->tickets->peek($data['ticket']);

        if (! $user) {
            return response()->json(['message' => 'Session expirée, reprenez la connexion.'], 401);
        }

        $row = $this->otp->verify($user->email, EmailOtpCode::PURPOSE_TWO_FACTOR, $data['code']);

        if (! $row) {
            return response()->json(['message' => 'Code invalide ou expiré.'], 401);
        }

        // Ticket à usage unique : il est consommé au moment de l'échange.
        $this->tickets->consume($data['ticket']);

        return $this->issueSession($user);
    }

    // GET /api/auth/two-factor
    public function showTwoFactorState(Request $request)
    {
        return response()->json([
            'enabled' => (bool) $request->user()->two_factor_enabled,
        ]);
    }

    // PUT /api/auth/two-factor
    public function updateTwoFactorState(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'password' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $wantsEnabled = (bool) $data['enabled'];

        if ($wantsEnabled && ! $user->two_factor_enabled) {
            // Un compte sans mot de passe (connexion Google) s'authentifie
            // déjà via Google : exiger un mot de passe n'aurait pas de sens.
            if ($user->password !== null) {
                if (($data['password'] ?? null) === null) {
                    return response()->json([
                        'message' => 'Saisissez votre mot de passe pour activer la double authentification.',
                    ], 422);
                }

                if (! Hash::check($data['password'], $user->password)) {
                    return response()->json(['message' => 'Mot de passe incorrect.'], 422);
                }
            }

            $user->forceFill(['two_factor_enabled' => true])->save();
        } elseif (! $wantsEnabled && $user->two_factor_enabled) {
            $user->forceFill(['two_factor_enabled' => false])->save();

            // Les codes en attente ne doivent pas survivre à la désactivation.
            $this->otp->invalidate($user->email, EmailOtpCode::PURPOSE_TWO_FACTOR);
        }

        return response()->json(['enabled' => (bool) $user->fresh()->two_factor_enabled]);
    }

    // ---------- Interne ----------

    private function issueSession(User $user)
    {
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    private function twoFactorChallenge(string $ticket): array
    {
        return [
            'two_factor_required' => true,
            'ticket' => $ticket,
            'expires_in' => (int) config('auth.otp.two_factor_ticket_ttl', 600),
        ];
    }

    /**
     * Limitation par IP uniquement.
     *
     * Elle ne dépend pas de l'existence d'un compte, donc elle ne révèle rien
     * sur les emails enregistrés. Le cooldown par email, lui, est silencieux
     * (voir OtpService::issue).
     */
    private function throttle(string $bucket, Request $request)
    {
        $limit = $bucket === 'otp-request'
            ? (int) config('auth.otp.request_per_minute', 10)
            : (int) config('auth.otp.verify_per_minute', 10);

        $key = $bucket.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => 'Trop de tentatives. Réessayez dans '.$seconds.' secondes.',
            ], 429);
        }

        RateLimiter::hit($key, 60);

        return null;
    }
}

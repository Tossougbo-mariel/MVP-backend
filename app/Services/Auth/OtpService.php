<?php

namespace App\Services\Auth;

use App\Models\EmailOtpCode;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Émission et vérification des codes à usage unique envoyés par email.
 *
 * Principes retenus :
 *  - le code n'est jamais stocké en clair, seulement une empreinte HMAC ;
 *  - un nouveau code annule les précédents pour le même couple (email, usage) ;
 *  - la limitation de débit par email est silencieuse, sinon elle permettrait de
 *    deviner quels emails ont un compte (voir OtpService::issue) ;
 *  - la vérification ne distingue jamais « code faux » de « code expiré ».
 */
class OtpService
{
    /** Délai minimum entre deux envois pour un même email et un même usage. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /** Nombre maximum d'envois par email et par usage sur une heure. */
    public const MAX_SENDS_PER_HOUR = 5;

    public function ttlMinutes(string $purpose): int
    {
        return $purpose === EmailOtpCode::PURPOSE_TWO_FACTOR
            ? (int) config('auth.otp.two_factor_ttl', 10)
            : (int) config('auth.otp.login_ttl', 10);
    }

    /**
     * Envoie un nouveau code à l'utilisateur et invalide les précédents.
     *
     * Retourne `sent = false` sans erreur si l'utilisateur redemande trop
     * vite : l'appelant doit alors répondre de façon identique à un envoi
     * réussi, pour ne pas filtrer l'existence du compte.
     */
    public function issue(
        User $user,
        string $purpose,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): OtpIssueResult {
        $email = $this->normalizeEmail($user->email);
        $now = now();

        $retryAfter = $this->retryAfter($email, $purpose, $now);
        if ($retryAfter > 0) {
            return new OtpIssueResult(sent: false, retryAfter: $retryAfter);
        }

        // Seul le dernier code émis reste valide.
        EmailOtpCode::query()
            ->where('email', $email)
            ->forPurpose($purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => $now]);

        $plainCode = $this->generateCode();
        $ttl = $this->ttlMinutes($purpose);

        EmailOtpCode::create([
            'user_id' => $user->id,
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => $this->hash($plainCode),
            'last_sent_at' => $now,
            'expires_at' => $now->copy()->addMinutes($ttl),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent ? Str::limit($userAgent, 255, '') : null,
        ]);

        $user->notify(new OtpCodeNotification($plainCode, $purpose, $ttl));

        return new OtpIssueResult(sent: true, retryAfter: 0);
    }

    /**
     * Vérifie un code et le consomme.
     *
     * Retourne `null` pour toute défaillance — mauvais code, expiré, déjà
     * utilisé, trop de tentatives — afin que le message d'erreur reste le
     * même dans tous les cas.
     */
    public function verify(string $email, string $purpose, string $code): ?EmailOtpCode
    {
        $row = EmailOtpCode::query()
            ->where('email', $this->normalizeEmail($email))
            ->forPurpose($purpose)
            ->usable()
            ->latestFirst()
            ->first();

        if (! $row) {
            return null;
        }

        if (! hash_equals($row->code_hash, $this->hash((string) $code))) {
            $row->registerFailedAttempt();

            return null;
        }

        $row->consume();

        return $row;
    }

    /** Invalide tous les codes en cours pour un couple (email, usage). */
    public function invalidate(string $email, string $purpose): void
    {
        EmailOtpCode::query()
            ->where('email', $this->normalizeEmail($email))
            ->forPurpose($purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }

    /** Délai avant de pouvoir redemander un code, en secondes (0 si possible). */
    public function retryAfter(string $email, string $purpose): int
    {
        return $this->retryAfterFor($this->normalizeEmail($email), $purpose, now());
    }

    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function retryAfterFor(string $email, string $purpose, Carbon $now): int
    {
        $base = EmailOtpCode::query()
            ->where('email', $email)
            ->forPurpose($purpose);

        $lastSentAt = (clone $base)->latest('last_sent_at')->value('last_sent_at');

        // Plafond horaire : on se cale sur le plus ancien envoi de la fenêtre.
        $hourlyCount = (clone $base)->where('last_sent_at', '>=', $now->copy()->subHour())->count();
        if ($hourlyCount >= self::MAX_SENDS_PER_HOUR) {
            $oldest = (clone $base)
                ->where('last_sent_at', '>=', $now->copy()->subHour())
                ->orderBy('last_sent_at')
                ->value('last_sent_at');

            if ($oldest) {
                return $this->secondsUntil(Carbon::parse($oldest)->addHour(), $now);
            }
        }

        if ($lastSentAt) {
            $cooldownUntil = Carbon::parse($lastSentAt)->addSeconds(self::RESEND_COOLDOWN_SECONDS);

            if ($now->lt($cooldownUntil)) {
                return $this->secondsUntil($cooldownUntil, $now);
            }
        }

        return 0;
    }

    private function secondsUntil(Carbon $target, Carbon $now): int
    {
        return max(1, (int) ceil($now->diffInSeconds($target, false)));
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}

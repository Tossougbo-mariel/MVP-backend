<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailOtpCode extends Model
{
    use HasFactory;

    /** Connexion sans mot de passe par code email. */
    public const PURPOSE_LOGIN = 'login';

    /** Second facteur demandé après validation du mot de passe. */
    public const PURPOSE_TWO_FACTOR = 'two_factor';

    /** Nombre d'essais avant invalidation définitive du code. */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'user_id', 'email', 'purpose', 'code_hash',
        'attempts', 'last_sent_at', 'expires_at', 'consumed_at',
        'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < self::MAX_ATTEMPTS;
    }

    /** Le code peut-il encore servir à une vérification ? */
    public function isUsable(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired() && $this->hasAttemptsLeft();
    }

    public function consume(): void
    {
        $this->forceFill(['consumed_at' => now()])->save();
    }

    public function registerFailedAttempt(): void
    {
        $this->forceFill(['attempts' => $this->attempts + 1])->save();

        // Un code trop sollicité est consommé : il oblige à en demander un
        // nouveau plutôt que de laisser une fenêtre de brute-force ouverte.
        if (! $this->hasAttemptsLeft()) {
            $this->consume();
        }
    }

    // ---------- Scopes ----------

    public function scopeForPurpose(Builder $query, string $purpose): Builder
    {
        return $query->where('purpose', $purpose);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS);
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }
}

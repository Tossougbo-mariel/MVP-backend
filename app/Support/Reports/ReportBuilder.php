<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\User;
use App\Support\TaskStatusResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Base commune des rapports PDF (projet et agence).
 *
 * Fournit les petits calculs partages : lecture de dates, mise en forme,
 * noms d'utilisateurs, statuts, priorites et pourcentages. Le rendu est un
 * document classique (noir et blanc), dense en statistiques.
 */
abstract class ReportBuilder
{
    protected function parseDate(string|Carbon|null $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        try {
            return Carbon::parse(strlen($value) <= 10 ? $value.' 00:00:00' : $value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function plural(int $n, string $one, string $many): string
    {
        return $n.' '.($n > 1 ? $many : $one);
    }

    protected function fmtLongDate(?Carbon $d): string
    {
        if (! $d) {
            return '—';
        }

        return ucfirst($d->copy()->locale('fr')->translatedFormat('j F Y'));
    }

    protected function fmtDate(?Carbon $d): string
    {
        return $d ? $d->format('d/m/Y') : '—';
    }

    /** Pourcentage arrondi, sous forme de chaine « 42 % ». */
    protected function pct(int $part, int $total): string
    {
        if ($total <= 0) {
            return '0 %';
        }

        return ((int) round(($part / $total) * 100)).' %';
    }

    protected function priorityLabel(?string $key): string
    {
        return [
            'urgente' => 'Urgente',
            'haute' => 'Haute',
            'moyenne' => 'Moyenne',
            'basse' => 'Basse',
        ][$key] ?? ucfirst((string) $key);
    }

    protected function projectStatusLabel(?string $key): string
    {
        return [
            'a_venir' => 'À venir',
            'en_cours' => 'En cours',
            'termine' => 'Terminé',
            'archive' => 'Archivé',
        ][$key] ?? ucfirst((string) $key);
    }

    protected function memberStatusLabel(?string $key): string
    {
        return [
            'actif' => 'Actif',
            'en_attente' => 'En attente',
            'inactif' => 'Inactif',
        ][$key] ?? ucfirst((string) $key);
    }

    protected function memberName(?User $user): string
    {
        if (! $user) {
            return '—';
        }

        $full = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        if ($full !== '') {
            return $full;
        }

        return $user->name ?: $user->email;
    }

    protected function isTerminal(int $agencyId, ?string $status): bool
    {
        return TaskStatusResolver::isTerminal($agencyId, $status);
    }

    /**
     * Poids d'avancement par statut : la derniere colonne vaut 100, les
     * statuts terminaux valent toujours 100.
     *
     * @return array<string, int>
     */
    protected function progressCredits(Collection $statuses): array
    {
        $lastIndex = max(0, $statuses->count() - 1);
        $credits = [];

        foreach ($statuses->values() as $i => $status) {
            $credits[$status['key']] = ($status['is_terminal'] || $lastIndex === 0)
                ? 100
                : (int) round(($i / $lastIndex) * 100);
        }

        return $credits;
    }

    protected function statusLabel(Collection $statuses, ?string $key): string
    {
        $match = $statuses->firstWhere('key', $key);

        return $match['label'] ?? (string) $key;
    }

    /** Nombre de jours entiers entre deux dates (arrondi bas, positif). */
    protected function daysBetween(?Carbon $from, ?Carbon $to): ?int
    {
        if (! $from || ! $to) {
            return null;
        }

        $seconds = $to->copy()->startOfDay()->getTimestamp() - $from->copy()->startOfDay()->getTimestamp();

        return (int) round($seconds / 86400);
    }

    /** Nombre de jours entiers ecoules depuis `$due` jusqu'a aujourd'hui. */
    protected function daysSince(Carbon $today, ?Carbon $due): int
    {
        if (! $due) {
            return 0;
        }

        $seconds = $today->getTimestamp() - $due->copy()->startOfDay()->getTimestamp();

        return max(0, (int) floor($seconds / 86400));
    }

    /** "3 jours" / "1 jour" / "—". */
    protected function daysLabel(?int $days): string
    {
        if ($days === null) {
            return '—';
        }

        return $days.' '.($days > 1 ? 'jours' : 'jour');
    }
}

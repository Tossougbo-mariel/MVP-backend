<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AgencyTaskStatus;
use Illuminate\Support\Collection;

/**
 * Source de verite unique des statuts de taches.
 *
 * Le code applicatif ne doit plus comparer `status` a 'terminee' en dur :
 * une agence peut renommer ou ajouter ses colonnes, et c'est le statut
 * marque `is_terminal` qui definit ce qui "cloture" une tache.
 *
 * Sans aucun statut configure pour l'agence, on retombe sur les quatre
 * statuts historiques, ce qui garantit que le behaviour existant ne change
 * pas pour les agences qui n'ont rien personnalise.
 */
class TaskStatusResolver
{
    /** @var array<int, array<int, array<string, mixed>>> */
    private static array $cache = [];

    /**
     * Statuts par defaut, identiques a l'historique de l'application.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            ['key' => 'a_faire', 'label' => 'À faire', 'color' => '#ef4444', 'is_terminal' => false],
            ['key' => 'en_cours', 'label' => 'En cours', 'color' => '#f59e0b', 'is_terminal' => false],
            ['key' => 'en_revision', 'label' => 'En révision', 'color' => '#589bff', 'is_terminal' => false],
            ['key' => 'terminee', 'label' => 'Terminée', 'color' => '#10b981', 'is_terminal' => true],
        ];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * Liste ordonnee des statuts d'une agence.
     *
     * Les statuts personnalises sont FUSIONNES par-dessus les defauts, pas
     * substitues : `tasks.status` contient deja les quatre cles historiques,
     * donc les faire disparaitre des qu'une agence ajoute une colonne
     * rendrait ses taches existantes invalides. Une ligne personnalisee dont
     * la cle existe deja parmi les defauts vient simplement ecraser ses
     * valeurs (libelle, couleur, terminal) tout en gardant sa position, ce
     * qui permet de renommer « En cours » sans perdre l'ordre des colonnes.
     * Les cles inconnues sont ajoutees apres les defauts, triees par
     * position.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function forAgency(int $agencyId): Collection
    {
        if (isset(self::$cache[$agencyId])) {
            return collect(self::$cache[$agencyId]);
        }

        $rows = AgencyTaskStatus::where('agency_id', $agencyId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $merged = [];

        foreach (self::defaults() as $index => $default) {
            $merged[$default['key']] = $default + ['position' => $index];
        }

        $appended = [];

        foreach ($rows as $s) {
            $entry = [
                'key' => $s->key,
                'label' => $s->label,
                'color' => $s->color,
                'is_terminal' => (bool) $s->is_terminal,
            ];

            if (array_key_exists($s->key, $merged)) {
                $merged[$s->key] = [...$merged[$s->key], ...$entry];
            } else {
                $appended[] = [...$entry, 'position' => 1000 + (int) $s->position];
            }
        }

        usort($appended, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        $ordered = array_values($merged);
        foreach ($appended as $entry) {
            $ordered[] = $entry;
        }

        self::$cache[$agencyId] = $ordered;

        return collect($ordered);
    }

    /**
     * Le statut de cette tache est-il terminal (la tache est cloturee) ?
     */
    public static function isTerminal(?int $agencyId, ?string $status): bool
    {
        if ($status === null) {
            return false;
        }

        if ($agencyId === null) {
            return in_array($status, ['terminee', 'termine', 'done'], true);
        }

        $match = self::forAgency($agencyId)->firstWhere('key', $status);

        // Statut inconnu pour cette agence : on retombe sur le vocabulaire
        // historique plutot que de considerer la tache comme cloturee, sinon
        // une donnee exotique disparaitrait des listes "en cours".
        return $match
            ? (bool) $match['is_terminal']
            : in_array($status, ['terminee', 'termine', 'done'], true);
    }

    /**
     * Cles des statuts qui cloturent une tache.
     *
     * @return array<int, string>
     */
    public static function terminalKeys(int $agencyId): array
    {
        return self::forAgency($agencyId)
            ->filter(fn (array $s) => (bool) $s['is_terminal'])
            ->pluck('key')
            ->all();
    }

    /**
     * Cles des statuts considered comme "ouverts" (toutes les non-terminales).
     *
     * @return array<int, string>
     */
    public static function openKeys(int $agencyId): array
    {
        return self::forAgency($agencyId)
            ->reject(fn (array $s) => (bool) $s['is_terminal'])
            ->pluck('key')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function keys(int $agencyId): array
    {
        return self::forAgency($agencyId)->pluck('key')->all();
    }

    public static function isValidKey(int $agencyId, ?string $status): bool
    {
        return $status !== null && in_array($status, self::keys($agencyId), true);
    }

    /** Cle du premier statut, utilise comme valeur par defaut a la creation. */
    public static function defaultKey(int $agencyId): string
    {
        return (string) (self::forAgency($agencyId)->first()['key'] ?? 'a_faire');
    }

    /**
     * Union de toutes les cles terminales connues (agences configurees +
     * defaults).
     *
     * C'est un sur-ensemble volontairement large : il sert a preselectionner
     * les lignes en base. Il ne remplace pas `isTerminal()`, qui reste la
     * reference car une meme cle peut etre terminale dans une agence et pas
     * dans une autre. Utilise pour les commandes qui traversent toutes les
     * agences.
     *
     * @return array<int, string>
     */
    public static function allTerminalKeys(): array
    {
        $fromAgencies = AgencyTaskStatus::where('is_terminal', true)
            ->pluck('key')
            ->all();

        $fromDefaults = collect(self::defaults())
            ->filter(fn (array $s) => (bool) $s['is_terminal'])
            ->pluck('key')
            ->all();

        return array_values(array_unique(array_merge($fromDefaults, $fromAgencies)));
    }
}

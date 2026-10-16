<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\Agency;
use Illuminate\Support\Carbon;

/**
 * Construit les donnees du « Rapport d'agence ».
 *
 * Rapport de gestion classique : informations de l'agence, liste des projets
 * et liste des membres. Aucun bloc statistique.
 */
class AgencyReportBuilder extends ReportBuilder
{
    public function __construct(private readonly Agency $agency)
    {
        $this->agency->loadMissing(['projects.owner', 'members.user', 'owner']);
    }

    public function build(): array
    {
        $agency = $this->agency;

        // ---- Liste des projets ----
        $projectRows = [];
        foreach ($agency->projects->sortBy('name') as $p) {
            $projectRows[] = [
                $p->name,
                $this->projectStatusLabel($p->status),
                $p->owner ? $this->memberName($p->owner) : '—',
                $this->fmtDate($this->parseDate($p->start_date)),
                $this->fmtDate($this->parseDate($p->due_date)),
            ];
        }

        // ---- Liste des membres ----
        $memberRows = [];
        foreach ($agency->members as $am) {
            $u = $am->user;
            if (! $u) {
                continue;
            }
            $role = (int) $u->id === (int) $agency->owner_id
                ? 'Propriétaire'
                : ($am->role === 'admin' ? 'Admin' : 'Membre');

            $memberRows[] = [
                $this->memberName($u),
                $role,
                $this->memberStatusLabel($am->status),
            ];
        }

        $sections = [
            [
                'heading' => 'Informations générales',
                'type' => 'keyvalue',
                'rows' => array_values(array_filter([
                    ['label' => 'Agence', 'value' => $agency->name],
                    $agency->owner ? ['label' => 'Propriétaire', 'value' => $this->memberName($agency->owner)] : null,
                ])),
            ],
            [
                'heading' => 'Liste des projets',
                'type' => 'table',
                'columns' => ['Projet', 'Statut', 'Responsable', 'Début', 'Échéance'],
                'align' => ['', '', '', '', ''],
                'rows' => $projectRows !== [] ? $projectRows : [['Aucun projet', '—', '—', '—', '—']],
            ],
            [
                'heading' => 'Liste des membres',
                'type' => 'table',
                'columns' => ['Membre', 'Rôle', 'Statut'],
                'align' => ['', '', ''],
                'rows' => $memberRows !== [] ? $memberRows : [['Aucun membre', '—', '—']],
            ],
        ];

        return [
            'title' => "Rapport d'agence",
            'subtitle' => $agency->name,
            'generatedAt' => $this->fmtLongDate(Carbon::now()).' à '.Carbon::now()->format('H:i'),
            'description' => $agency->description ?: null,
            'sections' => $sections,
        ];
    }
}

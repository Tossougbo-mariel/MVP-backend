<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Agency;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskStatusResolver;
use Illuminate\Support\Carbon;

/**
 * Construit le prompt systeme de l'assistant a partir des donnees reelles de
 * l'utilisateur. Rien n'est invente : si une information n'est pas dans la base,
 * le modele ne la voit pas et doit le dire.
 */
class AiContextBuilder
{
    public function build(User $user, ?int $agencyId = null): string
    {
        $now = Carbon::now();

        $lines = [
            "Tu es l'assistant integre de MVP Studio, une plateforme de gestion de projet "
            ."(agences, projets, taches, echeances).",
            '',
            "REGLE ABSOLUE : tu es en LECTURE SEULE. Tu analyses et tu expliques, tu ne crees, "
            ."ne modifies et ne supprimes jamais rien. Quand l'utilisateur veut une action, tu "
            ."lui donnes les etapes exactes a faire dans l'interface, ou tu proposes de la lui "
            ."faire confirmer explicitement. N'invente jamais une donnee absente du contexte : "
            ."si tu ne sais pas, dis que tu ne sais pas et propose comment verifier.",
            '',
            "Tu reponds dans la langue de l'utilisateur, comme un collegue qui discute :",
            "phrases courtes et simples, ton naturel et detendu, jamais de Markdown.",
            "Pas de titres, pas de puces, pas de gras, pas de tableaux : l'interface n'affiche que du texte brut.",
            "Si tu donnes plusieurs etapes, coule-les dans un paragraphe fluide, avec des mots de liaison",
            "comme « ensuite », « apres ça » ou « pour finir ». N'invente jamais de dates ni d'identifiants.",
            '',
            "UTILISATEUR : {$user->name} ({$user->email}).",
        ];

        $agencyIds = $this->agencyIds($user, $agencyId);

        if ($agencyIds === []) {
            $lines[] = '';
            $lines[] = "CONTEXTE : cet utilisateur n'appartient a aucune agence pour l'instant.";

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = 'AGENCES :';

        $agencies = Agency::whereIn('id', $agencyIds)
            ->with(['members' => fn ($q) => $q->where('user_id', $user->id)->where('status', 'actif')])
            ->get();

        foreach ($agencies as $agency) {
            $role = $user->roleInAgency((int) $agency->id) ?? 'proprietaire';
            $lines[] = "- #{$agency->id} {$agency->name} (votre role : {$role})";
        }

        $projects = Project::whereIn('agency_id', $agencyIds)
            ->with('tasks')
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', ['en_cours'])
            ->limit(15)
            ->get();

        if ($projects->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'PROJETS :';

            foreach ($projects as $project) {
                $open = $project->tasks
                    ->reject(fn (Task $t) => $t->isTerminalStatus())
                    ->count();
                $lines[] = "- #{$project->id} {$project->name} [{$project->status}] - {$open} tache(s) ouverte(s)";
            }
        }

        // Ce contexte melange plusieurs agences : on preselectionne avec
        // l'union de toutes les cles terminales, puis on confirme tache par
        // tache car un statut peut cloturer dans une agence et pas dans une autre.
        $openTasks = Task::query()
            ->where('archived_at', null)
            ->whereNotIn('status', TaskStatusResolver::allTerminalKeys())
            ->where(function ($query) use ($user, $agencyIds) {
                $query->where('assigned_to', $user->id);

                if ($agencyIds !== []) {
                    $query->orWhereHas('project', fn ($q) => $q->whereIn('agency_id', $agencyIds));
                }
            })
            ->with(['project', 'assignee'])
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->limit(25)
            ->get()
            ->reject(fn (Task $t) => $t->isTerminalStatus())
            ->values();

        if ($openTasks->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'TACHES OUVERTES (les plus urgentes en premier) :';

            foreach ($openTasks as $task) {
                $projectName = $task->project?->name ?? 'sans projet';
                $assignee = $task->assignee?->name ?? 'non assignee';
                $due = $task->due_date
                    ? Carbon::parse($task->due_date)->format('d/m/Y')
                    : 'sans echeance';
                // $openTasks ne contient deja que des taches ouvertes.
                $overdue = $task->due_date && Carbon::parse($task->due_date)->isPast()
                    ? ' [EN RETARD]'
                    : '';

                $lines[] = "- #{$task->id} {$task->title} | {$projectName} | {$task->status} | "
                    ."priorite {$task->priority} | echeance {$due} | {$assignee}{$overdue}";
            }
        }

        $overdue = $openTasks
            ->filter(fn (Task $t) => $t->due_date && Carbon::parse($t->due_date)->isPast())
            ->count();

        $lines[] = '';
        $lines[] = "DATE DU JOUR : {$now->format('d/m/Y H:i')}.";
        $lines[] = "RESUME : {$openTasks->count()} tache(s) ouverte(s) visible(s), dont {$overdue} en retard.";

        if ($overdue > 0) {
            $lines[] = "Si l'utilisateur demande quoi faire en priorite, oriente-le vers ces retards.";
        }

        return implode("\n", $lines);
    }

    /**
     * Agences accessibles a l'utilisateur, en suivant exactement le meme
     * perimetre que AgencyController@index : une membership `actif`.
     *
     * @return array<int, int>
     */
    public function agencyIds(User $user, ?int $agencyId = null): array
    {
        $query = Agency::whereHas('members', function ($q) use ($user) {
            $q->where('user_id', $user->id)->where('status', 'actif');
        });

        if ($agencyId !== null) {
            $query->where('id', $agencyId);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}

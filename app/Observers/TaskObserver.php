<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Agency;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskStatusResolver;

class TaskObserver
{
    public function created(Task $task): void
    {
        $link = $task->project?->agency_id
            ? "/agences/{$task->project->agency_id}/projets/{$task->project_id}/taches/{$task->id}"
            : null;
        $agencyId = $task->project?->agency_id;

        ActivityLog::create([
            'user_id' => $task->created_by,
            'agency_id' => $task->project?->agency_id,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'action' => 'creation',
            'description' => "a créé la tâche « {$task->title} »",
        ]);

        if ($task->assigned_to) {
            $this->notify($task->assigned_to, 'tache_assignee', 'Nouvelle tâche assignée', $this->actorContext($task).' vous a assigné à « '.$task->title.' »', $link, $agencyId);
        }
    }

    public function updated(Task $task): void
    {
        $userId = auth()->id() ?? $task->created_by;

        $link = $task->project?->agency_id
            ? "/agences/{$task->project->agency_id}/projets/{$task->project_id}/taches/{$task->id}"
            : null;
        $agencyId = $task->project?->agency_id;

        if ($task->wasChanged('status')) {
            // `is_terminal` (et non la cle 'terminee') definit ce qui cloture
            // une tache : une agence peut renommer sa colonne de fin.
            $isTerminal = $task->isTerminalStatus();
            $label = TaskStatusResolver::forAgency((int) $task->project?->agency_id)
                ->firstWhere('key', $task->status)['label'] ?? $task->status;

            if ($isTerminal) {
                ActivityLog::create([
                    'user_id' => $userId,
                    'agency_id' => $task->project?->agency_id,
                    'project_id' => $task->project_id,
                    'task_id' => $task->id,
                    'action' => 'tache_terminee',
                    'description' => "a marqué la tâche « {$task->title} » comme terminée",
                ]);
            } else {
                ActivityLog::create([
                    'user_id' => $userId,
                    'agency_id' => $task->project?->agency_id,
                    'project_id' => $task->project_id,
                    'task_id' => $task->id,
                    'action' => 'changement_statut',
                    'description' => "a changé le statut de « {$task->title} » en ".$label,
                ]);
            }

            if ($isTerminal && $task->assigned_to) {
                $this->notify($task->assigned_to, 'tache_terminee', 'Tâche terminée', $this->actorContext($task).' a marqué « '.$task->title.' » comme terminée', $link, $agencyId);
            }
        }

        if ($task->wasChanged('assigned_to')) {
            ActivityLog::create([
                'user_id' => $userId,
                'agency_id' => $task->project?->agency_id,
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'action' => 'changement_responsable',
                'description' => "a changé le responsable de « {$task->title} »",
            ]);

            // Le nouveau responsable est notifié
            if ($task->assigned_to) {
                $this->notify($task->assigned_to, 'tache_assignee', 'Nouvelle tâche assignée', $this->actorContext($task).' vous a assigné à « '.$task->title.' »', $link, $agencyId);
            }

            // L'ancien responsable, s'il y en avait un, est notifié qu'il en est retiré
            $previous = $task->getOriginal('assigned_to');
            if ($previous) {
                $this->notify($previous, 'tache_retiree', 'Retiré d\'une tâche', $this->actorContext($task).' vous a retiré de « '.$task->title.' »', $link, $agencyId);
            }
        }

        if ($task->wasChanged('priority')) {
            ActivityLog::create([
                'user_id' => $userId,
                'agency_id' => $task->project?->agency_id,
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'action' => 'changement_priorite',
                'description' => "a changé la priorité de « {$task->title} » en ".$task->priority,
            ]);
        }

        if ($task->wasChanged('due_date')) {
            ActivityLog::create([
                'user_id' => $userId,
                'agency_id' => $task->project?->agency_id,
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'action' => 'changement_echeance',
                'description' => "a changé l'échéance de « {$task->title} »",
            ]);

            // Une nouvelle échéance autorise un nouveau rappel
            $task->forceFill(['reminder_sent_at' => null])->saveQuietly();
        }
    }

    /**
     * Qui a fait l'action, pour le message de notification :
     * nom de l'auteur + son rôle dans l'agence (propriétaire / admin / membre)
     * + le nom de l'agence concernée.
     *
     * Ex. : « Aliiance ODAH (propriétaire) de l'agence « Freelance » »
     */
    private function actorContext(Task $task): string
    {
        $agency = $task->project?->agency;
        $actorId = (int) (auth()->id() ?? $task->created_by ?? 0);
        $actor = $actorId > 0 ? User::find($actorId) : null;

        $name = $actor?->name ?: 'Un administrateur';
        $role = $this->roleLabel($actor, $agency);
        $context = $role ? "{$name} ({$role})" : $name;

        return $agency?->name ? "{$context} de l'agence « {$agency->name} »" : $context;
    }

    /**
     * Rôle de l'auteur dans l'agence : le propriétaire (agency->owner_id)
     * prime sur sa ligne de membre, souvent déjà « admin ».
     */
    private function roleLabel(?User $actor, ?Agency $agency): ?string
    {
        if (! $actor || ! $agency) {
            return null;
        }

        if ((int) $agency->owner_id === (int) $actor->id) {
            return 'propriétaire';
        }

        return match ($actor->roleInAgency((int) $agency->id)) {
            'proprietaire', 'propriétaire' => 'propriétaire',
            'admin' => 'admin',
            'membre' => 'membre',
            default => null,
        };
    }

    private function notify(int $userId, string $type, string $title, string $message, ?string $link = null, ?int $agencyId = null): void
    {
        Notification::notifyUser($userId, $type, $title, $message, $link, $agencyId);
    }
}

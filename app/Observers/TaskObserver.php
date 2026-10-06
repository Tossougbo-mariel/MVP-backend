<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Task;
use App\Support\TaskStatusResolver;

class TaskObserver
{
    public function created(Task $task): void
    {
        $link = $task->project?->agency_id
            ? "/agences/{$task->project->agency_id}/projets/{$task->project_id}/taches/{$task->id}"
            : null;

        ActivityLog::create([
            'user_id' => $task->created_by,
            'agency_id' => $task->project?->agency_id,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'action' => 'creation',
            'description' => "a créé la tâche « {$task->title} »",
        ]);

        if ($task->assigned_to) {
            $this->notify($task->assigned_to, 'tache_assignee', 'Nouvelle tâche assignée', "On vous a assigné « {$task->title} »", $link);
        }
    }

    public function updated(Task $task): void
    {
        $userId = auth()->id() ?? $task->created_by;

        $link = $task->project?->agency_id
            ? "/agences/{$task->project->agency_id}/projets/{$task->project_id}/taches/{$task->id}"
            : null;

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
                $this->notify($task->assigned_to, 'tache_terminee', 'Tâche terminée', "« {$task->title} » a été marquée comme terminée", $link);
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
                $this->notify($task->assigned_to, 'tache_assignee', 'Nouvelle tâche assignée', "On vous a assigné « {$task->title} »", $link);
            }

            // L'ancien responsable, s'il y en avait un, est notifié qu'il en est retiré
            $previous = $task->getOriginal('assigned_to');
            if ($previous) {
                $this->notify($previous, 'tache_retiree', 'Retiré d\'une tâche', "Vous avez été retiré de « {$task->title} »", $link);
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

    private function notify(int $userId, string $type, string $title, string $message, ?string $link = null): void
    {
        Notification::notifyUser($userId, $type, $title, $message, $link);
    }
}

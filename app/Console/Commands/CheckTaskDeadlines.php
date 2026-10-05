<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Task;
use App\Support\TaskStatusResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckTaskDeadlines extends Command
{
    protected $signature = 'app:check-task-deadlines';

    protected $description = 'Notifie les destinataires des tâches à échéance proche (3 jours) et des tâches en retard';

    public function handle(): int
    {
        $today = Carbon::today();

        // Preselection large (toutes les cles terminales possibles), puis
        // verification authoritative par agence : un statut peut cloturer une
        // tache dans une agence et pas dans une autre.
        $terminalKeys = TaskStatusResolver::allTerminalKeys();

        $approaching = Task::query()
            ->whereNotIn('status', $terminalKeys)
            ->whereNotNull('assigned_to')
            ->whereDate('due_date', $today->copy()->addDays(3))
            ->with(['project.agency.members'])
            ->get()
            ->filter(fn (Task $task) => ! $task->isTerminalStatus())
            ->values();

        foreach ($approaching as $task) {
            $due = Carbon::parse($task->due_date)->format('d/m/Y');

            $this->notifyTaskRecipients(
                $task,
                'echeance_proche',
                "Échéance proche — « {$task->title} »",
                "La tâche « {$task->title} » arrive à échéance le {$due}. Merci de la finaliser avant cette date.",
            );
        }

        $overdue = Task::query()
            ->whereNotIn('status', $terminalKeys)
            ->whereDate('due_date', '<', $today)
            ->with(['project.agency.members'])
            ->get()
            ->filter(fn (Task $task) => ! $task->isTerminalStatus())
            ->values();

        foreach ($overdue as $task) {
            $due = Carbon::parse($task->due_date)->format('d/m/Y');

            $this->notifyTaskRecipients(
                $task,
                'tache_en_retard',
                "Tâche en retard — « {$task->title} »",
                "La tâche « {$task->title} » a dépassé son échéance du {$due}. Merci de la traiter au plus vite.",
            );
        }

        $this->info(sprintf(
            '%d tâche(s) à échéance proche, %d tâche(s) en retard traitée(s).',
            $approaching->count(),
            $overdue->count(),
        ));

        return self::SUCCESS;
    }

    private function notifyTaskRecipients(Task $task, string $type, string $title, string $message): void
    {
        $agency = $task->project?->agency;

        $recipients = collect([$task->assigned_to]);

        if ($agency) {
            $recipients->push($agency->owner_id);
            $recipients->push(
                ...$agency->members()
                    ->where('role', 'admin')
                    ->where('status', 'actif')
                    ->pluck('user_id')
                    ->all()
            );
        }

        // Le lien identifie la tache de facon stable : c'est lui qui sert de
        // cle de deduplication. Comparer les titres ne suffit pas, deux
        // taches homonymes dans deux agences se neutraliseraient, et une tache
        // reportee a une nouvelle date ne serait plus signalee.
        $link = $task->project?->agency_id
            ? "/agences/{$task->project->agency_id}/projets/{$task->project_id}/taches/{$task->id}"
            : null;

        foreach ($recipients->filter()->unique() as $userId) {
            $alreadyNotified = Notification::query()
                ->where('user_id', $userId)
                ->where('type', $type)
                ->where('link', $link)
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            // notifyUser et non create() : comme pour toutes les autres
            // notifications, on respecte les preferences de la personne et on
            // diffuse l'evenement temps reel (Reverb).
            Notification::notifyUser((int) $userId, $type, $title, $message, $link);
        }
    }
}

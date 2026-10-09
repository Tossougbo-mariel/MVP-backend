<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Task;
use App\Support\TaskStatusResolver;
use Illuminate\Console\Command;

class SendDeadlineReminders extends Command
{
    protected $signature = 'tasks:send-deadline-reminders {--days=1}';

    protected $description = "Notifie les responsables des tâches dont l'échéance approche";

    public function handle(): int
    {
        $target = now()->addDays((int) $this->option('days'))->toDateString();

        $tasks = Task::with('project')
            ->whereNotNull('assigned_to')
            ->whereNotIn('status', TaskStatusResolver::allTerminalKeys())
            ->whereDate('due_date', $target)
            ->whereNull('reminder_sent_at')
            ->get()
            // Verification par agence : le statut peut etre terminal pour une
            // agence et pas pour une autre.
            ->filter(fn (Task $task) => ! $task->isTerminalStatus())
            ->values();

        foreach ($tasks as $task) {
            $link = $task->project?->agency_id
                ? "/agences/{$task->project->agency_id}/projets/{$task->project_id}/taches/{$task->id}"
                : null;

            Notification::notifyUser(
                (int) $task->assigned_to,
                'rappel_echeance',
                'Échéance proche',
                "La tâche « {$task->title} » arrive à échéance le {$task->due_date}.",
                $link,
                $task->project?->agency_id
            );

            $task->forceFill(['reminder_sent_at' => now()])->saveQuietly();
        }

        $this->info($tasks->count().' rappel(s) envoyé(s).');

        return self::SUCCESS;
    }
}

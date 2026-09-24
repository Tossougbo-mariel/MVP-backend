<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckTaskDeadlines extends Command
{
    protected $signature = 'app:check-task-deadlines';

    protected $description = 'Notifie les destinataires des tâches à échéance proche (3 jours) et des tâches en retard';

    public function handle(): int
    {
        $today = Carbon::today();

        $approaching = Task::query()
            ->where('status', '!=', 'terminee')
            ->whereNotNull('assigned_to')
            ->whereDate('due_date', $today->copy()->addDays(3))
            ->with(['project.agency.members'])
            ->get();

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
            ->where('status', '!=', 'terminee')
            ->whereDate('due_date', '<', $today)
            ->with(['project.agency.members'])
            ->get();

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

        foreach ($recipients->filter()->unique() as $userId) {
            $alreadyNotified = Notification::query()
                ->where('user_id', $userId)
                ->where('type', $type)
                ->where('title', $title)
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            Notification::create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
            ]);
        }
    }
}

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rappel quotidien des tâches dont l'échéance est le lendemain
Schedule::command('tasks:send-deadline-reminders')->dailyAt('08:00');

// Alertes à 3 jours et signalements de retard. Sans cet enregistrement la
// commande n'était jamais exécutée : ses notifications restaient muettes.
Schedule::command('app:check-task-deadlines')
    ->dailyAt('08:15')
    ->withoutOverlapping();

<?php

$member = App\Models\AgencyMember::where('agency_id', 1)->where('status', 'actif')->first();
$assignee = $member?->user_id ?? App\Models\User::query()->value('id');

$task = App\Models\Task::create([
    'project_id' => 1,
    'title' => 'zzz test retard avec assigne',
    'status' => 'a_faire',
    'priority' => 'moyenne',
    'assigned_to' => $assignee,
    'created_by' => App\Models\User::query()->value('id'),
    'due_date' => now()->subDays(2)->toDateString(),
]);

echo $task->id . ' ' . $assignee;
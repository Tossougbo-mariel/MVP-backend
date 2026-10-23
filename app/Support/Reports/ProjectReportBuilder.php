<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\Project;
use App\Models\Task;
use App\Support\TaskStatusResolver;
use Illuminate\Support\Carbon;

/**
 * Construit les donnees du « Rapport de projet ».
 *
 * Rapport de gestion classique : informations generales du projet, puis detail
 * de chaque tache (titre, statut, priorite, responsable, echeance, avancement).
 * Aucun bloc statistique.
 */
class ProjectReportBuilder extends ReportBuilder
{
    public function __construct(private readonly Project $project)
    {
        $this->project->loadMissing(['tasks.assignee', 'members.user', 'agency', 'owner']);
    }

    public function build(): array
    {
        $project = $this->project;
        $agencyId = (int) $project->agency_id;
        $agencyName = $project->agency?->name ?? __('reports.rows.unknownAgency');

        $statuses = TaskStatusResolver::forAgency($agencyId)->values();
        $credits = $this->progressCredits($statuses);
        $today = Carbon::today();

        $tasks = $project->tasks;

        $isDone = fn (Task $t): bool => $this->isTerminal($agencyId, $t->status);
        $pctOf = fn (Task $t): int => $isDone($t) ? 100 : ($credits[$t->status] ?? 0);
        $dueOf = fn (Task $t): ?Carbon => $this->parseDate($t->due_date);
        $isOverdue = function (Task $t) use ($isDone, $dueOf, $today): bool {
            if ($isDone($t)) {
                return false;
            }
            $due = $dueOf($t);

            return $due !== null && $due->copy()->startOfDay()->lt($today);
        };

        // ---- Dates cles ----
        $start = $this->parseDate($project->start_date);
        $due = $this->parseDate($project->due_date);
        $plannedDays = $this->daysBetween($start, $due);
        $remaining = $this->daysBetween($today, $due);
        $remainingLabel = $remaining === null
            ? '—'
            : ($remaining >= 0
                ? $this->daysLabel($remaining)
                : __('reports.rows.overdueBy', ['duration' => $this->daysLabel(abs($remaining))]));

        // ---- Membres du projet ----
        $memberNames = $project->members
            ->map(fn ($m) => $m->user ? $this->memberName($m->user) : null)
            ->filter()
            ->values();
        $membersValue = $memberNames->isEmpty() ? '—' : $memberNames->implode(', ');

        // ---- Detail des taches ----
        $detailRows = $tasks
            ->sort(function (Task $a, Task $b) use ($isOverdue, $dueOf): int {
                $ao = $isOverdue($a) ? 0 : 1;
                $bo = $isOverdue($b) ? 0 : 1;
                if ($ao !== $bo) {
                    return $ao - $bo;
                }
                $ad = $dueOf($a)?->getTimestamp() ?? PHP_INT_MAX;
                $bd = $dueOf($b)?->getTimestamp() ?? PHP_INT_MAX;
                if ($ad !== $bd) {
                    return $ad <=> $bd;
                }

                return strcmp($a->title, $b->title);
            })
            ->values()
            ->map(function (Task $t, int $i) use ($isOverdue, $pctOf, $dueOf, $statuses): array {
                return [
                    (string) ($i + 1),
                    $t->title,
                    $this->statusLabel($statuses, $t->status),
                    $this->priorityLabel($t->priority),
                    $t->assignee ? $this->memberName($t->assignee) : __('reports.rows.unassigned'),
                    $this->fmtDate($dueOf($t)),
                    $isOverdue($t) ? __('reports.rows.overdue') : $this->pct($pctOf($t), 100),
                ];
            })
            ->all();

        $sections = [
            [
                'heading' => __('reports.general'),
                'type' => 'keyvalue',
                'rows' => array_values(array_filter([
                    ['label' => __('reports.keys.project'), 'value' => $project->name],
                    ['label' => __('reports.keys.agency'), 'value' => $agencyName],
                    ['label' => __('reports.keys.status'), 'value' => $this->projectStatusLabel($project->status)],
                    $project->owner ? ['label' => __('reports.keys.owner'), 'value' => $this->memberName($project->owner)] : null,
                    ['label' => __('reports.keys.startDate'), 'value' => $this->fmtLongDate($start)],
                    ['label' => __('reports.keys.dueDate'), 'value' => $this->fmtLongDate($due)],
                    ['label' => __('reports.keys.plannedDuration'), 'value' => $this->daysLabel($plannedDays)],
                    ['label' => __('reports.keys.remainingTime'), 'value' => $remainingLabel],
                    ['label' => __('reports.keys.projectMembers'), 'value' => $membersValue],
                ])),
            ],
            [
                'heading' => __('reports.taskDetails'),
                'type' => 'table',
                'columns' => [
                    __('reports.columns.number'),
                    __('reports.columns.task'),
                    __('reports.columns.status'),
                    __('reports.columns.priority'),
                    __('reports.columns.assignee'),
                    __('reports.columns.dueDate'),
                    __('reports.columns.progress'),
                ],
                'align' => ['num', '', '', '', '', '', 'num'],
                'rows' => $detailRows !== []
                    ? $detailRows
                    : [['—', __('reports.rows.noTask'), '—', '—', '—', '—', '—']],
            ],
        ];

        return [
            'title' => __('reports.projectTitle'),
            'subtitle' => $project->name.' — '.$agencyName,
            'generatedAt' => $this->fmtLongDate(Carbon::now()).' '.__('reports.at').' '.Carbon::now()->format('H:i'),
            'description' => $project->description ?: null,
            'sections' => $sections,
        ];
    }
}

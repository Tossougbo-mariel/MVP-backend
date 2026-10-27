<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\Agency;
use Illuminate\Support\Carbon;

class AgencyReportBuilder extends ReportBuilder
{
    public function __construct(private readonly Agency $agency)
    {
        $this->agency->loadMissing(['projects.owner', 'projects.tasks', 'members.user', 'owner']);
    }

    public function build(): array
    {
        $agency = $this->agency;

        $projectRows = [];
        foreach ($agency->projects->sortBy('name') as $p) {
            $projectRows[] = [
                $p->name,
                $this->projectStatusLabel($p->status),
                $p->owner ? $this->memberName($p->owner) : '—',
                $this->fmtDate($this->parseDate($p->start_date)),
                $this->fmtDate($this->parseDate($p->due_date)),
                $p->progress.' %',
            ];
        }

        $memberRows = [];
        foreach ($agency->members as $am) {
            $u = $am->user;
            if (! $u) {
                continue;
            }
            $role = (int) $u->id === (int) $agency->owner_id
                ? __('reports.rows.agencyOwner')
                : ($am->role === 'admin' ? __('reports.rows.admin') : __('reports.rows.member'));

            $memberRows[] = [
                $this->memberName($u),
                $role,
                $this->memberStatusLabel($am->status),
            ];
        }

        $sections = [
            [
                'heading' => __('reports.general'),
                'type' => 'keyvalue',
                'rows' => array_values(array_filter([
                    ['label' => __('reports.keys.agency'), 'value' => $agency->name],
                    $agency->owner ? ['label' => __('reports.keys.owner'), 'value' => $this->memberName($agency->owner)] : null,
                ])),
            ],
            [
                'heading' => __('reports.projectList'),
                'type' => 'table',
                'columns' => [
                    __('reports.columns.project'),
                    __('reports.columns.status'),
                    __('reports.columns.assignee'),
                    __('reports.columns.startDate'),
                    __('reports.columns.dueDate'),
                    __('reports.columns.progress'),
                ],
                'align' => ['', '', '', '', '', 'num'],
                'rows' => $projectRows !== []
                    ? $projectRows
                    : [[__('reports.rows.noProject'), '—', '—', '—', '—', '—']],
            ],
            [
                'heading' => __('reports.memberList'),
                'type' => 'table',
                'columns' => [
                    __('reports.columns.member'),
                    __('reports.columns.role'),
                    __('reports.columns.status'),
                ],
                'align' => ['', '', ''],
                'rows' => $memberRows !== []
                    ? $memberRows
                    : [[__('reports.rows.noMember'), '—', '—']],
            ],
        ];

        return [
            'title' => __('reports.agencyTitle'),
            'subtitle' => $agency->name,
            'generatedAt' => $this->fmtLongDate(Carbon::now()).' '.__('reports.at').' '.Carbon::now()->format('H:i'),
            'description' => $agency->description ?: null,
            'sections' => $sections,
        ];
    }
}

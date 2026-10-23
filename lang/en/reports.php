<?php

return [
    'projectTitle' => 'Project report',
    'agencyTitle' => 'Agency report',
    'generatedOn' => 'Generated on',
    'at' => 'at',
    'page' => 'Page',
    'noData' => 'No data.',

    'general' => 'General information',
    'taskDetails' => 'Task details',
    'projectList' => 'Project list',
    'memberList' => 'Member list',

    'keys' => [
        'project' => 'Project',
        'agency' => 'Agency',
        'status' => 'Status',
        'owner' => 'Owner',
        'startDate' => 'Start date',
        'dueDate' => 'Due date',
        'plannedDuration' => 'Planned duration',
        'remainingTime' => 'Time remaining',
        'projectMembers' => 'Project members',
        'member' => 'Member',
        'role' => 'Role',
    ],

    'columns' => [
        'number' => 'No.',
        'task' => 'Task',
        'status' => 'Status',
        'priority' => 'Priority',
        'assignee' => 'Assignee',
        'dueDate' => 'Due date',
        'progress' => 'Progress',
        'project' => 'Project',
        'startDate' => 'Start',
    ],

    'rows' => [
        'noTask' => 'No task',
        'noProject' => 'No project',
        'noMember' => 'No member',
        'unassigned' => 'Unassigned',
        'overdue' => 'Overdue',
        'overdueBy' => 'Overdue by :duration',
        'unknownAgency' => 'Agency',
        'agencyOwner' => 'Owner',
        'admin' => 'Admin',
        'member' => 'Member',
    ],

    'statuses' => [
        'project' => [
            'a_venir' => 'Upcoming',
            'en_cours' => 'In progress',
            'termine' => 'Completed',
            'archive' => 'Archived',
        ],
        'member' => [
            'actif' => 'Active',
            'en_attente' => 'Pending',
            'inactif' => 'Inactive',
        ],
    ],

    'priorities' => [
        'urgente' => 'Urgent',
        'haute' => 'High',
        'moyenne' => 'Medium',
        'basse' => 'Low',
    ],

    'duration' => ':count day|:count days',
];

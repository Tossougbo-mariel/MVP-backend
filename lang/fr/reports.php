<?php

return [
    'projectTitle' => 'Rapport de projet',
    'agencyTitle' => "Rapport d'agence",
    'generatedOn' => 'Généré le',
    'at' => 'à',
    'page' => 'Page',
    'noData' => 'Aucune donnée.',

    'general' => 'Informations générales',
    'taskDetails' => 'Détail des tâches',
    'projectList' => 'Liste des projets',
    'memberList' => 'Liste des membres',

    'keys' => [
        'project' => 'Projet',
        'agency' => 'Agence',
        'status' => 'Statut',
        'owner' => 'Responsable',
        'startDate' => 'Date de début',
        'dueDate' => "Date d'échéance",
        'plannedDuration' => 'Durée planifiée',
        'remainingTime' => 'Temps restant',
        'projectMembers' => 'Membres du projet',
        'member' => 'Membre',
        'role' => 'Rôle',
    ],

    'columns' => [
        'number' => 'N°',
        'task' => 'Tâche',
        'status' => 'Statut',
        'priority' => 'Priorité',
        'assignee' => 'Responsable',
        'dueDate' => 'Échéance',
        'progress' => 'Avancement',
        'project' => 'Projet',
        'startDate' => 'Début',
    ],

    'rows' => [
        'noTask' => 'Aucune tâche',
        'noProject' => 'Aucun projet',
        'noMember' => 'Aucun membre',
        'unassigned' => 'Non assignée',
        'overdue' => 'En retard',
        'overdueBy' => 'Dépassée de :duration',
        'unknownAgency' => 'Agence',
        'agencyOwner' => 'Propriétaire',
        'admin' => 'Admin',
        'member' => 'Membre',
    ],

    'statuses' => [
        'project' => [
            'a_venir' => 'À venir',
            'en_cours' => 'En cours',
            'termine' => 'Terminé',
            'archive' => 'Archivé',
        ],
        'member' => [
            'actif' => 'Actif',
            'en_attente' => 'En attente',
            'inactif' => 'Inactif',
        ],
    ],

    'priorities' => [
        'urgente' => 'Urgente',
        'haute' => 'Haute',
        'moyenne' => 'Moyenne',
        'basse' => 'Basse',
    ],

    'duration' => ':count jour|:count jours',
];

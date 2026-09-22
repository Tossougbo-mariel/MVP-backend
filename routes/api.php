<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AgencyController;
use App\Http\Controllers\AgencyMemberController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\SubtaskController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskDependencyController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

// ── Authentification (publique) ────────────────────────────
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
Route::post('/password/reset', [AuthController::class, 'resetPassword']);

// ── Invitation : aperçu public (la personne n'est pas encore connectée) ──
Route::get('/invitations/{token}', [InvitationController::class, 'show']);
Route::middleware('auth:sanctum')->group(function () {

    // ── Authentification (protégé) ─────────────────────────
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'updateProfile']);
    Route::get('/me/notification-preferences', [AuthController::class, 'notificationPreferences']);
    Route::put('/me/notification-preferences', [AuthController::class, 'updateNotificationPreferences']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // ── Agences ──────────────────────────────────────────────
    Route::get('/agencies', [AgencyController::class, 'index']);
    Route::post('/agencies', [AgencyController::class, 'store']);
    Route::get('/agencies/{agency}', [AgencyController::class, 'show']);
    Route::put('/agencies/{agency}', [AgencyController::class, 'update']);
    Route::delete('/agencies/{agency}', [AgencyController::class, 'destroy']);

    // ── Membres d'agence (membres déjà en place : rôle, statut, retrait) ──
    Route::get('/agencies/{agency}/members', [AgencyMemberController::class, 'index']);
    Route::put('/agencies/{agency}/members/{agencyMember}', [AgencyMemberController::class, 'update']);
    Route::delete('/agencies/{agency}/members/{agencyMember}', [AgencyMemberController::class, 'destroy']);

    // ── Invitations d'agence ──
    Route::get('/agencies/{agency}/invitations', [InvitationController::class, 'index']);
    Route::post('/agencies/{agency}/invitations', [InvitationController::class, 'store']);
    Route::post('/agencies/{agency}/invitations/{invitation}/resend', [InvitationController::class, 'resend']);
    Route::delete('/agencies/{agency}/invitations/{invitation}', [InvitationController::class, 'destroy']);
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);

    // ── Équipes ──
    Route::get('/agencies/{agency}/teams', [TeamController::class, 'index']);
    Route::post('/agencies/{agency}/teams', [TeamController::class, 'store']);
    Route::put('/teams/{team}', [TeamController::class, 'update']);
    Route::delete('/teams/{team}', [TeamController::class, 'destroy']);

    // ── Projets ──────────────────────────────────────────────
    Route::get('/agencies/{agency}/projects', [ProjectController::class, 'index']);
    Route::post('/agencies/{agency}/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::put('/projects/{project}', [ProjectController::class, 'update']);
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);

    // ── Membres de projet ────────────────────────────────────
    Route::get('/projects/{project}/members', [ProjectMemberController::class, 'index']);
    Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store']);
    Route::delete('/projects/{project}/members/{projectMember}', [ProjectMemberController::class, 'destroy']);

    // ── Tâches ───────────────────────────────────────────────
    Route::get('/projects/{project}/tasks', [TaskController::class, 'index']);
    Route::post('/projects/{project}/tasks', [TaskController::class, 'store']);
    Route::post('/projects/{project}/tasks/bulk', [TaskController::class, 'bulkUpdate']);
    Route::get('/tasks/{task}', [TaskController::class, 'show']);
    Route::put('/tasks/{task}', [TaskController::class, 'update']);
    Route::patch('/tasks/{task}/status', [TaskController::class, 'updateStatus']);
    Route::patch('/tasks/{task}/archive', [TaskController::class, 'archive']);
    Route::patch('/tasks/{task}/restore', [TaskController::class, 'restore']);
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
    Route::put('/tasks/{task}/tags', [TaskController::class, 'updateTags']);

    // ── Export CSV ───────────────────────────────────────────
    Route::get('/agencies/{agency}/tasks/export', [TaskController::class, 'export']);

    // ── Dépendances entre tâches ─────────────────────────────
    Route::get('/tasks/{task}/dependencies', [TaskDependencyController::class, 'index']);
    Route::post('/tasks/{task}/dependencies', [TaskDependencyController::class, 'store']);
    Route::delete('/tasks/{task}/dependencies/{dependency}', [TaskDependencyController::class, 'destroy']);

    // ── Étiquettes ───────────────────────────────────────────
    Route::get('/agencies/{agency}/tags', [TagController::class, 'index']);
    Route::post('/agencies/{agency}/tags', [TagController::class, 'store']);
    Route::put('/tags/{tag}', [TagController::class, 'update']);
    Route::delete('/tags/{tag}', [TagController::class, 'destroy']);

    // ── Pièces jointes ───────────────────────────────────────
    Route::get('/tasks/{task}/attachments', [AttachmentController::class, 'index']);
    Route::post('/tasks/{task}/attachments', [AttachmentController::class, 'store']);
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download']);
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy']);

    // ── Sous-tâches ──────────────────────────────────────────
    Route::get('/tasks/{task}/subtasks', [SubtaskController::class, 'index']);
    Route::post('/tasks/{task}/subtasks', [SubtaskController::class, 'store']);
    Route::put('/subtasks/{subtask}', [SubtaskController::class, 'update']);
    Route::delete('/subtasks/{subtask}', [SubtaskController::class, 'destroy']);

    // ── Commentaires ─────────────────────────────────────────
    Route::get('/tasks/{task}/comments', [CommentController::class, 'index']);
    Route::post('/tasks/{task}/comments', [CommentController::class, 'store']);
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy']);

    // ── Journal d'activité ───────────────────────────────────
    Route::get('/activity', [ActivityLogController::class, 'index']);

    // ── Notifications ────────────────────────────────────────
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
});

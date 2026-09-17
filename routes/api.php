<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AgencyController;
use App\Http\Controllers\AgencyMemberController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

// ── Authentification (publique) ────────────────────────────
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
Route::post('/password/reset', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {

    // ── Authentification (protégé) ─────────────────────────
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'updateProfile']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // ── Agences ──────────────────────────────────────────────
    Route::get('/agencies', [AgencyController::class, 'index']);
    Route::post('/agencies', [AgencyController::class, 'store']);
    Route::get('/agencies/{agency}', [AgencyController::class, 'show']);
    Route::put('/agencies/{agency}', [AgencyController::class, 'update']);
    Route::delete('/agencies/{agency}', [AgencyController::class, 'destroy']);

    // ── Membres d'agence ─────────────────────────────────────
    Route::get('/agencies/{agency}/members', [AgencyMemberController::class, 'index']);
    Route::post('/agencies/{agency}/members', [AgencyMemberController::class, 'store']);
    Route::post('/agency-members/{agencyMember}/accept', [AgencyMemberController::class, 'accept']);
    Route::put('/agencies/{agency}/members/{agencyMember}', [AgencyMemberController::class, 'update']);
    Route::delete('/agencies/{agency}/members/{agencyMember}', [AgencyMemberController::class, 'destroy']);

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
    Route::get('/tasks/{task}', [TaskController::class, 'show']);
    Route::put('/tasks/{task}', [TaskController::class, 'update']);
    Route::patch('/tasks/{task}/status', [TaskController::class, 'updateStatus']);
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);

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

<?php

use App\Http\Controllers\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Route nommAce "login" : cible de redirection du middleware d'authentification
// quand l'utilisateur n'est pas authentifiAc (elle ne doit pas exister sinon l'API renvoie 500).
Route::get('/login', function () {
    return response()->json(['message' => 'Non authentifiAc.'], 401);
})->name('login');

// ── Connexion Google ────────────────────────────────────────
// Volontairement hors du préfixe /api : ce sont exactement les deux URL
// déclarées côté console Google (GOOGLE_REDIRECT_URI), que Google rappelle
// après consentement.
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');

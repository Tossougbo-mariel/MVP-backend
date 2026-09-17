<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Route nommée "login" : cible de redirection du middleware d'authentification
// quand l'utilisateur n'est pas authentifié (elle ne doit pas exister sinon l'API renvoie 500).
Route::get('/login', function () {
    return response()->json(['message' => 'Non authentifié.'], 401);
})->name('login');

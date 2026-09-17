<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    // POST /api/register
    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'avatar' => ['nullable', 'string', 'max:1000000'],
        ]);

        $user = User::create([
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'avatar' => $data['avatar'] ?? null,
            'status' => 'actif',
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    // POST /api/login
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Identifiants incorrects.'], 401);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    // POST /api/logout
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté avec succès.']);
    }

    // POST /api/change-password
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Le mot de passe actuel est incorrect.'], 422);
        }

        if (Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Le nouveau mot de passe doit être différent de l\'actuel.'], 422);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Invalide les autres sessions, garde celle en cours
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Mot de passe modifié avec succès.']);
    }

    // GET /api/me
    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    // PUT /api/me — met à jour les informations personnelles de l'utilisateur connecté
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'string', 'max:1000000'],
        ]);

        $user->first_name = $data['first_name'];
        $user->last_name = $data['last_name'];
        $user->name = trim($data['first_name'].' '.$data['last_name']);
        $user->email = $data['email'];
        $user->phone = $data['phone'] ?? null;
        $user->city = $data['city'] ?? null;
        $user->bio = $data['bio'] ?? null;
        $user->job_title = $data['job_title'] ?? null;

        if (array_key_exists('avatar', $data)) {
            $user->avatar = $data['avatar'] ?? null;
        }

        $user->save();

        return response()->json($user);
    }

    // POST /api/password/forgot
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = PasswordBroker::sendResetLink($request->only('email'));

        return $status === PasswordBroker::RESET_LINK_SENT
            ? response()->json(['message' => 'Lien envoyé si ce compte existe.'])
            : response()->json(['message' => 'Impossible d\'envoyer le lien.'], 422);
    }

    // POST /api/password/reset
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', Password::min(8), 'confirmed'],
        ]);

        $status = PasswordBroker::reset($data, function ($user, $password) {
            $user->forceFill([
                'password' => $password,
                'status' => 'actif', // réactive un compte qui était "invite"
            ])->save();
        });

        return $status === PasswordBroker::PASSWORD_RESET
            ? response()->json(['message' => 'Mot de passe défini avec succès.'])
            : response()->json(['message' => 'Lien invalide ou expiré.'], 422);
    }
}

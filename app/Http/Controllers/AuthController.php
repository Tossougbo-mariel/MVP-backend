<?php

namespace App\Http\Controllers;

use App\Models\EmailOtpCode;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Auth\TwoFactorTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        protected OtpService $otp,
        protected TwoFactorTicket $tickets,
    ) {}
    // POST /api/register
    public function register(Request $request)
    {
        $this->normalizeEmailInput($request);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'avatar' => ['nullable', 'string', 'max:1000000'],
        ]);

        // Un compte "invite" (créé via une invitation) n'a pas encore de mot de passe :
        // on le réutilise et on le complète au lieu de le bloquer.
        $user = User::where('email', $data['email'])->first();

        if ($user && $user->status !== 'invite') {
            return response()->json(['message' => 'Un compte existe déjà avec cet e-mail.'], 422);
        }

        if (! $user) {
            $user = new User;
        }

        $user->fill([
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'avatar' => $data['avatar'] ?? null,
            'status' => 'actif',
        ])->save();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    // POST /api/login
    public function login(Request $request)
    {
        $this->normalizeEmailInput($request);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Un compte créé via Google n'a pas de mot de passe : sans cette garde,
        // Hash::check recevrait null.
        if (! $user || $user->password === null || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Identifiants incorrects.'], 401);
        }

        // Mot de passe correct mais session non ouverte : un second facteur
        // est demandé. Aucun token n'est émis à ce stade.
        if ($user->two_factor_enabled) {
            $ticket = $this->tickets->issue($user);

            try {
                $this->otp->issue(
                    $user,
                    EmailOtpCode::PURPOSE_TWO_FACTOR,
                    $request->ip(),
                    $request->userAgent(),
                );
            } catch (Throwable $e) {
                // La session n'est pas ouverte, mais on ne Renonce pas pour
                // autant : l'utilisateur peut demander un nouveau code.
                Log::warning('Envoi du code 2FA échoué : '.$e->getMessage());
            }

            return response()->json([
                'two_factor_required' => true,
                'ticket' => $ticket,
                'expires_in' => (int) config('auth.otp.two_factor_ticket_ttl', 600),
            ]);
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
        $this->normalizeEmailInput($request);

        $user = $request->user();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'theme_color' => ['nullable', 'string', 'max:50'],
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
        $user->theme_color = $data['theme_color'] ?? null;

        if (array_key_exists('avatar', $data)) {
            $user->avatar = $data['avatar'] ?? null;
        }

        $user->save();

        return response()->json($user);
    }

    // GET /api/me/notification-preferences
    public function notificationPreferences(Request $request)
    {
        return response()->json($request->user()->notificationPreferences());
    }

    // PUT /api/me/notification-preferences
    public function updateNotificationPreferences(Request $request)
    {
        $data = $request->validate([
            'task_assigned' => ['sometimes', 'boolean'],
            'task_completed' => ['sometimes', 'boolean'],
            'task_removed' => ['sometimes', 'boolean'],
            'comment' => ['sometimes', 'boolean'],
            'mention' => ['sometimes', 'boolean'],
            'deadline_reminder' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $preferences = array_merge($user->notificationPreferences(), $data);

        $user->update(['notification_preferences' => $preferences]);

        return response()->json($preferences);
    }

    // POST /api/password/forgot
    public function forgotPassword(Request $request)
    {
        $this->normalizeEmailInput($request);

        $request->validate(['email' => ['required', 'email']]);

        try {
            $status = PasswordBroker::sendResetLink($request->only('email'));
        } catch (Throwable $e) {
            Log::warning('Envoi e-mail de réinitialisation échoué : '.$e->getMessage());

            return response()->json(
                ['message' => "L'envoi a échoué. Réessayez dans quelques instants."],
                502,
            );
        }

        return $status === PasswordBroker::RESET_LINK_SENT
            ? response()->json(['message' => 'Lien envoyé si ce compte existe.'])
            : response()->json(
                ['message' => "L'envoi a échoué. Réessayez dans quelques instants."],
                502,
            );
    }

    // POST /api/password/reset
    public function resetPassword(Request $request)
    {
        $this->normalizeEmailInput($request);

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

    /**
     * Met l'adresse en minuscules avant toute validation.
     *
     * Les emails sont stockés normalisés (voir User::email) : sans cela, la
     * règle `unique` comparerait la casse brute à la base et autoriserait un
     * doublon que la base refuserait ensuite.
     */
    private function normalizeEmailInput(Request $request): void
    {
        if ($request->has('email') && is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
    }
}

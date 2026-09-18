<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\AgencyInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    // GET /api/agencies/{agency}/invitations
    // Liste des invitations (pour que l'admin puisse les gérer : relancer, annuler)
    public function index(Request $request, Agency $agency)
    {
        $this->authorize('manageMembers', $agency);

        $invitations = $agency->invitations()
            ->with('invitedBy:id,name,email')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json($invitations);
    }

    // POST /api/agencies/{agency}/invitations
    // Envoyer une invitation
    public function store(Request $request, Agency $agency)
    {
        $this->authorize('manageMembers', $agency);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['sometimes', 'string', 'in:admin,membre'],
        ]);

        $email = strtolower($data['email']);

        // 1. Règle : impossible de s'inviter soi-même
        if ($email === strtolower($request->user()->email)) {
            return response()->json(['message' => 'Vous ne pouvez pas vous inviter vous-même.'], 422);
        }

        // 2. Règle : impossible d'inviter quelqu'un qui est DÉJÀ membre actif
        $existingUser = User::where('email', $email)->first();
        if ($existingUser && $agency->members()
            ->where('user_id', $existingUser->id)
            ->where('status', 'actif')
            ->exists()) {
            return response()->json(['message' => 'Cette personne est déjà membre de l\'agence.'], 422);
        }

        // 3. Règle : une nouvelle invitation annule l'ancienne encore en attente (même email).
        // Ainsi, il n'y a qu'un seul lien valide à la fois.
        $agency->invitations()
            ->where('email', $email)
            ->where('status', 'en_attente')
            ->update(['status' => 'annulee']);

        // 4. Création de l'invitation avec son token secret (40 caractères aléatoires)
        $invitation = $agency->invitations()->create([
            'email' => $email,
            'role' => $data['role'] ?? 'membre',
            'token' => Str::random(40),
            'status' => 'en_attente',
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        // 5. Compte "coquille" (sans mot de passe) uniquement pour pouvoir lui envoyer
        //    la notification par e-mail. ⚠️ On NE crée PAS de ligne AgencyMember ici :
        //    tant que l'invitation n'est pas confirmée via accept(), cette personne ne
        //    doit apparaître nulle part dans la liste de l'équipe.
        $invitedUser = User::firstOrCreate(
            ['email' => $email],
            ['name' => $email, 'password' => null, 'status' => 'invite']
        );

        // 6. Envoi de l'e-mail avec le lien d'acceptation
        $invitedUser->notify(new AgencyInvitation($invitation));

        return response()->json($invitation, 201);
    }

    // GET /api/invitations/{token}
    // Aperçu public : la personne clique sur le lien AVANT d'être connectée.
    // Il n'y a donc AUCUNE connexion requise ici.
    public function show($token)
    {
        $invitation = Invitation::where('token', $token)->with('agency:id,name')->first();

        if (! $invitation || $invitation->status !== 'en_attente') {
            abort(404, 'Cette invitation n\'existe plus.');
        }

        if ($invitation->isExpired()) {
            $invitation->update(['status' => 'expiree']);
            abort(404, 'Cette invitation a expiré.');
        }

        // On renvoie uniquement ce qui est utile à l'affichage (jamais de données sensibles)
        return response()->json([
            'token' => $invitation->token,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at,
            'agency' => ['id' => $invitation->agency->id, 'name' => $invitation->agency->name],
        ]);
    }

    // POST /api/invitations/{token}/accept
    // Accepter l'invitation (il faut être connecté)
    public function accept(Request $request, $token)
    {
        $invitation = Invitation::where('token', $token)->first();

        if (! $invitation || $invitation->status !== 'en_attente') {
            return response()->json(['message' => 'Cette invitation n\'existe plus.'], 404);
        }

        if ($invitation->isExpired()) {
            $invitation->update(['status' => 'expiree']);
            return response()->json(['message' => 'Cette invitation a expiré.'], 410);
        }

        // SÉCURITÉ IMPORTANTE : le compte connecté doit avoir la MÊME adresse e-mail
        // que celle de l'invitation. Sinon on refuse.
        if (strtolower($request->user()->email) !== $invitation->email) {
            return response()->json([
                'message' => 'Cette invitation est adressée à '.$invitation->email.'. Connectez-vous avec ce compte.',
            ], 403);
        }

        // ✅ C'est SEULEMENT ICI, à la confirmation, que le membre est créé
        // (directement en "actif" — jamais de passage par "en_attente" côté équipe).
        AgencyMember::updateOrCreate(
            ['agency_id' => $invitation->agency_id, 'user_id' => $request->user()->id],
            ['role' => $invitation->role, 'status' => 'actif']
        );

        $invitation->update(['status' => 'acceptee']);

        return response()->json(['ok' => true]);
    }

    // POST /api/agencies/{agency}/invitations/{invitation}/resend
    // Relancer : on crée un nouveau token et on renvoie l'e-mail
    public function resend(Request $request, Agency $agency, Invitation $invitation)
    {
        $this->authorize('manageMembers', $agency);
        abort_if($invitation->agency_id !== $agency->id, 404);

        if ($invitation->status !== 'en_attente') {
            return response()->json(['message' => 'Seules les invitations en attente peuvent être relancées.'], 422);
        }

        $invitation->update([
            'token' => Str::random(40),
            'expires_at' => now()->addDays(7),
        ]);

        $user = User::where('email', $invitation->email)->first();
        if ($user) {
            $user->notify(new AgencyInvitation($invitation));
        }

        return response()->json($invitation);
    }

    // DELETE /api/agencies/{agency}/invitations/{invitation}
    // Annuler une invitation
    public function destroy(Request $request, Agency $agency, Invitation $invitation)
    {
        $this->authorize('manageMembers', $agency);
        abort_if($invitation->agency_id !== $agency->id, 404);

        $invitation->update(['status' => 'annulee']);

        // Filet de sécurité : si une ancienne version du code (avant ce correctif)
        // avait déjà créé un membre "en_attente" pour cette invitation, on le retire.
        // Avec la nouvelle logique de store(), cette ligne ne trouvera normalement
        // jamais rien à supprimer — c'est volontaire, sans danger de la garder.
        $user = User::where('email', $invitation->email)->first();
        if ($user) {
            AgencyMember::where('agency_id', $agency->id)
                ->where('user_id', $user->id)
                ->where('status', 'en_attente')
                ->delete();
        }

        return response()->json(null, 204);
    }
}
<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AgencyInvitation;
use Illuminate\Http\Request;

class AgencyMemberController extends Controller
{
    // GET /api/agencies/{agency}/members
    public function index(Agency $agency)
    {
        $this->authorize('view', $agency);

        $members = $agency->members()->with('user:id,name,email,avatar,first_name,last_name,job_title')->get();

        return response()->json($members);
    }

    // POST /api/agencies/{agency}/members
    public function store(Request $request, Agency $agency)
    {
        $this->authorize('manageMembers', $agency);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['sometimes', 'string', 'in:admin,membre'],
        ]);

        $user = User::firstOrCreate(
            ['email' => $data['email']],
            ['name' => $data['email'], 'password' => null, 'status' => 'invite']
        );

        $membership = AgencyMember::updateOrCreate(
            ['agency_id' => $agency->id, 'user_id' => $user->id],
            [
                'role' => $data['role'] ?? 'membre',
                'status' => 'en_attente',
            ]
        );

        if ($user->wasRecentlyCreated) {
            $user->notify(new AgencyInvitation($agency));
        }

        // Ajouté (ou promu) directement avec le rôle admin : la personne doit
        // le savoir tout de suite, et pas seulement en ouvrant l'agence.
        $becameAdmin = $membership->role === 'admin'
            && ($membership->wasRecentlyCreated || $membership->wasChanged('role'));
        if ($becameAdmin) {
            $this->notifyMember(
                $request,
                $agency,
                $membership->user_id,
                'nomme_admin',
                'Nommé administrateur',
                $this->actorName($request).' vous a nommé administrateur de l\'agence « '.$agency->name.' ».',
                '/agences/'.$agency->id
            );
        }

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'agency_id' => $agency->id,
            'action' => 'membre_ajoute',
            'description' => "a invité « {$user->email} » à rejoindre l'agence",
        ]);

        return response()->json($membership, 201);
    }

    // L'INVITÉ accepte lui-même — c'est ce qui manquait
    public function accept(Request $request, AgencyMember $agencyMember)
    {
        // Sécurité : seul le concerné peut accepter SA PROPRE invitation
        abort_if($agencyMember->user_id !== $request->user()->id, 403);

        $agencyMember->update(['status' => 'actif']);

        return response()->json($agencyMember);
    }

    // PUT /api/agencies/{agency}/members/{agencyMember}
    public function update(Request $request, Agency $agency, AgencyMember $agencyMember)
    {
        $this->authorize('manageMembers', $agency);

        $data = $request->validate([
            'role' => ['sometimes', 'string', 'in:admin,membre'],
            'status' => ['sometimes', 'string', 'in:en_attente,actif,inactif'],
        ]);

        abort_if(
            $agencyMember->user_id === $agency->owner_id
            && array_key_exists('role', $data)
            && $data['role'] !== 'admin',
            422,
            "Le rôle du propriétaire de l'agence ne peut pas être modifié."
        );

        $previousRole = $agencyMember->role;
        $previousStatus = $agencyMember->status;

        $agencyMember->update($data);

        // Ce qui change les accès de la personne doit lui être annoncé : elle
        // n'est pas en train d'observer la liste des membres quand on la
        // nomme, la rétrograde, l'active ou la désactive.
        if (array_key_exists('role', $data) && $data['role'] !== $previousRole) {
            if ($data['role'] === 'admin') {
                $this->notifyMember(
                    $request,
                    $agency,
                    $agencyMember->user_id,
                    'nomme_admin',
                    'Nommé administrateur',
                    $this->actorName($request).' vous a nommé administrateur de l\'agence « '.$agency->name.' ».',
                    '/agences/'.$agency->id
                );
            } else {
                $this->notifyMember(
                    $request,
                    $agency,
                    $agencyMember->user_id,
                    'role_modifie',
                    'Rôle modifié',
                    $this->actorName($request).' a modifié votre rôle dans l\'agence « '.$agency->name.' » : vous êtes désormais « '.$this->roleLabel($data['role']).' ».',
                    '/agences/'.$agency->id
                );
            }
        }

        if (
            array_key_exists('status', $data)
            && $data['status'] !== $previousStatus
            && in_array($data['status'], ['actif', 'inactif'], true)
        ) {
            if ($data['status'] === 'actif') {
                $this->notifyMember(
                    $request,
                    $agency,
                    $agencyMember->user_id,
                    'compte_active',
                    'Compte activé',
                    $this->actorName($request).' a réactivé votre compte dans l\'agence « '.$agency->name.' » : vos accès sont rétablis.',
                    '/agences/'.$agency->id
                );
            } else {
                $this->notifyMember(
                    $request,
                    $agency,
                    $agencyMember->user_id,
                    'compte_desactive',
                    'Compte désactivé',
                    $this->actorName($request).' a désactivé votre compte dans l\'agence « '.$agency->name.' » : vous n\'avez plus accès à ses contenus.',
                    '/agences/'.$agency->id
                );
            }
        }

        return response()->json($agencyMember);
    }

    // DELETE /api/agencies/{agency}/members/{agencyMember}
    public function destroy(Request $request, Agency $agency, AgencyMember $agencyMember)
    {
        $this->authorize('manageMembers', $agency);

        abort_if($agencyMember->agency_id !== $agency->id, 404);

        abort_if(
            $agencyMember->user_id === $agency->owner_id,
            422,
            "Le propriétaire de l'agence ne peut pas être retiré."
        );

        $activeTasks = Task::whereHas('project', fn ($q) => $q->where('agency_id', $agency->id))
            ->where('assigned_to', $agencyMember->user_id)
            ->whereIn('status', ['a_faire', 'en_cours', 'en_revision'])
            ->count();

        if ($activeTasks > 0 && ! $request->boolean('confirm')) {
            return response()->json([
                'message' => "Ce membre a {$activeTasks} tâche(s) en cours dans les projets de l'agence.",
                'active_tasks_count' => $activeTasks,
                'requires_confirmation' => true,
            ], 409);
        }

        // Prévenu avant la suppression de la ligne : une fois l'accès retiré,
        // cette notification est ce qui reste à la personne pour le savoir.
        $this->notifyMember(
            $request,
            $agency,
            $agencyMember->user_id,
            'membre_retire',
            'Retiré de l\'agence',
            $this->actorName($request).' vous a retiré de l\'agence « '.$agency->name.' ».',
            null
        );

        $agencyMember->delete();

        return response()->json(null, 204);
    }

    /**
     * Notification in-app envoyée à la personne que l'action concerne.
     *
     * `$link` mène à l'agence quand elle y a encore accès ; il est `null` pour
     * une exclusion, l'agence lui étant désormais interdite. Les types de
     * rôles et d'accès ne figurent pas dans `NOTIFICATION_TYPE_MAP` : ils sont
     * toujours livrés, c'est précisément l'information que la personne doit
     * recevoir. Personne ne s'écrit à soi-même.
     */
    private function notifyMember(
        Request $request,
        Agency $agency,
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $link
    ): void {
        if ($userId === (int) $request->user()?->id) {
            return;
        }

        Notification::notifyUser($userId, $type, $title, $message, $link, $agency->id);
    }

    /** Auteur du changement, pour le corps du message. */
    private function actorName(Request $request): string
    {
        return $request->user()?->name ?: 'Un administrateur';
    }

    /** Rôle en toutes lettres, pour être lu par la personne concernée. */
    private function roleLabel(string $role): string
    {
        return $role === 'admin' ? 'administrateur' : 'membre';
    }
}

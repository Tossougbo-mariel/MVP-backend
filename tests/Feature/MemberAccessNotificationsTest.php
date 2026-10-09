<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Invitation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notifications de rôles et d'accès.
 *
 * Quand un admin change le rôle, le statut ou l'appartenance d'une personne,
 * celle-ci doit l'apprendre par une notification — elle n'est pas en train de
 * regarder la liste des membres au moment du changement.
 *
 * Deux règles verrouillées :
 *  - chaque changement d'accès produit UNE notification du bon type, portée à
 *    la bonne personne et à la bonne agence ;
 *  - on ne s'écrit jamais à soi-même, et ne rien changer ne notifie rien.
 */
class MemberAccessNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Agency $agency;

    private User $member;

    private AgencyMember $membership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->agency = Agency::create([
            'name' => 'Agence Accès',
            'owner_id' => $this->owner->id,
        ]);
        // Le propriétaire porte le rôle « admin » : c'est ce que fait la
        // création d'agence, et c'est ce qui autorise manageMembers.
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $this->owner->id,
            'role' => 'admin',
            'status' => 'actif',
        ]);

        $this->member = User::factory()->create();
        $this->membership = AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $this->member->id,
            'role' => 'membre',
            'status' => 'actif',
        ]);
    }

    // ---------- Rôle ----------

    public function test_promoting_a_member_to_admin_notifies_them(): void
    {
        $this->actingAs($this->owner)
            ->putJson("/api/agencies/{$this->agency->id}/members/{$this->membership->id}", [
                'role' => 'admin',
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->member->id,
            'agency_id' => $this->agency->id,
            'type' => 'nomme_admin',
            'title' => 'Nommé administrateur',
            'link' => "/agences/{$this->agency->id}",
        ]);
        // L'auteur du changement n'est pas prévenu de ce qu'il vient de faire.
        $this->assertDatabaseMissing('notifications', ['user_id' => $this->owner->id]);
    }

    public function test_demoting_an_admin_is_announced_as_a_role_change(): void
    {
        $this->membership->update(['role' => 'admin']);

        $this->actingAs($this->owner)
            ->putJson("/api/agencies/{$this->agency->id}/members/{$this->membership->id}", [
                'role' => 'membre',
            ])
            ->assertOk();

        $notification = Notification::where('user_id', $this->member->id)
            ->where('type', 'role_modifie')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame($this->agency->id, (int) $notification->agency_id);
        // Le message dit le rôle obtenu, sans jargon de base de données, et
        // nomme l'agence concernée.
        $this->assertStringContainsString('« membre »', $notification->message);
        $this->assertStringContainsString($this->agency->name, $notification->message);
    }

    public function test_leaving_the_role_untouched_sends_nothing(): void
    {
        $this->actingAs($this->owner)
            ->putJson("/api/agencies/{$this->agency->id}/members/{$this->membership->id}", [
                'role' => 'membre',
                'status' => 'actif',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('notifications', ['user_id' => $this->member->id]);
    }

    // ---------- Statut ----------

    public function test_activating_a_member_notifies_them(): void
    {
        $this->membership->update(['status' => 'inactif']);

        $this->actingAs($this->owner)
            ->putJson("/api/agencies/{$this->agency->id}/members/{$this->membership->id}", [
                'status' => 'actif',
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->member->id,
            'agency_id' => $this->agency->id,
            'type' => 'compte_active',
            'link' => "/agences/{$this->agency->id}",
        ]);
    }

    public function test_deactivating_a_member_notifies_them(): void
    {
        $this->actingAs($this->owner)
            ->putJson("/api/agencies/{$this->agency->id}/members/{$this->membership->id}", [
                'status' => 'inactif',
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->member->id,
            'agency_id' => $this->agency->id,
            'type' => 'compte_desactive',
        ]);
    }

    // ---------- Retrait ----------

    public function test_removing_a_member_notifies_them_without_a_link_to_the_agency(): void
    {
        $this->actingAs($this->owner)
            ->deleteJson("/api/agencies/{$this->agency->id}/members/{$this->membership->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('agency_members', ['id' => $this->membership->id]);
        // Sans lien : l'agence est désormais hors de sa portée.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->member->id,
            'agency_id' => $this->agency->id,
            'type' => 'membre_retire',
            'link' => null,
        ]);
    }

    // ---------- Invitation en tant qu'admin ----------

    public function test_accepting_an_admin_invitation_notifies_the_nomination(): void
    {
        $invitee = User::factory()->create();
        $invitation = $this->invite($invitee, 'admin');

        $this->actingAs($invitee)
            ->postJson("/api/invitations/{$invitation->token}/accept")
            ->assertOk();

        $this->assertDatabaseHas('agency_members', [
            'agency_id' => $this->agency->id,
            'user_id' => $invitee->id,
            'role' => 'admin',
            'status' => 'actif',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $invitee->id,
            'agency_id' => $this->agency->id,
            'type' => 'nomme_admin',
            'link' => "/agences/{$this->agency->id}",
        ]);
    }

    public function test_accepting_a_membre_invitation_creates_no_access_notification(): void
    {
        $invitee = User::factory()->create();
        $invitation = $this->invite($invitee, 'membre');

        $this->actingAs($invitee)
            ->postJson("/api/invitations/{$invitation->token}/accept")
            ->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $invitee->id,
            'type' => 'nomme_admin',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $invitee->id,
            'type' => 'role_modifie',
        ]);
    }

    // ---------- Soi-même ----------

    public function test_a_member_is_never_notified_of_their_own_changes(): void
    {
        $admin = User::factory()->create();
        $own = AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $admin->id,
            'role' => 'admin',
            'status' => 'actif',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/agencies/{$this->agency->id}/members/{$own->id}", [
                'status' => 'inactif',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('notifications', ['user_id' => $admin->id]);
    }

    /** Invitation en attente, expédiée par le propriétaire de l'agence. */
    private function invite(User $invitee, string $role): Invitation
    {
        return Invitation::create([
            'agency_id' => $this->agency->id,
            'email' => $invitee->email,
            'role' => $role,
            'token' => 'token-'.uniqid(),
            'status' => 'en_attente',
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(7),
        ]);
    }
}

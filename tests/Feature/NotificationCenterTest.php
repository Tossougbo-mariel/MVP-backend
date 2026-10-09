<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Invitation;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Centre de notifications : suppression et invitation acceptee.
 *
 * Trois comportements a verrouiller :
 *  - l'utilisateur ne supprime que ce qui lui appartient, en une fois ou en
 *    lot (selection a cocher cote client) ;
 *  - une invitation acceptee devient "neutre" : son lien d'adhesion disparait,
 *    donc la notification ne renvoie plus sur l'invitation et ne peut plus
 *    etre actionnee, seulement supprimee.
 */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->agency = Agency::create([
            'name' => 'Agence Notifications',
            'owner_id' => $this->owner->id,
        ]);
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $this->owner->id,
            'role' => 'proprietaire',
            'status' => 'actif',
        ]);
    }

    private function makeNotification(User $user, array $attrs = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $user->id,
            'agency_id' => $this->agency->id,
            'type' => 'tache_assignee',
            'title' => 'Tache assignee',
            'message' => 'On vous a confie Maquettes.',
            'link' => "/agences/{$this->agency->id}/projets/1/taches/1",
        ], $attrs));
    }

    // ---------- Suppression ----------

    public function test_a_user_can_delete_his_own_notification(): void
    {
        $notification = $this->makeNotification($this->owner);

        $this->actingAs($this->owner)
            ->deleteJson("/api/notifications/{$notification->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_a_user_cannot_delete_someone_elses_notification(): void
    {
        $stranger = User::factory()->create();
        $notification = $this->makeNotification($stranger);

        $this->actingAs($this->owner)
            ->deleteJson("/api/notifications/{$notification->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    public function test_a_bulk_deletion_only_removes_the_selected_own_notifications(): void
    {
        $mine1 = $this->makeNotification($this->owner);
        $mine2 = $this->makeNotification($this->owner);
        $stranger = User::factory()->create();
        $foreign = $this->makeNotification($stranger);

        $response = $this->actingAs($this->owner)
            ->deleteJson('/api/notifications', [
                'ids' => [$mine1->id, $mine2->id, $foreign->id],
            ])
            ->assertOk();

        $this->assertSame(2, $response->json('deleted'));
        $this->assertDatabaseMissing('notifications', ['id' => $mine1->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $mine2->id]);
        $this->assertDatabaseHas('notifications', ['id' => $foreign->id]);
    }

    public function test_an_empty_selection_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->deleteJson('/api/notifications', ['ids' => []])
            ->assertStatus(422);
    }

    public function test_a_guest_cannot_delete_anything(): void
    {
        $notification = $this->makeNotification($this->owner);

        $this->deleteJson("/api/notifications/{$notification->id}")->assertUnauthorized();
        $this->deleteJson('/api/notifications', ['ids' => [$notification->id]])->assertUnauthorized();

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    // ---------- Invitation acceptee ----------

    public function test_accepting_an_invitation_neutralizes_its_notification(): void
    {
        $token = 'token-invitation-'.uniqid();
        $link = config('app.frontend_url').'/accepter-invitation?token='.$token;

        Invitation::create([
            'agency_id' => $this->agency->id,
            'email' => $this->owner->email,
            'role' => 'membre',
            'token' => $token,
            'status' => 'en_attente',
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $notification = $this->makeNotification($this->owner, [
            'type' => 'invitation',
            'title' => 'Invitation a rejoindre Agence Notifications',
            'message' => 'On vous invite a rejoindre l\'agence.',
            'link' => $link,
            'agency_id' => null,
            'read_at' => null,
        ]);

        // Invitation relancée entre-temps : l'ancienne notification porte un
        // jeton different, elle doit pourtant suivre le meme sort.
        $stale = $this->makeNotification($this->owner, [
            'type' => 'invitation',
            'title' => 'Invitation a rejoindre Agence Notifications',
            'message' => 'On vous invite a rejoindre l\'agence.',
            'link' => config('app.frontend_url').'/accepter-invitation?token=ancien-jeton',
            'read_at' => null,
        ]);

        $this->actingAs($this->owner)
            ->postJson("/api/invitations/{$token}/accept")
            ->assertOk();

        $notification->refresh();
        $stale->refresh();

        // Plus de lien : ni "Accepter l'invitation" ni clic sur la carte.
        $this->assertNull($notification->link);
        $this->assertNotNull($notification->read_at);
        $this->assertNull($stale->link);
        $this->assertNotNull($stale->read_at);
        $this->assertDatabaseHas('agency_members', [
            'agency_id' => $this->agency->id,
            'user_id' => $this->owner->id,
            'status' => 'actif',
        ]);
    }
}

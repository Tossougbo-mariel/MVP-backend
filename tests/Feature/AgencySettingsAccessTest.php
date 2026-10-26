<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les paramètres d'une agence (nom, description, réglages) sont réservés au
 * propriétaire, comme la page front qui les expose.
 *
 * Les admins non-propriétaires conservent la gestion des membres et des
 * invitations depuis la page Équipe : `manageMembers` n'est pas modifié.
 */
class AgencySettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private User $member;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->member = User::factory()->create();

        $this->agency = Agency::create([
            'name' => 'Agence Origine',
            'description' => 'Description initiale',
            'owner_id' => $this->owner->id,
        ]);

        // Le propriétaire porte le rôle « admin », comme à la création.
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $this->owner->id,
            'role' => 'admin',
            'status' => 'actif',
        ]);

        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $this->admin->id,
            'role' => 'admin',
            'status' => 'actif',
        ]);

        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $this->member->id,
            'role' => 'membre',
            'status' => 'actif',
        ]);
    }

    public function test_owner_can_update_agency_info_and_settings(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/agencies/{$this->agency->id}", [
                'name' => 'Agence Renommée',
                'settings' => ['whoCanInvite' => 'all'],
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Agence Renommée')
            ->assertJsonPath('settings.whoCanInvite', 'all');

        $this->agency->refresh();

        $this->assertSame('Agence Renommée', $this->agency->name);
        $this->assertSame('all', $this->agency->settings['whoCanInvite']);
    }

    public function test_admin_without_ownership_cannot_update_agency(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/agencies/{$this->agency->id}", [
                'name' => 'Agence Piratée',
                'settings' => ['whoCanInvite' => 'all'],
            ])
            ->assertForbidden();

        $this->agency->refresh();

        $this->assertSame('Agence Origine', $this->agency->name);
        $this->assertNull($this->agency->settings);
    }

    public function test_simple_member_cannot_update_agency(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->putJson("/api/agencies/{$this->agency->id}", [
                'name' => 'Agence Piratée',
            ])
            ->assertForbidden();

        $this->assertSame('Agence Origine', $this->agency->refresh()->name);
    }

    public function test_guest_cannot_update_agency(): void
    {
        $this->putJson("/api/agencies/{$this->agency->id}", [
            'name' => 'Agence Piratée',
        ])->assertUnauthorized();

        $this->assertSame('Agence Origine', $this->agency->refresh()->name);
    }
}

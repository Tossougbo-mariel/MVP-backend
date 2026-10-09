<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectDueDateUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $agency = Agency::create([
            'name' => 'Agence',
            'owner_id' => $this->user->id,
        ]);
        AgencyMember::create([
            'agency_id' => $agency->id,
            'user_id' => $this->user->id,
            'role' => 'admin',
            'status' => 'actif',
        ]);
        $this->project = Project::create([
            'agency_id' => $agency->id,
            'name' => 'Projet',
            'owner_id' => $this->user->id,
            'status' => 'en_cours',
            'start_date' => '2026-10-01',
            'due_date' => '2026-11-30',
        ]);
    }

    public function test_due_date_cannot_be_cleared(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}", ['due_date' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_date');
    }

    public function test_due_date_cannot_be_earlier(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}", ['due_date' => '2026-10-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_date');
    }

    public function test_due_date_can_be_kept_or_postponed(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}", ['due_date' => '2026-11-30'])
            ->assertOk();

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}", ['due_date' => '2026-12-31'])
            ->assertOk()
            ->assertJsonPath('due_date', '2026-12-31');
    }

    public function test_status_only_update_is_unaffected(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}", ['status' => 'archive'])
            ->assertOk()
            ->assertJsonPath('status', 'archive');
    }
}

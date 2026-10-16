<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/agencies/{agency}/dashboard` remplace le bootstrap complet pour
 * la page de tableau de bord : une seule agence, résumé léger.
 *
 * Contrat :
 *  - réservé aux membres actifs (401 sans jeton, 403 sinon) ;
 *  - visibilité des projets identique à ProjectController@index ;
 *  - `deadline_status` présent (l'accessor en dépend) mais les relations
 *    lourdes (créateur, assigné, étiquettes, dépendants) restent réservées
 *    au bootstrap qui alimente le Kanban et le planning.
 */
class AgencyDashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Agency $agency;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->agency = Agency::create([
            'name' => 'Agence Tableau de bord',
            'owner_id' => $this->user->id,
        ]);
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $this->user->id,
            'role' => 'proprietaire',
            'status' => 'actif',
        ]);

        $this->project = Project::create([
            'agency_id' => $this->agency->id,
            'name' => 'Refonte site',
            'owner_id' => $this->user->id,
            'status' => 'en_cours',
            'start_date' => '2026-10-01',
            'due_date' => '2026-11-30',
        ]);

        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]);
    }

    private function task(array $overrides = []): Task
    {
        return Task::create(array_merge([
            'project_id' => $this->project->id,
            'title' => 'Tache',
            'status' => 'a_faire',
            'priority' => 'moyenne',
            'start_date' => '2026-10-05',
            'due_date' => '2026-10-09',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    public function test_it_returns_a_single_agency_with_projects_and_tasks(): void
    {
        $this->task(['title' => 'En retard', 'due_date' => '2020-01-01']);

        $payload = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/agencies/{$this->agency->id}/dashboard")
            ->assertOk()
            ->json();

        $this->assertCount(1, $payload['agencies']);
        $this->assertSame('Agence Tableau de bord', $payload['agencies'][0]['name']);
        $this->assertSame('Refonte site', $payload['agencies'][0]['projects'][0]['name']);
        $this->assertSame('En retard', $payload['agencies'][0]['projects'][0]['tasks'][0]['title']);
        $this->assertSame('en_retard', $payload['agencies'][0]['projects'][0]['tasks'][0]['deadline_status']);
    }

    public function test_it_excludes_heavy_relations_reserved_for_the_bootstrap(): void
    {
        $this->task();

        $task = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/agencies/{$this->agency->id}/dashboard")
            ->assertOk()
            ->json('agencies.0.projects.0.tasks.0');

        // Garanties par le Kanban / la page détail, pas par le tableau de bord.
        foreach (['creator', 'assignee', 'tags', 'dependents'] as $heavy) {
            $this->assertArrayNotHasKey($heavy, $task, "La relation « {$heavy} » allonge inutilement le résumé.");
        }

        // Indispensable à Task::isBlockedByDependencies (calcul de deadline_status).
        $this->assertArrayHasKey('dependencies', $task);
    }

    public function test_a_plain_member_only_sees_his_own_projects(): void
    {
        $member = User::factory()->create();
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $member->id,
            'role' => 'membre',
            'status' => 'actif',
        ]);

        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $member->id,
        ]);

        Project::create([
            'agency_id' => $this->agency->id,
            'name' => 'Autre projet',
            'owner_id' => $this->user->id,
            'status' => 'en_cours',
        ]);

        $projects = collect(
            $this->actingAs($member, 'sanctum')
                ->getJson("/api/agencies/{$this->agency->id}/dashboard")
                ->assertOk()
                ->json('agencies.0.projects')
        )->pluck('name');

        $this->assertSame(['Refonte site'], $projects->values()->all());
    }

    public function test_an_admin_sees_every_agency_project(): void
    {
        $admin = User::factory()->create();
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $admin->id,
            'role' => 'admin',
            'status' => 'actif',
        ]);

        Project::create([
            'agency_id' => $this->agency->id,
            'name' => 'Autre projet',
            'owner_id' => $this->user->id,
            'status' => 'en_cours',
        ]);

        $projects = collect(
            $this->actingAs($admin, 'sanctum')
                ->getJson("/api/agencies/{$this->agency->id}/dashboard")
                ->assertOk()
                ->json('agencies.0.projects')
        )->pluck('name');

        $this->assertCount(2, $projects);
    }

    public function test_it_does_not_leak_other_agencies(): void
    {
        $other = Agency::create(['name' => 'Autre agence', 'owner_id' => $this->user->id]);
        AgencyMember::create([
            'agency_id' => $other->id,
            'user_id' => $this->user->id,
            'role' => 'proprietaire',
            'status' => 'actif',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/agencies/{$this->agency->id}/dashboard")
            ->assertOk()
            ->assertJsonCount(1, 'agencies')
            ->assertJsonPath('agencies.0.id', $this->agency->id);
    }

    public function test_an_active_member_only_restriction_applies(): void
    {
        $pending = User::factory()->create();
        AgencyMember::create([
            'agency_id' => $this->agency->id,
            'user_id' => $pending->id,
            'role' => 'membre',
            'status' => 'en_attente',
        ]);

        $this->actingAs($pending, 'sanctum')
            ->getJson("/api/agencies/{$this->agency->id}/dashboard")
            ->assertForbidden();
    }

    public function test_a_guest_cannot_read_the_summary(): void
    {
        $this->getJson("/api/agencies/{$this->agency->id}/dashboard")->assertUnauthorized();
    }

    public function test_a_non_member_cannot_read_the_summary(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/agencies/{$this->agency->id}/dashboard")
            ->assertForbidden();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les rapports PDF suivent la locale demandee par le front (`?locale=en`),
 * avec repli sur le francais et un nom de fichier coherent.
 */
class ReportLocaleTest extends TestCase
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
            'name' => 'Agence Rapports',
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

    public function test_project_report_is_english_when_requested(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->get("/api/projects/{$this->project->id}/report?locale=en");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('project-report', $response->headers->get('content-disposition'));
    }

    public function test_project_report_defaults_to_french(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->get("/api/projects/{$this->project->id}/report");

        $response->assertOk();
        $this->assertStringContainsString('rapport-projet', $response->headers->get('content-disposition'));

        $invalid = $this->actingAs($this->user, 'sanctum')
            ->get("/api/projects/{$this->project->id}/report?locale=de");

        $invalid->assertOk();
        $this->assertStringContainsString('rapport-projet', $invalid->headers->get('content-disposition'));
    }

    public function test_agency_report_is_english_when_requested(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->get("/api/agencies/{$this->agency->id}/report?locale=en");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('agency-report', $response->headers->get('content-disposition'));
    }

    public function test_agency_report_defaults_to_french(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->get("/api/agencies/{$this->agency->id}/report");

        $response->assertOk();
        $this->assertStringContainsString('rapport-agence', $response->headers->get('content-disposition'));
    }
}

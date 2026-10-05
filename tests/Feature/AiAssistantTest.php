<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.enabled' => true,
            'ai.api_key' => 'test-key',
            'ai.base_url' => 'https://example.test/v1',
            'ai.model' => 'test-model',
        ]);
    }

    private function fakeCompletion(string $content): void
    {
        Http::fake([
            'example.test/*' => Http::response([
                'model' => 'test-model',
                'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
                'usage' => ['total_tokens' => 42],
            ]),
        ]);
    }

    public function test_guest_is_rejected(): void
    {
        $this->postJson('/api/ai/chat', ['message' => 'bonjour'])->assertUnauthorized();
    }

    public function test_status_reports_configuration(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ai/status')
            ->assertOk()
            ->assertJson(['configured' => true, 'read_only' => true, 'model' => 'test-model']);
    }

    public function test_status_reports_missing_key(): void
    {
        config(['ai.api_key' => '']);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ai/status')
            ->assertOk()
            ->assertJson(['configured' => false]);
    }

    public function test_chat_returns_the_model_reply(): void
    {
        $this->fakeCompletion('Commencez par la tache #12.');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/ai/chat', ['message' => 'Par quoi je commence ?'])
            ->assertOk();

        $this->assertSame('Commencez par la tache #12.', $response->json('reply'));

        Http::assertSent(function ($request) {
            $body = $request->data();

            $system = $body['messages'][0];
            $this->assertSame('system', $system['role']);
            $this->assertStringContainsString('LECTURE SEULE', $system['content']);
            $this->assertSame('Par quoi je commence ?', end($body['messages'])['content']);

            return true;
        });
    }

    public function test_chat_keeps_previous_turns(): void
    {
        $this->fakeCompletion('ok');

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ai/chat', [
                'message' => 'et ensuite ?',
                'history' => [
                    ['role' => 'user', 'content' => 'premiere question'],
                    ['role' => 'assistant', 'content' => 'premiere reponse'],
                ],
            ])
            ->assertOk();

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'];

            $this->assertCount(4, $messages);
            $this->assertSame('premiere question', $messages[1]['content']);
            $this->assertSame('premiere reponse', $messages[2]['content']);

            return true;
        });
    }

    public function test_missing_key_returns_503_instead_of_faking_a_reply(): void
    {
        config(['ai.api_key' => '']);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ai/chat', ['message' => 'bonjour'])
            ->assertStatus(503)
            ->assertJsonPath('configured', false);
    }

    public function test_provider_error_is_surfaced(): void
    {
        Http::fake([
            'example.test/*' => Http::response(['error' => ['message' => 'quota exceeded']], 429),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ai/chat', ['message' => 'bonjour'])
            ->assertStatus(503);
    }

    public function test_message_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ai/chat', ['message' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_cannot_scope_to_an_agency_the_user_does_not_belong_to(): void
    {
        $this->fakeCompletion('ok');

        $user = User::factory()->create();
        $otherAgency = Agency::create([
            'name' => 'Agence interdite',
            'owner_id' => User::factory()->create()->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ai/chat', [
                'message' => 'bonjour',
                'agency_id' => $otherAgency->id,
            ])
            ->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_context_only_contains_agencies_the_user_is_active_in(): void
    {
        $this->fakeCompletion('ok');

        $user = User::factory()->create();

        $mine = Agency::create(['name' => 'Mon agence', 'owner_id' => $user->id]);
        AgencyMember::create([
            'agency_id' => $mine->id,
            'user_id' => $user->id,
            'role' => 'proprietaire',
            'status' => 'actif',
        ]);

        $stranger = User::factory()->create();
        $other = Agency::create(['name' => 'Agence inconnue', 'owner_id' => $stranger->id]);
        AgencyMember::create([
            'agency_id' => $other->id,
            'user_id' => $stranger->id,
            'role' => 'proprietaire',
            'status' => 'actif',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ai/chat', ['message' => 'bonjour'])
            ->assertOk();

        Http::assertSent(function ($request) {
            $system = $request->data()['messages'][0]['content'];

            $this->assertStringContainsString('Mon agence', $system);
            $this->assertStringNotContainsString('Agence inconnue', $system);

            return true;
        });
    }

    public function test_inactive_membership_is_excluded(): void
    {
        $this->fakeCompletion('ok');

        $user = User::factory()->create();
        $agency = Agency::create(['name' => 'Agence resignee', 'owner_id' => $user->id]);

        AgencyMember::create([
            'agency_id' => $agency->id,
            'user_id' => $user->id,
            'role' => 'membre',
            'status' => 'inactif',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ai/chat', ['message' => 'bonjour'])
            ->assertOk();

        Http::assertSent(function ($request) {
            $system = $request->data()['messages'][0]['content'];

            $this->assertStringContainsString("n'appartient a aucune agence", $system);

            return true;
        });
    }
}

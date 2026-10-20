<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'apparence clair/sombre du compte (colonne users.theme) est persistée par
 * PUT /api/me, au même titre que la couleur d'accent (theme_color).
 */
class ProfileThemeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => $this->user->first_name,
            'last_name' => $this->user->last_name,
            'email' => $this->user->email,
            'theme' => 'light',
        ], $overrides);
    }

    public function test_the_dark_light_theme_is_persisted(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/me', $this->payload())
            ->assertOk()
            ->assertJsonPath('theme', 'light');

        $this->actingAs($this->user->fresh(), 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('theme', 'light');
    }

    public function test_an_invalid_theme_is_rejected(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/me', $this->payload(['theme' => 'violet']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['theme']);
    }

    public function test_the_theme_can_be_cleared_back_to_default(): void
    {
        $this->user->forceFill(['theme' => 'dark'])->save();

        $this->actingAs($this->user->fresh(), 'sanctum')
            ->putJson('/api/me', $this->payload(['theme' => null]))
            ->assertOk()
            ->assertJsonPath('theme', null);

        $this->assertNull($this->user->fresh()->theme);
    }
}

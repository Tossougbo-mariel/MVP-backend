<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteGoogleUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND = 'http://localhost:3000';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => self::FRONTEND,
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect' => 'http://127.0.0.1:8000/auth/google/callback',
        ]);
    }

    private function fakeGoogleUser(array $overrides = []): void
    {
        // array_key_exists et non ?? : un email explicitement null doit rester
        // null, sinon le défaut reviendrait et le test ne testerait rien.
        $pick = fn (string $key, mixed $default) => array_key_exists($key, $overrides)
            ? $overrides[$key]
            : $default;

        $googleUser = new SocialiteGoogleUser;
        $googleUser->id = $pick('id', '112233');
        $googleUser->name = $pick('name', 'Claire Dupont');
        $googleUser->email = $pick('email', 'claire@gmail.com');
        $googleUser->avatar = $pick('avatar', 'https://lh3.googleusercontent.com/a/photo');

        $provider = Mockery::mock(\Laravel\Socialite\Two\GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    // ---------- Non configuré ----------

    public function test_redirect_returns_503_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/auth/google/redirect')
            ->assertStatus(503)
            ->assertJson(['message' => 'La connexion Google n\'est pas configurée sur ce serveur.']);
    }

    public function test_callback_returns_503_when_google_is_not_configured(): void
    {
        config(['services.google.client_secret' => '']);

        $this->get('/auth/google/callback')->assertStatus(503);
    }

    // ---------- Création de compte ----------

    public function test_a_new_google_user_gets_an_account_without_password(): void
    {
        $this->fakeGoogleUser();

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect();

        $user = User::where('email', 'claire@gmail.com')->firstOrFail();

        $this->assertNull($user->password, 'Un compte Google ne doit pas avoir de mot de passe.');
        $this->assertSame('Claire Dupont', $user->name);
        $this->assertSame('Claire', $user->first_name);
        $this->assertSame('Dupont', $user->last_name);
        $this->assertSame('actif', $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('https://lh3.googleusercontent.com/a/photo', $user->avatar);
    }

    public function test_the_token_travels_in_the_fragment_not_the_query(): void
    {
        $this->fakeGoogleUser();

        $response = $this->get('/auth/google/callback');
        $target = $response->headers->get('Location');

        $this->assertStringStartsWith(self::FRONTEND.'/connexion#token=', $target);

        // Un token en query string finit dans les logs et l'en-tête Referer.
        $this->assertStringNotContainsString('?token=', $target);

        $token = urldecode(explode('#token=', $target)[1]);
        $this->assertTrue(
            (new User)->tokens()->where('id', explode('|', $token)[0] ?? '0')->exists()
                || User::where('email', 'claire@gmail.com')->firstOrFail()->tokens()->count() === 1
        );
    }

    public function test_google_is_not_configured_by_default_in_the_env_example(): void
    {
        $this->assertSame(
            'http://127.0.0.1:8000/auth/google/callback',
            config('services.google.redirect'),
        );
    }

    // ---------- Refus de rattachement automatique ----------

    public function test_an_existing_account_is_never_hijacked_through_google(): void
    {
        $existing = User::factory()->create(['email' => 'claire@gmail.com']);

        $this->fakeGoogleUser();

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(self::FRONTEND.'/connexion?erreur=email_deja_utilise');

        // Aucun nouveau compte, et surtout aucun token délivré.
        $this->assertSame(1, User::where('email', 'claire@gmail.com')->count());
        $this->assertSame(0, $existing->tokens()->count());
    }

    public function test_the_match_is_case_insensitive(): void
    {
        $existing = User::factory()->create(['email' => 'claire@gmail.com']);

        $this->fakeGoogleUser(['email' => 'Claire@Gmail.com']);

        $this->get('/auth/google/callback')
            ->assertRedirect(self::FRONTEND.'/connexion?erreur=email_deja_utilise');

        $this->assertSame(1, User::where('email', 'claire@gmail.com')->count());
        $this->assertSame(0, $existing->tokens()->count());
    }

    public function test_a_google_account_without_email_is_refused(): void
    {
        $this->fakeGoogleUser(['email' => null]);

        $this->get('/auth/google/callback')
            ->assertRedirect(self::FRONTEND.'/connexion?erreur=google_email_absent');

        $this->assertSame(0, User::count());
    }

    public function test_a_failing_google_call_returns_to_the_login_page(): void
    {
        $provider = Mockery::mock(\Laravel\Socialite\Two\GoogleProvider::class);
        $provider->shouldReceive('user')->andThrow(new \RuntimeException('-state mismatch'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback')
            ->assertRedirect(self::FRONTEND.'/connexion?erreur=google_echec');

        $this->assertSame(0, User::count());
    }

    public function test_a_google_user_can_then_log_in_with_a_received_code(): void
    {
        $this->fakeGoogleUser();
        $this->get('/auth/google/callback');

        $user = User::where('email', 'claire@gmail.com')->firstOrFail();

        // Le compte n'a pas de mot de passe : la connexion par code est le
        // seul moyen d'y revenir.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => ''])
            ->assertStatus(422);
    }
}

<?php

namespace Tests\Feature;

use App\Models\EmailOtpCode;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        RateLimiter::clear('otp-request|127.0.0.1');
        RateLimiter::clear('otp-verify|127.0.0.1');
    }

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'claire@example.com',
            'password' => Hash::make('motdepasse123'),
        ], $attrs));
    }

    private function lastSentCode(User $user): string
    {
        $code = null;

        Notification::assertSentTo(
            $user,
            OtpCodeNotification::class,
            function ($notification) use (&$code) {
                $code = (new \ReflectionProperty($notification, 'code'))->getValue($notification);

                return true;
            }
        );

        $this->assertNotNull($code, 'Aucun code 2FA envoyé.');

        return (string) $code;
    }

    // ---------- Le mot de passe ne suffit plus ----------

    public function test_login_returns_a_ticket_instead_of_a_token_when_two_factor_is_on(): void
    {
        $user = $this->makeUser(['two_factor_enabled' => true]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'motdepasse123',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('two_factor_required'));
        $this->assertNotEmpty($response->json('ticket'));

        // Le point crucial : aucun token de session avant la validation.
        $this->assertNull($response->json('token'));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_wrong_password_still_gets_the_plain_401(): void
    {
        $user = $this->makeUser(['two_factor_enabled' => true]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'mauvais'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Identifiants incorrects.']);

        Notification::assertNothingSent();
    }

    public function test_the_correct_code_opens_the_session(): void
    {
        $user = $this->makeUser(['two_factor_enabled' => true]);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'motdepasse123',
        ]);

        $code = $this->lastSentCode($user);

        $response = $this->postJson('/api/auth/two-factor/verify', [
            'ticket' => $login->json('ticket'),
            'code' => $code,
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame($user->id, $response->json('user.id'));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_the_ticket_is_single_use(): void
    {
        $user = $this->makeUser(['two_factor_enabled' => true]);

        $ticket = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'motdepasse123',
        ])->json('ticket');

        $code = $this->lastSentCode($user);

        $this->postJson('/api/auth/two-factor/verify', ['ticket' => $ticket, 'code' => $code])->assertOk();

        // Le code a été consommé, donc le rejeu échoue — mais sur le code,
        // pas sur le ticket : ce qui compte est qu'aucun second token n'est
        // délivré.
        $this->postJson('/api/auth/two-factor/verify', ['ticket' => $ticket, 'code' => $code])
            ->assertStatus(401);
        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    public function test_an_unknown_ticket_is_refused(): void
    {
        $user = $this->makeUser(['two_factor_enabled' => true]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'motdepasse123']);

        $this->postJson('/api/auth/two-factor/verify', [
            'ticket' => 'ticket-fabrique',
            'code' => '123456',
        ])->assertStatus(401);
    }

    public function test_a_login_code_cannot_be_reused_as_a_two_factor_code(): void
    {
        $user = $this->makeUser(['two_factor_enabled' => true]);

        $this->postJson('/api/auth/otp/request', ['email' => $user->email]);
        $code = $this->lastSentCode($user);

        $ticket = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'motdepasse123',
        ])->json('ticket');

        // Les deux usages sont cloisonnés : le code de connexion ne passe pas
        // comme second facteur.
        $this->postJson('/api/auth/two-factor/verify', ['ticket' => $ticket, 'code' => $code])
            ->assertStatus(401);
    }

    public function test_resending_rotates_the_ticket(): void
    {
        $user = $this->makeUser(['two_factor_enabled' => true]);

        $first = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'motdepasse123',
        ])->json('ticket');

        $resend = $this->postJson('/api/auth/two-factor/resend', ['ticket' => $first])->assertOk();
        $newTicket = $resend->json('ticket');

        $this->assertNotSame($first, $newTicket);

        // L'ancien ticket ne vaut plus rien.
        $this->postJson('/api/auth/two-factor/verify', [
            'ticket' => $first,
            'code' => $this->lastSentCode($user),
        ])->assertStatus(401);
    }

    // ---------- Activation / désactivation ----------

    public function test_two_factor_is_off_by_default(): void
    {
        $user = $this->makeUser();

        // fresh() : la valeur par défaut de la colonne n'est lue qu'après
        // rechargement du modèle.
        $this->assertFalse($user->fresh()->two_factor_enabled);

        $this->actingAs($user)
            ->getJson('/api/auth/two-factor')
            ->assertOk()
            ->assertJson(['enabled' => false]);
    }

    public function test_enabling_requires_the_current_password(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->putJson('/api/auth/two-factor', ['enabled' => true])
            ->assertStatus(422);

        $this->actingAs($user)
            ->putJson('/api/auth/two-factor', ['enabled' => true, 'password' => 'mauvais'])
            ->assertStatus(422);

        $this->assertFalse($user->fresh()->two_factor_enabled);

        $this->actingAs($user)
            ->putJson('/api/auth/two-factor', ['enabled' => true, 'password' => 'motdepasse123'])
            ->assertOk()
            ->assertJson(['enabled' => true]);
    }

    public function test_an_account_without_password_can_enable_it_directly(): void
    {
        // Compte créé via Google : il n'a pas de mot de passe à confirmer.
        $user = $this->makeUser(['password' => null]);

        $this->actingAs($user)
            ->putJson('/api/auth/two-factor', ['enabled' => true])
            ->assertOk()
            ->assertJson(['enabled' => true]);
    }

    public function test_a_password_account_cannot_log_in_without_a_password(): void
    {
        $user = $this->makeUser(['password' => null]);

        // Garde-fou : Hash::check ne doit jamais recevoir null.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'peu-importe'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Identifiants incorrects.']);
    }

    public function test_disabling_kills_the_pending_two_factor_codes(): void
    {
        $user = $this->makeUser(['two_factor_enabled' => true]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'motdepasse123']);
        $code = $this->lastSentCode($user);

        $row = EmailOtpCode::query()
            ->forPurpose(EmailOtpCode::PURPOSE_TWO_FACTOR)
            ->latest('id')
            ->first();
        $this->assertFalse($row->isConsumed());

        $this->actingAs($user)
            ->putJson('/api/auth/two-factor', ['enabled' => false])
            ->assertOk()
            ->assertJson(['enabled' => false]);

        $this->assertTrue($row->fresh()->isConsumed());
    }

    public function test_a_guest_cannot_read_or_change_the_state(): void
    {
        $this->getJson('/api/auth/two-factor')->assertStatus(401);
        $this->putJson('/api/auth/two-factor', ['enabled' => true])->assertStatus(401);
    }

    public function test_two_factor_login_code_does_not_bypass_the_state_endpoint(): void
    {
        // Un utilisateur connectAc via OTP n'a pas A� repasser par la 2FA.
        $user = $this->makeUser(['two_factor_enabled' => true]);

        $this->postJson('/api/auth/otp/request', ['email' => $user->email]);
        $code = $this->lastSentCode($user);

        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $code])
            ->assertOk();
    }

    public function test_the_profile_learns_whether_a_password_exists(): void
    {
        // L'interface doit savoir si elle doit demander un mot de passe avant
        // d'activer la 2FA : un compte Google n'en a pas.
        $withPassword = $this->makeUser(['email' => 'avec@example.com']);

        $this->actingAs($withPassword)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('has_password', true);

        $this->assertSame('avec@example.com', $withPassword->email);

        $googleUser = $this->makeUser([
            'email' => 'google@example.com',
            'password' => null,
        ]);

        $this->actingAs($googleUser)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('has_password', false);
    }
}

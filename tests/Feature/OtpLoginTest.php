<?php

namespace Tests\Feature;

use App\Models\EmailOtpCode;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class OtpLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // La limitation est par IP : sans reset, un même test s'auto-bloquerait.
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

    /**
     * Dernier code réellement expédié.
     *
     * La notification ne stocke le code que dans une propriété protégée (elle
     * ne doit jamais fuiter via une file d'attente) : on la lit donc par
     * réflexion, comme le ferait un attaquant en cas de fuite.
     */
    private function lastSentCode(?User $user = null): string
    {
        $user ??= User::query()->firstOrFail();
        $code = null;

        Notification::assertSentTo(
            $user,
            OtpCodeNotification::class,
            function ($notification) use (&$code) {
                $code = (new \ReflectionProperty($notification, 'code'))->getValue($notification);

                return true;
            }
        );

        $this->assertNotNull($code, 'Aucune notification de code n\'a été envoyée.');

        return (string) $code;
    }

    public function test_asking_for_a_code_always_answers_the_same_way(): void
    {
        $this->makeUser();

        $response = $this->postJson('/api/auth/otp/request', ['email' => 'claire@example.com']);

        $response->assertStatus(202);
        $this->assertStringContainsString('un code vient d', $response->json('message'));
    }

    public function test_an_unknown_email_gets_the_very_same_answer(): void
    {
        $known = $this->postJson('/api/auth/otp/request', ['email' => 'claire@example.com']);
        RateLimiter::clear('otp-request|127.0.0.1');

        $unknown = $this->postJson('/api/auth/otp/request', ['email' => 'inconnu@example.com']);

        // Aucun message ne permet de distinguer un compte existant d'un email
        // inconnu, sinon la page de connexion deviendrait un annuaire.
        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame(202, $unknown->status());
    }

    public function test_a_code_is_never_stored_in_clear_text(): void
    {
        $user = $this->makeUser();

        app(OtpService::class)->issue($user, EmailOtpCode::PURPOSE_LOGIN);

        $row = EmailOtpCode::query()->latest('id')->first();

        $this->assertSame(6, strlen($this->lastSentCode()));
        $this->assertNotSame($this->lastSentCode(), $row->code_hash);
        $this->assertSame(64, strlen($row->code_hash));
    }

    public function test_the_code_logs_the_user_in(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/otp/request', ['email' => $user->email]);
        $code = $this->lastSentCode();

        $response = $this->postJson('/api/auth/otp/verify', [
            'email' => $user->email,
            'code' => $code,
        ]);

        $response->assertOk();
        $this->assertSame($user->id, $response->json('user.id'));
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_the_email_is_matched_regardless_of_case(): void
    {
        $user = $this->makeUser(['email' => 'Claire@Example.com']);

        $this->postJson('/api/auth/otp/request', ['email' => 'claire@example.com']);
        $code = $this->lastSentCode();

        $this->postJson('/api/auth/otp/verify', [
            'email' => 'CLAIRE@example.com',
            'code' => $code,
        ])->assertOk();
    }

    public function test_a_code_can_only_be_used_once(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/otp/request', ['email' => $user->email]);
        $code = $this->lastSentCode();

        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $code])->assertOk();

        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $code])
            ->assertStatus(401)
            ->assertJson(['message' => 'Code invalide ou expiré.']);
    }

    public function test_a_wrong_code_is_rejected_with_the_same_message_as_an_expired_one(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/otp/request', ['email' => $user->email]);
        $this->lastSentCode();

        $wrong = $this->postJson('/api/auth/otp/verify', [
            'email' => $user->email,
            'code' => '000000',
        ]);

        $expiredRow = EmailOtpCode::query()->latest('id')->first();
        $expiredRow->forceFill(['expires_at' => now()->subMinute()])->save();

        $expired = $this->postJson('/api/auth/otp/verify', [
            'email' => $user->email,
            'code' => '111111',
        ]);

        $this->assertSame($wrong->json('message'), $expired->json('message'));
    }

    public function test_the_code_is_invalidated_after_five_attempts(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/otp/request', ['email' => $user->email]);
        $code = $this->lastSentCode();

        for ($i = 0; $i < EmailOtpCode::MAX_ATTEMPTS - 1; $i++) {
            $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => '999999']);
        }

        // Il reste un essai : le bon code fonctionne encore.
        $this->assertSame(
            EmailOtpCode::MAX_ATTEMPTS - 1,
            EmailOtpCode::query()->latest('id')->first()->attempts
        );

        // Le cinquième échec épuise le code, qui est alors consommé.
        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => '999999']);
        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $code])
            ->assertStatus(401);

        $row = EmailOtpCode::query()->latest('id')->first();
        $this->assertTrue($row->isConsumed());
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/otp/request', ['email' => $user->email]);
        $code = $this->lastSentCode();

        EmailOtpCode::query()->latest('id')->first()
            ->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $code])
            ->assertStatus(401);
    }

    public function test_requesting_again_invalidates_the_previous_code(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/otp/request', ['email' => $user->email]);
        $first = $this->lastSentCode();

        // On force le cooldown pour pouvoir redemander.
        EmailOtpCode::query()->update(['last_sent_at' => now()->subMinutes(5)]);

        $this->postJson('/api/auth/otp/request', ['email' => $user->email]);
        $second = $this->lastSentCode();

        $this->assertNotSame($first, $second);

        // Seul le code le plus récent fonctionne.
        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $first])
            ->assertStatus(401);
        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $second])
            ->assertOk();
    }

    public function test_resending_too_quickly_is_silent(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/otp/request', ['email' => $user->email])->assertStatus(202);
        $first = $this->lastSentCode();

        $this->postJson('/api/auth/otp/request', ['email' => $user->email])->assertStatus(202);

        // Aucun second e-mail : c'est le cooldown silencieux.
        $this->assertCount(1, EmailOtpCode::query()->get());

        // Le code déjà reçu reste valable.
        $this->postJson('/api/auth/otp/verify', ['email' => $user->email, 'code' => $first])
            ->assertOk();
    }

    public function test_the_request_endpoint_is_throttled_by_ip(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < (int) config('auth.otp.request_per_minute'); $i++) {
            $this->postJson('/api/auth/otp/request', ['email' => $user->email])->assertStatus(202);
        }

        $this->postJson('/api/auth/otp/request', ['email' => $user->email])
            ->assertStatus(429);
    }

    public function test_the_email_field_is_required(): void
    {
        $this->postJson('/api/auth/otp/request', [])->assertStatus(422);
        $this->postJson('/api/auth/otp/verify', ['email' => 'pas-un-email', 'code' => '123456'])
            ->assertStatus(422);
    }
}

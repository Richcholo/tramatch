<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * EMAIL VERIFICATION IS A SIX-DIGIT CODE, not a signed link.
 *
 * Registration issues the code (hashed at rest, plain only inside the mail),
 * the traveller types it, one success burns it, and every authed page is
 * gated on a verified address behind it. The signed-link flow these tests
 * replaced never sent anything a traveller could act on without a browser
 * session — a code typed from a phone's mail app is the flow a traveller
 * actually completes.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
        $response->assertSee('six-digit code', false);
    }

    public function test_registration_issues_a_six_digit_code_by_mail(): void
    {
        Notification::fake();

        $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        Notification::assertSentTo(
            User::where('email', 'test@example.com')->first(),
            VerifyEmailCode::class,
            fn (VerifyEmailCode $mail) => preg_match('/^\d{6}$/', $mail->code) === 1
        );
    }

    public function test_the_code_is_stored_hashed_and_never_in_plain_text(): void
    {
        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();

        $this->assertNotNull($user->fresh()->verification_code_hash);
        $this->assertNotSame('123456', $user->fresh()->verification_code_hash);
        // A database leak must not hand out working codes.
        $this->assertSame(60, strlen($user->fresh()->verification_code_hash));
        $this->assertNotNull($user->fresh()->verification_code_expires_at);
    }

    public function test_the_right_code_verifies_and_burns_itself(): void
    {
        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();

        // Recover the plain digits the same way the mail got them: the hash
        // is bcrypt, so the code is re-issued rather than decoded.
        $code = (string) random_int(100000, 999999);
        $user->forceFill([
            'verification_code_hash' => Hash::make($code),
            'verification_code_expires_at' => now()->addMinutes(15),
        ])->save();

        $response = $this->actingAs($user)->post('/verify-email', ['code' => $code]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        // One success burns the code: the same digits are dead.
        $this->assertFalse($user->fresh()->verifyEmailCode($code));
        $this->assertNull($user->fresh()->verification_code_hash);
    }

    public function test_a_wrong_code_is_refused_and_the_stay_is_unverified(): void
    {
        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();

        $response = $this->actingAs($user)->post('/verify-email', ['code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = User::factory()->unverified()->create();

        $code = (string) random_int(100000, 999999);
        $user->forceFill([
            'verification_code_hash' => Hash::make($code),
            'verification_code_expires_at' => now()->subMinute(),
        ])->save();

        $this->actingAs($user)->post('/verify-email', ['code' => $code]);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_unverified_traveller_is_bounced_to_the_code_page(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($user)->get('/itineraries')
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($user)->get('/discover')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_resending_issues_a_fresh_code_and_kills_the_old_one(): void
    {
        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();
        $oldHash = $user->fresh()->verification_code_hash;

        $this->actingAs($user)
            ->post('/email/verification-notification')
            ->assertSessionHasNoErrors();

        $this->assertNotSame($oldHash, $user->fresh()->verification_code_hash);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_already_verified_traveller_bypasses_the_code_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/verify-email')
            ->assertRedirect(route('dashboard', absolute: false));
    }
}

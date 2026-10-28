<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;

#[Fillable(['first_name', 'last_name', 'email', 'password', 'profile_photo_path'])]
#[Hidden(['password', 'remember_token', 'verification_code_hash'])]
class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $appends = ['name'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'verification_code_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * EMAIL VERIFICATION IS A SIX-DIGIT CODE, not a signed link.
     *
     * The code is generated here, stored HASHED (a database leak must not
     * hand out working codes — the same discipline as the share token), and
     * the plain digits exist only inside the mail. Six digits over a
     * fifteen-minute window with a throttled verify route is the brute-force
     * budget.
     */
    public function sendEmailVerificationNotification(): void
    {
        $code = (string) random_int(100000, 999999);

        $this->forceFill([
            'verification_code_hash' => Hash::make($code),
            'verification_code_expires_at' => now()->addMinutes(15),
        ])->save();

        try {
            $this->notify(new \App\Notifications\VerifyEmailCode($code));
        } catch (Throwable $e) {
            // A mail failure must reach the traveller as a sentence, not a
            // 500 — and the log line is the only thing that names the SMTP
            // fault for whoever runs the deployment. The stored code stays
            // valid, so a resend can succeed without a new one.
            \Illuminate\Support\Facades\Log::warning('mail.verification_failed', [
                'user' => $this->id,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'We could not send the code just now. Check the address and try again in a minute.',
                0,
                $e
            );
        }
    }

    /**
     * The traveller's typed code against the stored hash. Expired or never
     * issued is the same answer: no.
     */
    public function verifyEmailCode(string $code): bool
    {
        $hash = $this->verification_code_hash;

        if ($hash === null
            || $this->verification_code_expires_at === null
            || $this->verification_code_expires_at->isPast()
            || ! Hash::check($code, $hash)
        ) {
            return false;
        }

        $this->forceFill([
            'email_verified_at' => now(),
            'verification_code_hash' => null,
            'verification_code_expires_at' => null,
        ])->save();

        return true;
    }

    public function getNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }

    public function travelProfile(): HasOne
    {
        return $this->hasOne(TravelProfile::class);
    }

    public function itineraries(): HasMany
    {
        return $this->hasMany(Itinerary::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function destinationSwipes(): HasMany
    {
        return $this->hasMany(DestinationSwipe::class);
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin'], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }
}
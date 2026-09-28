<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed_with_edit_profile_link(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response
            ->assertOk()
            ->assertSee('/editprofile')
            ->assertDontSee('Save photo')
            ->assertDontSee('Remove photo')
            ->assertSee('Edit preferences')
            ->assertSee('Set your budget')
            ->assertSee('0 of 4 complete')
            ->assertSee('Still to add: Budget, Group size, Trip length, Interests.')
            ->assertSee('aria-valuenow="0"', false);
    }

    public function test_profile_page_displays_saved_travel_preferences_and_interests(): void
    {
        $user = User::factory()->create();
        $travelProfile = $user->travelProfile()->create([
            'budget_level' => 'mid-range',
            'group_size' => 4,
            'trip_duration_days' => 7,
            'preferred_region' => 'Central Luzon',
        ]);
        $tag = Tag::create([
            'name' => 'Nature',
            'slug' => 'nature',
        ]);
        $travelProfile->tags()->attach($tag, ['weight' => 3]);

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response
            ->assertOk()
            ->assertSee('Mid range')
            ->assertSee('4 travelers')
            ->assertSee('7 days')
            ->assertSee('Central Luzon')
            ->assertSee('Nature')
            ->assertSee('4 of 4 complete')
            ->assertSee('100%')
            ->assertSee('aria-valuenow="4"', false)
            ->assertSee('/preferences');
    }

    public function test_profile_progress_marks_missing_interests(): void
    {
        $user = User::factory()->create();
        $user->travelProfile()->create([
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 5,
            'preferred_region' => null,
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response
            ->assertOk()
            ->assertSee('3 of 4 complete')
            ->assertSee('Still to add: Interests.');
    }

    public function test_edit_profile_page_displays_account_settings_sections(): void
    {
        $user = User::factory()->create([
            'profile_photo_path' => 'profile-photos/avatar.jpg',
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/editprofile');

        $response
            ->assertOk()
            ->assertSee('Profile information')
            ->assertSee('Update Password')
            ->assertSee('Delete account')
            ->assertSee('Save this profile photo?')
            ->assertSee('Remove your profile photo?')
            ->assertSee('Save profile changes?')
            ->assertSee('Update your password?')
            ->assertSee('/profile');
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/editprofile', [
                'first_name' => 'Test',
                'last_name' => 'User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/editprofile');

        $user->refresh();

        $this->assertSame('Test', $user->first_name);
        $this->assertSame('User', $user->last_name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/editprofile', [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/editprofile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/editprofile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertSoftDeleted($user);
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/editprofile')
            ->delete('/editprofile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/editprofile');

        $this->assertNotNull($user->fresh());
    }

    public function test_profile_photo_can_be_uploaded_and_replaces_the_previous_photo(): void
    {
        Storage::fake('public');
        $user = User::factory()->create([
            'profile_photo_path' => 'profile-photos/previous.jpg',
        ]);
        Storage::disk('public')->put('profile-photos/previous.jpg', 'previous photo');

        $response = $this
            ->actingAs($user)
            ->post('/editprofile/photo', [
                'photo' => UploadedFile::fake()->create('new-photo.jpg', 100, 'image/jpeg'),
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/editprofile');

        $newPhotoPath = $user->fresh()->profile_photo_path;

        $this->assertNotSame('profile-photos/previous.jpg', $newPhotoPath);
        Storage::disk('public')->assertExists($newPhotoPath);
        Storage::disk('public')->assertMissing('profile-photos/previous.jpg');
    }

    public function test_profile_photo_upload_rejects_non_image_files(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post('/editprofile/photo', [
                'photo' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ]);

        $response->assertSessionHasErrors('photo');
        $this->assertNull($user->fresh()->profile_photo_path);
        Storage::disk('public')->assertDirectoryEmpty('profile-photos');
    }

    public function test_profile_photo_can_be_removed(): void
    {
        Storage::fake('public');
        $user = User::factory()->create([
            'profile_photo_path' => 'profile-photos/avatar.jpg',
        ]);
        Storage::disk('public')->put('profile-photos/avatar.jpg', 'profile photo');

        $response = $this
            ->actingAs($user)
            ->delete('/editprofile/photo');

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/editprofile');

        $this->assertNull($user->fresh()->profile_photo_path);
        Storage::disk('public')->assertMissing('profile-photos/avatar.jpg');
    }
}

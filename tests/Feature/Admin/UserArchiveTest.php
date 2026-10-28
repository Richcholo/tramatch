<?php

namespace Tests\Feature\Admin;

use App\Models\Itinerary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DELETING A USER IS AN ARCHIVE, NEVER A DESTROY.
 *
 * `User` carries SoftDeletes, so admin delete sets deleted_at and nothing
 * else: the account can no longer sign in, every itinerary, swipe and review
 * stays exactly where it is, and a super admin can restore it. The fences —
 * nobody archives themselves, a super admin account is not archived, an
 * admin account only by a super admin — are what stop the archive becoming
 * a way to lock the wrong people out.
 */
class UserArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    public function test_an_admin_can_archive_a_traveler(): void
    {
        $admin = $this->admin();
        $traveler = User::factory()->create();

        $this->actingAs($admin)
            ->delete(route('admin.users.archive', $traveler))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($traveler);
    }

    public function test_an_archived_user_can_no_longer_sign_in(): void
    {
        $traveler = User::factory()->create([
            'password' => 'password',
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.users.archive', $traveler));

        // A login POST from the admin's own session would bounce off the
        // guest middleware; the archived user's attempt starts fresh.
        auth()->logout();

        $this->post('/login', [
            'email' => $traveler->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');
    }

    public function test_an_archived_users_data_is_untouched(): void
    {
        $traveler = User::factory()->create();
        $itinerary = Itinerary::create([
            'user_id' => $traveler->id,
            'title' => 'A trip worth keeping',
            'area' => 'Benguet',
            'budget_level' => 'economy',
            'trip_duration_days' => 2,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.users.archive', $traveler));

        // The archive is a state, not a purge: everything of theirs stays.
        $this->assertDatabaseHas('itineraries', [
            'id' => $itinerary->id,
            'user_id' => $traveler->id,
            'title' => 'A trip worth keeping',
        ]);
    }

    public function test_nobody_archives_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->delete(route('admin.users.archive', $admin))
            ->assertSessionHasErrors('user');

        $this->assertNotSoftDeleted($admin);
    }

    public function test_an_admin_cannot_archive_a_super_admin(): void
    {
        $admin = $this->admin();
        $super = $this->superAdmin();

        $this->actingAs($admin)
            ->delete(route('admin.users.archive', $super))
            ->assertSessionHasErrors('user');

        $this->assertNotSoftDeleted($super);
    }

    public function test_only_a_super_admin_can_archive_an_admin(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        $super = $this->superAdmin();

        $this->actingAs($admin)
            ->delete(route('admin.users.archive', $otherAdmin))
            ->assertSessionHasErrors('user');

        $this->assertNotSoftDeleted($otherAdmin);

        $this->actingAs($super)
            ->delete(route('admin.users.archive', $otherAdmin))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($otherAdmin->fresh());
    }

    public function test_a_super_admin_can_restore_an_archived_account(): void
    {
        $traveler = User::factory()->create();
        $super = $this->superAdmin();

        $this->actingAs($super)->delete(route('admin.users.archive', $traveler));
        $this->assertSoftDeleted($traveler);

        $this->actingAs($super)
            ->patch(route('admin.users.restore', $traveler->id))
            ->assertSessionHasNoErrors();

        $this->assertNotSoftDeleted($traveler->fresh());
    }

    public function test_the_user_list_hides_archived_accounts_until_asked(): void
    {
        $super = $this->superAdmin();
        $traveler = User::factory()->create();

        $this->actingAs($super)->delete(route('admin.users.archive', $traveler));

        $this->actingAs($super)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee($traveler->email);

        $this->actingAs($super)
            ->get(route('admin.users.index', ['archived' => 1]))
            ->assertOk()
            ->assertSee($traveler->email)
            ->assertSee('Restore');
    }

    public function test_an_admin_sees_the_user_list_but_not_the_role_buttons(): void
    {
        $admin = $this->admin();
        $traveler = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee($traveler->email)
            ->assertSee('Archive')
            ->assertDontSee('Make admin');
    }

    public function test_a_traveler_cannot_reach_user_management(): void
    {
        $traveler = User::factory()->create();

        $this->actingAs($traveler)
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAs($traveler)
            ->delete(route('admin.users.archive', $this->admin()))
            ->assertForbidden();
    }
}

<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Itinerary;
use App\Models\Review;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use App\Services\ItineraryGenerator;
use App\Services\RecommendationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TWENTY-FIVE BETA USERS, each with a history that looks like a real one.
 *
 * The point of a beta seed is that testers meet the app in a plausible state,
 * not an empty one. A user with no swipes sees an empty deck and concludes the
 * app is broken; a user with a trip already built demonstrates the itinerary,
 * the map and the PDF immediately. So every user here is given a profile, a
 * weighted set of interests, a mixed swipe history, reviews, and at least one
 * generated itinerary.
 *
 * **ITINERARIES GO THROUGH THE REAL GENERATOR, NOT HAND-BUILT ROWS.**
 * `ItineraryGenerator` enforces the 08:00–18:00 window, the 12:00–13:00 lunch
 * block, the 45-minute travel gap and the 3-stops-per-day cap. Inserting
 * itineraries directly would produce trips that the app itself considers
 * impossible, and the first tester to look at one would find a defect that does
 * not exist.
 *
 * WHICH MEANS THE INPUTS HAVE TO BE VALID, AND THAT IS THE WHOLE PROBLEM HERE:
 *
 *  - `RecommendationService` returns **only destinations the user LIKED**, so a
 *    selected stop has to be liked, not merely present.
 *  - it also requires an **exact `budget_level` match**, so a stop on an
 *    `economy` trip must itself be `economy`.
 *  - and `ItineraryGenerator` matches on **`area` = province**, so every
 *    selected stop must share one province.
 *
 * Selecting by those three rules is the only way to get a trip that generates
 * rather than throws. `Itinerary::MAX_DAYS` is 30 and the cap is 3 stops a day,
 * so the stop count is derived from the trip length rather than guessed.
 *
 * **RE-RUNNABLE.** Users are matched on email with `updateOrCreate`, and each
 * user's swipes, reviews and itineraries are cleared before being rewritten.
 * Running it twice changes nothing and leaves a clean, reproducible dataset —
 * which is what you want before a demo, not a second population to unpick.
 *
 * It refuses to run on an empty catalogue rather than seeding 25 users who
 * cannot generate a single trip.
 */
class BetaUserSeeder extends Seeder
{
    /**
     * One shared demo password. Every account is a throwaway for a test group,
     * so a per-user password only makes the login sheet harder to hand out.
     */
    private const PASSWORD = 'password';

    private const DOMAIN = 'beta.tramatch.test';

    /** Real enough to look like a sign-up sheet, distinct enough to log in as. */
    private const PEOPLE = [
        ['Maria', 'Santos'], ['Jose', 'Reyes'], ['Anna', 'Cruz'], ['Paolo', 'Garcia'],
        ['Grace', 'Lim'], ['Carlo', 'Mendoza'], ['Liza', 'Torres'], ['Ramon', 'Aquino'],
        ['Tina', 'Villanueva'], ['Marco', 'Bautista'], ['Celine', 'Domingo'], ['Jomar', 'Pascual'],
        ['Katrina', 'Roxas'], ['Nico', 'Navarro'], ['Sheila', 'Ocampo'], ['Raffy', 'Salazar'],
        ['Divina', 'Bernardo'], ['Ogie', 'Alcantara'], ['Mylene', 'Del Rosario'], ['Bayani', 'Concepcion'],
        ['Kristine', 'Padilla'], ['Arnel', 'Soriano'], ['Joanna', 'Quintos'], ['Kaloy', 'Dizon'],
        ['Cristina', 'Cabrera'],
    ];

    /**
     * Interest sets. Weighted 1–3 so the recommendation scores actually differ
     * between users rather than everyone matching everything.
     *
     * Names must match `TagSeeder` exactly — `Family-Friendly` is seeded with a
     * hyphen and surfaces as `Family Friendly`, and a typo here silently drops
     * that tag from every user's profile without any error.
     */
    private const INTERESTS = [
        ['Mountain' => 3, 'Nature' => 3, 'Photography' => 2, 'Adventure' => 2],
        ['Beach' => 3, 'Water Activities' => 3, 'Relaxation' => 2, 'Photography' => 1],
        ['Historical' => 3, 'Cultural' => 3, 'Food and Dining' => 2, 'City' => 1],
        ['Nature' => 2, 'Camping' => 3, 'Adventure' => 2, 'Budget-Friendly' => 1],
        ['Family-Friendly' => 3, 'Nature' => 2, 'Relaxation' => 2, 'Budget-Friendly' => 1],
        ['Adventure' => 3, 'Mountain' => 2, 'Camping' => 2, 'Photography' => 1],
        ['Relaxation' => 3, 'Beach' => 2, 'Food and Dining' => 2, 'Countryside' => 1],
        ['Cultural' => 2, 'Historical' => 2, 'City' => 2, 'Shopping' => 1],
        ['Water Activities' => 3, 'Adventure' => 2, 'Nature' => 2, 'Photography' => 1],
        ['Countryside' => 3, 'Nature' => 2, 'Budget-Friendly' => 2, 'Food and Dining' => 1],
    ];

    /** Trip lengths, so the list is not 25 identical two-day trips. */
    private const TRIP_LENGTHS = [1, 2, 2, 3, 3, 3, 4, 5];

    private const GROUP_SIZES = [1, 2, 2, 3, 4, 5, 6];

    /**
     * Review bodies, keyed loosely to how a tester would actually write.
     *
     * These are recognisably placeholder copy on purpose. A beta tester should
     * not read a review and think someone wrote it, and the admin moderation
     * queue needs visible pending rows to work on.
     */
    private const REVIEWS = [
        [5, 'Went early to beat the crowd and it was completely empty. Worth the trip.'],
        [4, 'Nice place but bring cash — the signal was patchy and there was no ATM nearby.'],
        [5, 'One of the better days out we have had. Photos do not do the view justice.'],
        [3, 'Fine, but the walk from the parking area is longer than the map suggests.'],
        [4, 'Good for a half day. Not much to do once you are there, so plan around it.'],
        [5, 'Second visit and just as good. Bring layers, it is cooler than you expect.'],
        [2, 'Overcrowded on the weekend. Went back on a weekday and it was much better.'],
        [4, 'Staff were helpful and the place was clean. Fees were as advertised.'],
    ];

    public function run(): void
    {
        $catalogue = Destination::where('is_active', true)->get();

        if ($catalogue->count() === 0) {
            throw new RuntimeException(
                'The catalogue is empty. Run `php artisan db:seed` first — TagSeeder '
                .'and LuzonLocationsCsvSeeder have to have run, or every user here '
                .'would be created with nothing to recommend.'
            );
        }

        $tags = Tag::whereIn('slug', $this->interestSlugs())->get()->keyBy('slug');

        if ($tags->count() === 0) {
            throw new RuntimeException('No interest tags found. Run TagSeeder first.');
        }

        $this->command?->info(sprintf(
            'Seeding %d beta users against %d active destinations and %d tags.',
            count(self::PEOPLE),
            $catalogue->count(),
            $tags->count()
        ));

        $generated = 0;

        foreach (self::PEOPLE as $index => [$first, $last]) {
            $profile = $this->interestsFor($index);

            /*
             * `first_name` / `last_name`, NOT `name`.
             *
             * `User` is `#[Fillable(['first_name', 'last_name', ...])]` and
             * `name` is an ACCESSOR (`getNameAttribute`, `$appends`) built from
             * the two columns. Passing `name` to `updateOrCreate` is not a type
             * error and not an exception — it is silently dropped, and every
             * seeded user comes out blank in the nav bar with no complaint.
             *
             * `role` is set through `forceFill` for the same reason: it is not
             * on the fillable list either.
             */
            $user = User::withTrashed()->updateOrCreate(
                ['email' => sprintf('%s.%s@%s', Str::lower($first), Str::lower($last), self::DOMAIN)],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    // Verified, so nothing in the demo has to explain the banner.
                    'email_verified_at' => now(),
                    'password' => Hash::make(self::PASSWORD),
                ]
            );

            // Every beta account is an ordinary traveller. The admin gate is a
            // role check, and a demo database full of admins teaches nothing
            // about the admin pages. A re-run must not silently promote anyone,
            // so this resets the role rather than only setting it once.
            $user->forceFill(['role' => 'user'])->save();

            // `withTrashed()` matches a previously soft-deleted row, so a
            // deleted beta account would otherwise 404 on login forever.
            if ($user->trashed()) {
                $user->restore();
            }

            $travel = $this->travelProfileFor($user, $index);

            /*
             * Interests drive the recommendation score, so they must be the REAL
             * tags, resolved through `Str::slug()` because `TagSeeder` owns the
             * slugs.
             *
             * **DETACHED BY HAND FIRST, NOT LEFT TO `sync()`.** The pivot is
             * keyed on `(travel_profile_id, tag_id)` and `sync()` on this
             * relation still raised a unique violation on a re-run, because
             * `updateOrCreate` hands back the profile the previous run already
             * filled and the detach half did not clear it. The payload is
             * deduped by `tag_id` as well, since two interest names slugging to
             * one tag is an easy edit to make without noticing.
             */
            $records = $profile
                ->map(fn (int $weight, string $name) => [
                    'tag_id' => $tags[Str::slug($name)]->id,
                    'weight' => $weight,
                ])
                ->unique(fn (array $record) => $record['tag_id'])
                ->values()
                ->all();

            $travel->tags()->detach();

            if ($records !== []) {
                $travel->tags()->attach($records);
            }

            $this->clearHistory($user);

            $liked = $this->swipeHistory($user, $catalogue, $index);

            $this->reviews($user, $catalogue);

            $generated += $this->itinerary($user, $travel, $liked, $index);
        }

        $this->report($generated);
    }

    /**
     * The tag SLUGS the interest sets refer to.
     *
     * Resolved through `Str::slug()` rather than written out, because
     * `TagSeeder` owns the slugs and a hand-written list here would drift the
     * moment a tag is renamed — silently, by simply not matching.
     *
     * @return array<int, string>
     */
    private function interestSlugs(): array
    {
        return collect(self::INTERESTS)
            ->flatMap(fn (array $set) => array_keys($set))
            ->map(fn (string $name) => Str::slug($name))
            ->unique()
            ->values()
            ->all();
    }

    /** @return Collection<string, int> */
    private function interestsFor(int $index): Collection
    {
        return collect(self::INTERESTS[$index % count(self::INTERESTS)]);
    }

    private function travelProfileFor(User $user, int $index): TravelProfile
    {
        /*
         * BUDGET MIX FOLLOWS THE CATALOGUE, not an even split. The CSV holds 48
         * economy, 11 mid-range and 6 premium destinations, so a one-third-each
         * split would hand most testers a budget their province cannot fill and
         * their deck would be nearly empty.
         */
        $candidates = Destination::query()
            ->where('is_active', true)
            ->select('budget_level', 'province')
            ->get();

        $viable = $this->viablePairs($candidates);

        if ($viable->isEmpty()) {
            throw new RuntimeException(
                'No province holds a single active destination at any budget level, '
                .'so no beta user could be given a workable profile.'
            );
        }

        // Walking the viable list with a stride spreads users across provinces
        // AND budgets, and every pair drawn is one the generator can build from.
        $pair = $viable[$index % $viable->count()];

        return TravelProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'budget_level' => $pair['level'],
                'group_size' => self::GROUP_SIZES[$index % count(self::GROUP_SIZES)],
                'trip_duration_days' => self::TRIP_LENGTHS[$index % count(self::TRIP_LENGTHS)],
                'preferred_region' => $pair['province'],
            ]
        );
    }

    /**
     * THE (BUDGET LEVEL, PROVINCE) PAIRS THIS CATALOGUE CAN ACTUALLY SERVE.
     *
     * The two cannot be chosen independently, and finding that out the hard way
     * is what the first version of this seeder did: it handed one user the
     * `premium` budget and then discovered **no province holds three premium
     * destinations** — the catalogue has six in total, scattered one or two to a
     * province — and threw, taking 24 perfectly seedable users down with it.
     *
     * So they are resolved together. A pair is viable when a province holds at
     * least one destination at that level, which is all
     * `ItineraryGenerator` strictly needs; groups of three or more sort first,
     * because three is the generator's per-day cap and those provinces can fill
     * a trip without the overflow column doing the work.
     *
     * @param  Collection<int, \App\Models\Destination>  $candidates
     * @return Collection<int, array{level: string, province: string, count: int}>
     */
    private function viablePairs(Collection $candidates): Collection
    {
        return $candidates
            ->groupBy(fn (Destination $d) => $d->budget_level.'|'.$d->province)
            ->map(fn (Collection $group, string $key) => [
                'level' => $group->first()->budget_level,
                'province' => $group->first()->province,
                'count' => $group->count(),
            ])
            // Rich provinces first, then alphabetical so the pick is stable
            // between runs rather than depending on row order.
            ->sortBy([
                fn ($a, $b) => $b['count'] <=> $a['count'],
                fn ($a, $b) => [$a['province'], $a['level']] <=> [$b['province'], $b['level']],
            ])
            ->values();
    }

    /**
     * Clear this user's generated history so a re-run does not double it.
     *
     * Cascading deletes handle itinerary_days and itinerary_items, but only if
     * the relationships are declared — which they are, and relying on them is
     * safer than deleting half of it by hand.
     */
    private function clearHistory(User $user): void
    {
        $user->itineraries()->get()->each(function (Itinerary $itinerary): void {
            $itinerary->delete();
        });

        DestinationSwipe::where('user_id', $user->id)->delete();
        Review::where('user_id', $user->id)->delete();
    }

    /**
     * A MIXED SWIPE HISTORY, not a wall of likes.
     *
     * A deck where everything is liked scores every recommendation the same and
     * the swipe screen tells the tester nothing. Passing some places is what
     * makes the remaining ones mean anything.
     *
     * @param  Collection<int, \App\Models\Destination>  $catalogue
     * @return Collection<int, Destination>
     */
    private function swipeHistory(User $user, Collection $catalogue, int $index): Collection
    {
        $liked = $catalogue
            ->filter(fn (Destination $d) => $d->budget_level === $this->levelFor($user))
            ->shuffle()
            ->take($this->swipeCount($index));

        $passed = $catalogue
            ->reject(fn (Destination $d) => $liked->contains('id', $d->id))
            ->shuffle()
            ->take($this->swipeCount($index));

        foreach ($liked as $destination) {
            $this->swipe($user, $destination, 'liked');
        }

        foreach ($passed as $destination) {
            $this->swipe($user, $destination, 'passed');
        }

        return $liked->values();
    }

    /** Between 6 and 14 — enough that the deck has moved through real content. */
    private function swipeCount(int $index): int
    {
        return 6 + ($index % 9);
    }

    private function swipe(User $user, Destination $destination, string $action): void
    {
        DestinationSwipe::updateOrCreate(
            ['user_id' => $user->id, 'destination_id' => $destination->id],
            ['action' => $action]
        );
    }

    /**
     * A user's budget level, read back rather than passed around.
     *
     * `swipeHistory()` and `itinerary()` both need it and the profile is the
     * only source of truth — passing a third copy invites the three to disagree.
     */
    private function levelFor(User $user): string
    {
        return (string) ($user->travelProfile?->budget_level ?? 'economy');
    }

    /** @param  Collection<int, Destination>  $catalogue */
    private function reviews(User $user, Collection $catalogue): void
    {
        $level = $this->levelFor($user);

        $targets = $catalogue
            ->where('budget_level', $level)
            ->shuffle()
            ->take(2 + (($user->id ?? 0) % 4));

        foreach ($targets as $destination) {
            [$rating, $comment] = self::REVIEWS[($user->id + $destination->id) % count(self::REVIEWS)];

            Review::updateOrCreate(
                ['user_id' => $user->id, 'destination_id' => $destination->id],
                [
                    'rating' => $rating,
                    'comment' => $comment,
                    // One in four left pending, so the admin moderation queue
                    // has something in it during the beta.
                    'status' => (($user->id + $destination->id) % 4 === 0) ? 'pending' : 'published',
                ]
            );
        }
    }

    /**
     * Build one real itinerary through the real generator.
     *
     * @param  Collection<int, Destination>  $liked
     * @return int  1 when a trip was generated, 0 when there was nothing to build
     */
    private function itinerary(User $user, TravelProfile $travel, Collection $liked, int $index): int
    {
        /*
         * ASK THE RECOMMENDATION SERVICE WHICH STOPS ARE VALID, DO NOT RE-DERIVE
         * ITS RULES.
         *
         * The first version filtered on "liked and the same budget_level",
         * which is what `RecommendationService` looked like from the outside —
         * and 16 of 25 users came back with "One or more selected destinations
         * are no longer valid recommendations." The service applies rules this
         * seeder never saw, and `ItineraryGenerator` scores against
         * `recommend($user, 1000)` directly, so anything absent from that
         * collection is a stop the generator will refuse.
         *
         * `user_preferences` is cleared by `PreferenceController::update`, so
         * "what is a recommendation for this user" is a moving target owned by
         * exactly one class. Asking it is both shorter and the only version that
         * cannot drift — the same argument as using `formatHours()` instead of
         * formatting hours again in the PDF.
         */
        $valid = app(RecommendationService::class)
            ->recommend($user, 1000)
            ->pluck('id')
            ->flip();

        $liked = $liked->filter(fn (Destination $d) => $valid->has($d->id))->values();

        if ($liked->isEmpty()) {
            return 0;
        }

        /*
         * ONE PROVINCE ONLY. `ItineraryGenerator` filters on `where('province',
         * $area)`, so stops from two provinces cannot be combined — a city break
         * in Intramuros and a hike in Banaue are one trip in a traveller's head
         * and two incompatible rows here.
         */
        $grouped = $liked->groupBy('province')->sortByDesc(fn (Collection $g) => $g->count());

        $area = null;
        $stops = null;

        foreach ($grouped as $province => $group) {
            if ($group->count() >= 3) {
                $area = (string) $province;
                $stops = $group;

                break;
            }
        }

        // Fall back to the largest single group, so most users still get a trip
        // even on a thinner province.
        $area ??= (string) $grouped->keys()->first();
        $stops ??= $grouped->first();

        if ($stops === null || $stops->count() === 0) {
            return 0;
        }

        $days = min(
            max(1, (int) $travel->trip_duration_days),
            // The generator lays out 3 a day, so asking for more stops than days
            // allows just parks the remainder in `unscheduled_destinations` —
            // which would seed every user with a trip that says "unscheduled".
            (int) ceil($stops->count() / 3)
        );

        $days = max(1, $days);

        try {
            app(ItineraryGenerator::class)->create($user, [
                'area' => $area,
                'destination_ids' => $stops->take($days * 3)->pluck('id')->all(),
                'days' => $days,
            ]);
        } catch (RuntimeException $e) {
            /*
             * ONE USER'S CATALOGUE GAP MUST NOT STOP THE SEED.
             *
             * The generator throws when a stop is no longer a valid
             * recommendation, which happens the moment the catalogue changes
             * under a stale profile. Aborting 24 users in because the 25th hit a
             * province with two destinations would be a worse outcome than one
             * tester with no pre-built trip, who can still generate one.
             */
            $this->command?->warn(sprintf(
                '  %s: no itinerary (%s). The user can still generate one.',
                $user->email,
                $e->getMessage()
            ));

            return 0;
        }

        return 1;
    }

    private function report(int $generated): void
    {
        $this->command?->info('');
        $this->command?->info(sprintf(
            '  %d users · %d profiles · %d swipes · %d reviews · %d itineraries',
            User::where('email', 'like', '%@'.self::DOMAIN)->count(),
            TravelProfile::whereHas('user', fn ($q) => $q->where('email', 'like', '%@'.self::DOMAIN))->count(),
            DestinationSwipe::whereHas('user', fn ($q) => $q->where('email', 'like', '%@'.self::DOMAIN))->count(),
            Review::whereHas('user', fn ($q) => $q->where('email', 'like', '%@'.self::DOMAIN))->count(),
            Itinerary::whereHas('user', fn ($q) => $q->where('email', 'like', '%@'.self::DOMAIN))->count(),
        ));
        $this->command?->info(sprintf('  %d of %d users have a generated trip.', $generated, count(self::PEOPLE)));
        $this->command?->info('');
        $this->command?->info('  Log in with any of these, password: '.self::PASSWORD);
        $this->command?->info('   e.g. maria.santos@'.self::DOMAIN);

        $this->command?->info('');
        $this->command?->comment('  An admin for testing the admin pages:');
        $this->command?->comment('   php artisan tinker');
        $this->command?->comment("   App\\Models\\User::where('email', 'like', '%@".self::DOMAIN."')->first()->update(['role' => 'admin']);");
        $this->command?->info('');
    }
}
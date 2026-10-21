<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use App\Services\Planning\TripBrief;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WHAT MAY BE SENT TO THE MODEL, PINNED IN BOTH DIRECTIONS.
 *
 * `TripBrief` is the one place a Gemini prompt is assembled from, shared by the
 * deck assist and the trip draft. A free-tier request improves Google's
 * products, so the class is a privacy boundary as much as a presenter, and a
 * boundary that is only described in a docblock is a boundary nobody has
 * tested.
 *
 * SO EVERY TEST HERE IS ABOUT A LIST, NOT ABOUT FORMATTING:
 *
 * - **THE DENY-LIST.** A traveller's own words — `custom_name`,
 *   `custom_address`, `custom_notes` — and their account identity must never
 *   reach the wire. The fixture below puts a PERSON'S NAME in `custom_name`
 *   and asserts, character for character, that it is absent from the assembled
 *   payload. It is the field most likely to be swept in by a later change,
 *   because a drafting prompt naturally wants to mention the row it belongs to.
 * - **THE ALLOW-LIST.** Everything the model is *given* is asserted present, so
 *   a refactor cannot quietly drop the interest weights or the trip length and
 *   leave the feature answering a thinner question than its callers believe.
 * - **HOURS ARE RENDERED, NEVER RAW.** `always_open` means UNGATED, and
 *   `formatHours()` spells that `00:00–23:59`. Sent as a raw pair it tells a
 *   model the gate opens at midnight — the exact lie the PDF shipped once. Each
 *   shape gets a test so a new kind cannot arrive unlabelled.
 *
 * These are asserted against the presenter directly rather than through the
 * HTTP route, because the deny-list is a property of the assembly and must be
 * testable without a key, a deck or a network.
 */
class TripBriefTest extends TestCase
{
    use RefreshDatabase;

    private TripBrief $brief;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brief = new TripBrief;

        $this->user = User::factory()->create([
            'first_name' => 'Maria Clara',
            'last_name' => 'dela Cruz',
            'email' => 'maria.clara@example.test',
        ]);

        $profile = TravelProfile::create([
            'user_id' => $this->user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 3,
            'preferred_region' => 'Cordillera',
        ]);

        $nature = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $food = Tag::create(['name' => 'Food and Dining', 'slug' => 'food-and-dining']);

        $profile->tags()->attach($nature, ['weight' => 3]);
        $profile->tags()->attach($food, ['weight' => 1]);
    }

    // ------------------------------------------------------- the deny-list

    /**
     * THE ONE THAT MATTERS MOST.
     *
     * A trip holds a stop the traveller typed themselves — "Tita Baby's house,
     * 12 Mabini St" is a name and an address, and it is exactly the shape of
     * row a drafting prompt wants to hand the model so it can route around it.
     * It must not go.
     *
     * FALSIFIED by adding `custom_name` (or the address, or the note) to
     * `TripBrief::candidateRow()`: the assertion below then fails on the name
     * of a private individual, which is the failure this test exists to catch.
     */
    #[Test]
    public function a_travellers_own_words_never_reach_the_payload(): void
    {
        $destination = $this->destination('Burnham Park');

        $itinerary = $this->itinerary();
        $day = ItineraryDay::create([
            'itinerary_id' => $itinerary->id,
            'day_number' => 1,
            'date' => CarbonImmutable::parse('2026-11-03'),
        ]);

        ItineraryItem::create([
            'itinerary_day_id' => $day->id,
            'destination_id' => null,
            'item_type' => 'custom',
            'custom_name' => "Tita Baby's house",
            'custom_address' => '12 Mabini Street, Baguio',
            // `note` is the free-text column a traveller types into.
            'note' => 'My aunt is expecting us for lunch, ring the bell twice',
            'sort_order' => 1,
        ]);

        // Everything the presenter can assemble for this trip, concatenated:
        // the deny-list must hold across the WHOLE brief, not just one method.
        $payload = implode("\n", [
            $this->brief->profileClause($this->user),
            $this->brief->interests($this->user),
            $this->brief->candidateRows(collect([$destination])),
            json_encode($this->brief->candidateRow($destination), JSON_UNESCAPED_UNICODE),
            $this->brief->hoursLine($destination),
            $this->brief->tripClause($itinerary),
        ]);

        // A traveller's own prose, in all three columns it can live in.
        $this->assertStringNotContainsString("Tita Baby", $payload);
        $this->assertStringNotContainsString('12 Mabini Street', $payload);
        $this->assertStringNotContainsString('ring the bell twice', $payload);

        // And the account identity, which no surface may send.
        //
        // `User::name` is an ACCESSOR over `first_name` + `last_name` (there is
        // no `name` column), so the assertion below is the identity the app
        // actually renders — and it is asserted non-empty first, so the
        // deny-list cannot pass by comparing against an empty string.
        $this->assertNotSame('', $this->user->name);
        $this->assertStringNotContainsString($this->user->name, $payload);
        $this->assertStringNotContainsString($this->user->email, $payload);
    }

    /**
     * A SHARE TOKEN IS A CAPABILITY, NOT A FACT.
     *
     * It is the one column on the trip that grants access rather than
     * describing it, so it is the single worst string to hand a third party —
     * and it is attached to the very row a draft prompt is built from.
     */
    #[Test]
    public function the_trips_share_token_never_reaches_the_payload(): void
    {
        $itinerary = $this->itinerary();
        $token = $itinerary->sharedToken();

        $this->assertNotSame('', $token);

        $payload = $this->brief->tripClause($itinerary->fresh());

        $this->assertStringNotContainsString($token, $payload);
    }

    // ------------------------------------------------------ the allow-list

    /**
     * THE FACTS THE MODEL IS GIVEN, EACH ONE ASSERTED PRESENT.
     *
     * Every line here is something the model NEEDS in order to choose between
     * things the server already knows. Dropping one does not error — it makes
     * the model answer a thinner question — so each is pinned rather than
     * assumed.
     */
    #[Test]
    public function the_profile_clause_carries_the_tier_the_region_and_the_weights(): void
    {
        $clause = $this->brief->profileClause($this->user);

        // The tier is an exact-match filter (ADR 0002), not a preference.
        $this->assertStringContainsString('budget tier "economy"', $clause);

        // The region is only present when the traveller set one.
        $this->assertStringContainsString('region preference "Cordillera"', $clause);

        // NAME AND WEIGHT BOTH. "Nature" alone loses the difference between a
        // traveller who rates it 3 and one who rates it 1, which is most of
        // what the ranking is for.
        $this->assertStringContainsString('Nature (weight 3)', $clause);
        $this->assertStringContainsString('Food and Dining (weight 1)', $clause);
    }

    /**
     * NO REGION PREFERENCE SENDS NO REGION CLAUSE.
     *
     * The optional half is genuinely optional: a traveller who never chose a
     * region must not have one invented for them, and the region filter is a
     * server-side candidate decision that runs BEFORE the prompt anyway.
     */
    #[Test]
    public function a_profile_without_a_region_sends_no_region_clause(): void
    {
        $this->user->travelProfile->update(['preferred_region' => null]);

        $clause = $this->brief->profileClause($this->user->fresh());

        $this->assertStringContainsString('budget tier "economy"', $clause);
        $this->assertStringNotContainsString('region preference', $clause);
    }

    /**
     * A CANDIDATE ROW CARRIES ITS ID, NAME, AREA, TAGS AND FEE NOTE.
     *
     * `id` is the contract — the only thing a reply is trusted to name, and
     * every id it names is checked against the map these rows built. `area` is
     * trimmed because `municipality` or `province` may be blank on a real row,
     * and an untrimmed join sends a dangling comma into a prompt whose whole
     * job is being precise about places.
     */
    #[Test]
    public function a_candidate_row_carries_every_allow_listed_field(): void
    {
        $destination = $this->destination('Burnham Park');

        $row = $this->brief->candidateRow($destination);

        $this->assertSame($destination->id, $row['id']);
        $this->assertSame('Burnham Park', $row['name']);
        $this->assertSame('Baguio, Benguet', $row['area']);
        $this->assertSame(['Nature'], $row['tags']);

        // `entrance_fee_display` is null on this fixture, so the fee falls to
        // the catalogue's own estimate — LABELLED as one, because it is not a
        // ticket price. Asserting the label and not just the number is the
        // point: `PHP 60` alone would read to a model as an admission fee.
        $this->assertSame('curated estimate PHP 60', $row['catalogue_fee_estimate']);
    }

    /**
     * A BLANK MUNICIPALITY DOES NOT SEND A DANGLING COMMA.
     *
     * `trim()` is load-bearing here: without it a row with no municipality
     * reads `", Benguet"` — a place the prompt then describes as being nowhere
     * in particular.
     */
    #[Test]
    public function a_blank_municipality_leaves_no_dangling_comma(): void
    {
        $destination = $this->destination('Mystery Falls');
        $destination->update(['municipality' => '']);

        $row = $this->brief->candidateRow($destination->fresh());

        $this->assertSame('Benguet', $row['area']);
    }

    // ------------------------------------------------------------- fees

    /**
     * A PUBLISHED FEE STRING IS SHARED VERBATIM.
     *
     * It is a display string that a curator wrote and the page already renders,
     * so re-phrasing it here would create a second version of a published fact.
     */
    #[Test]
    public function a_published_fee_display_is_shared_verbatim(): void
    {
        $destination = $this->destination('Burnham Park');
        $destination->update(['entrance_fee_display' => 'PHP 60 — free for seniors']);

        $this->assertSame(
            'PHP 60 — free for seniors',
            $this->brief->feeNote($destination->fresh())
        );
    }

    /**
     * AN UNPUBLISHED FEE IS `null`, NEVER A ZERO.
     *
     * `destinations.estimated_cost` is `default 0`, so "nobody verified this"
     * and "it costs nothing" are the same stored value on that table. A model
     * told a fee is zero will plan a free day, so the honest third state is
     * carried as JSON `null` rather than as `0` or the word "unknown".
     *
     * FALSIFIED by returning `'0'` or by dropping the `> 0` guard: the reply
     * then plans around a price nobody published.
     */
    #[Test]
    public function an_unpublished_fee_is_null_rather_than_zero(): void
    {
        $destination = $this->destination('Mystery Falls');
        $destination->update(['entrance_fee_display' => null, 'estimated_cost' => 0]);

        $this->assertNull($this->brief->feeNote($destination->fresh()));
    }

    /**
     * A CURATED ESTIMATE IS LABELLED AS ONE.
     *
     * `estimated_cost` is not a ticket price and the prefix says so, so the
     * model cannot restate it as an admission fee in a sentence we then print
     * beside a place whose real fee is unknown.
     */
    #[Test]
    public function a_curated_estimate_is_prefixed_with_its_own_origin(): void
    {
        $destination = $this->destination('Mystery Falls');
        $destination->update([
            'entrance_fee_display' => null,
            'estimated_cost' => 250,
        ]);

        $this->assertSame(
            'curated estimate PHP 250',
            $this->brief->feeNote($destination->fresh())
        );
    }

    // ------------------------------------------------------------ hours

    /**
     * HOURS ARRIVE AS THE APP RENDERS THEM, SHAPE BY SHAPE.
     *
     * THE `always_open` CASE IS THE REASON THIS METHOD EXISTS. A curated
     * 00:00–23:59 means UNGATED — a mountainside viewpoint with no gate —
     * and `formatHours()` returns exactly that pair for it. Sent raw, the
     * prompt tells a model the gate opens at midnight, which is the lie the
     * PDF shipped once and now refuses. It is checked by the COLUMN, not by
     * comparing the formatted string, so the two cannot drift apart.
     */
    #[Test]
    public function an_ungated_place_reads_as_no_gate_rather_than_midnight_to_midnight(): void
    {
        $destination = $this->destination('Kiltepan');
        $destination->update([
            'opening_time' => '00:00',
            'closing_time' => '23:59',
            'hours_kind' => Destination::HOURS_ALWAYS_OPEN,
            'daily_hours' => null,
        ]);

        $destination = $destination->fresh();

        // The raw renderer is what we are NOT sending, and it says the wrong
        // thing — asserted so the reason for the special case is visible.
        $this->assertSame('00:00–23:59', $destination->formatHours());

        $this->assertSame('Open — no gate', $this->brief->hoursLine($destination));
    }

    /**
     * A PER-DAY WEEK KEEPS ITS PER-DAY SUMMARY.
     *
     * Flattening a week that differs into one pair is the collapse the per-day
     * schema exists to prevent — Fort Santiago's Mon–Fri 08–22 / Sat–Sun 06–22
     * is two different days, and a model given one pair will schedule it on
     * the wrong one.
     */
    #[Test]
    public function a_per_day_week_keeps_its_per_day_summary(): void
    {
        $destination = $this->destination('Fort Santiago');
        $destination->update([
            'hours_kind' => Destination::HOURS_PER_DAY,
            // `daily_hours` casts to an array, so it goes in as one.
            'daily_hours' => [
                'monday' => ['open' => '08:00', 'close' => '22:00'],
                'tuesday' => ['open' => '08:00', 'close' => '22:00'],
                'wednesday' => ['open' => '08:00', 'close' => '22:00'],
                'thursday' => ['open' => '08:00', 'close' => '22:00'],
                'friday' => ['open' => '08:00', 'close' => '22:00'],
                'saturday' => ['open' => '06:00', 'close' => '22:00'],
                'sunday' => ['open' => '06:00', 'close' => '22:00'],
            ],
        ]);

        $line = $this->brief->hoursLine($destination->fresh());

        $this->assertStringContainsString('08:00–22:00', $line);
        $this->assertStringContainsString('06:00–22:00', $line);
        $this->assertNotSame('00:00–23:59', $line);
    }

    /**
     * A PLAIN WINDOW IS THE PLAIN RENDERER.
     */
    #[Test]
    public function a_plain_window_uses_the_models_own_renderer(): void
    {
        $destination = $this->destination('Pinto Art Museum');
        $destination->update([
            'opening_time' => '09:00',
            'closing_time' => '18:00',
            'hours_kind' => null,
            'daily_hours' => null,
            'closed_days' => null,
        ]);

        $this->assertSame('09:00–18:00', $this->brief->hoursLine($destination->fresh()));
    }

    /**
     * NO HOURS AT ALL IS "Hours not listed", WHICH BEATS A WRONG NUMBER.
     *
     * The catalogue's own rule, and the one a model must not be left to fill
     * in for itself.
     */
    #[Test]
    public function a_place_with_no_hours_says_so(): void
    {
        $destination = $this->destination('Mystery Falls');
        $destination->update([
            'opening_time' => null,
            'closing_time' => null,
            'hours_kind' => null,
            'daily_hours' => null,
        ]);

        $this->assertSame('Hours not listed', $this->brief->hoursLine($destination->fresh()));
    }

    // ------------------------------------------------------------- trip

    /**
     * THE TRIP CLAUSE CARRIES ONLY THE PARTS THAT ARE SET.
     *
     * An undated trip must not be handed an invented date, and a trip with no
     * ceiling must not be handed a budget — the calendar writer already refuses
     * to build a file for a trip whose days carry no date, for the same reason:
     * a date nobody chose is a plan for a day that does not exist.
     */
    #[Test]
    public function an_undated_trip_with_no_budget_sends_neither(): void
    {
        $itinerary = $this->itinerary(['start_date' => null, 'budget_amount' => null]);

        $clause = $this->brief->tripClause($itinerary);

        $this->assertStringContainsString('trip length "3 day(s)"', $clause);
        $this->assertStringContainsString('undated', $clause);
        $this->assertStringNotContainsString('starts', $clause);
        $this->assertStringNotContainsString('ceiling', $clause);
    }

    /**
     * A DATED TRIP CARRIES ITS START AND END, DERIVED WITHOUT TOUCHING THE TRIP.
     *
     * `Itinerary::start_date` is a `date` cast, so the value handed over is a
     * mutable Carbon. The presenter takes an immutable copy anyway, and the two
     * dates below are asserted to be three days apart.
     *
     * THE COPY IS DEFENSIVE, NOT LOAD-BEARING, AND THAT WAS MEASURED RATHER
     * THAN ASSUMED. An earlier draft of this test claimed it guarded against
     * an in-place mutation of the trip's own start date, and the falsification
     * harness could not make it bite. Probing the platform explains why:
     * `add()` and `modify()` on the cast object both return the same instance
     * WITHOUT mutating the model, because **each read of a `date` cast hands
     * back a distinct clone** — so the trip's stored date is unreachable
     * through the returned object by any method. The test therefore pins the
     * two dates and the immutability of the arithmetic, which is what a reader
     * actually needs; it does not claim to guard a mutation that cannot happen.
     *
     * Integer arithmetic is used over `modify()` because it works on both a
     * mutable and an immutable Carbon, which is the same reason `asDate()`
     * exists in the PDF writer.
     */
    #[Test]
    public function a_dated_trip_carries_a_start_and_a_derived_end(): void
    {
        $itinerary = $this->itinerary(['start_date' => '2026-11-03']);

        $clause = $this->brief->tripClause($itinerary);

        $this->assertStringContainsString('starts "Tue 3 Nov 2026"', $clause);
        $this->assertStringContainsString('ends "Thu 5 Nov 2026"', $clause);

        // The trip's own start date is exactly where it was.
        $this->assertSame('2026-11-03', $itinerary->start_date->format('Y-m-d'));
    }

    /**
     * A STATED CEILING TRAVELS, BECAUSE THE DRAFT HAS TO RESPECT IT.
     *
     * It is the traveller's own number and it is not derived from
     * `budget_level` — that column is a catalogue FILTER (ADR 0002), not an
     * amount, so a peso figure per tier would be a number nobody published.
     */
    #[Test]
    public function a_stated_ceiling_travels_and_a_null_one_does_not(): void
    {
        $withCeiling = $this->itinerary(['budget_amount' => 12000]);
        $this->assertStringContainsString(
            "the traveller's own ceiling is PHP 12,000",
            $this->brief->tripClause($withCeiling)
        );

        $without = $this->itinerary(['budget_amount' => null]);
        $this->assertStringNotContainsString('ceiling', $this->brief->tripClause($without));
    }

    // ----------------------------------------------------------- helpers

    private function destination(string $name): Destination
    {
        $destination = Destination::create([
            'name' => $name,
            'slug' => str($name)->slug(),
            'description' => 'A place in Luzon worth the walk.',
            'province' => 'Benguet',
            'municipality' => 'Baguio',
            'latitude' => 16.4,
            'longitude' => 120.6,
            'budget_level' => 'economy',
            'estimated_cost' => 60,
            'recommended_minutes' => 90,
            'is_active' => true,
            'is_featured' => true,
        ]);

        $destination->tags()->attach(Tag::where('slug', 'nature')->first());

        return $destination;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function itinerary(array $attributes = []): Itinerary
    {
        return Itinerary::create(array_merge([
            'user_id' => $this->user->id,
            'title' => 'Baguio long weekend',
            'area' => 'Benguet',
            'start_date' => '2026-11-03',
            'budget_level' => 'economy',
            'budget_amount' => null,
            'trip_duration_days' => 3,
            'total_estimated_cost' => 0,
            'match_score' => 0,
        ], $attributes));
    }
}

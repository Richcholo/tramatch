<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItineraryEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TravelProfile $profile;

    /**
     * @var array<string, Destination>
     */
    private array $destinations = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->profile = TravelProfile::create([
            'user_id' => $this->user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 1,
        ]);

        $tag = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $this->profile->tags()->attach($tag, ['weight' => 3]);

        foreach ([
            ['alpha', 'Alpha Trail', 800, 120],
            ['bravo', 'Bravo Falls', 400, 90],
            ['charlie', 'Charlie Ridge', 600, 60],
        ] as [$slug, $name, $cost, $minutes]) {
            $destination = Destination::create([
                'name' => $name,
                'slug' => $slug,
                'description' => 'A place.',
                'province' => 'Laguna',
                'latitude' => 14.3,
                'longitude' => 121.4,
                'budget_level' => 'economy',
                'estimated_cost' => $cost,
                'recommended_minutes' => $minutes,
            ]);

            $destination->tags()->attach($tag);
            $this->destinations[$slug] = $destination;

            DestinationSwipe::create([
                'user_id' => $this->user->id,
                'destination_id' => $destination->id,
                'action' => 'liked',
            ]);
        }
    }

    /**
     * Generate an itinerary through the real request path so the editor is
     * tested against the shape the generator actually produces.
     */
    private function generateItinerary(string ...$slugs): Itinerary
    {
        $this->actingAs($this->user)->post(route('itineraries.store'), [
            'title' => 'Lakeside loop',
            'area' => 'Laguna',
            'destination_ids' => array_map(
                fn (string $slug) => $this->destinations[$slug]->id,
                $slugs
            ),
        ])->assertRedirect();

        return $this->user->itineraries()->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ItineraryItem $item, array $overrides = []): array
    {
        return array_merge([
            'day_id' => $item->itinerary_day_id,
            'sort_order' => $item->sort_order,
            'destination_id' => $item->destination_id,
            'start_time' => $item->start_time ? substr($item->start_time, 0, 5) : null,
            'end_time' => $item->end_time ? substr($item->end_time, 0, 5) : null,
            'estimated_cost' => number_format((float) $item->estimated_cost, 2, '.', ''),
        ], $overrides);
    }

    public function test_a_stop_can_be_moved_earlier_in_the_day(): void
    {
        $itinerary = $this->generateItinerary('alpha', 'bravo');
        $items = $itinerary->days->first()->items;

        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $items[1]->id => $this->row($items[1], ['sort_order' => 1]),
                    $items[0]->id => $this->row($items[0], ['sort_order' => 2]),
                ],
            ])
            ->assertRedirect(route('itineraries.show', $itinerary));

        $this->assertSame(
            [$items[1]->id, $items[0]->id],
            $itinerary->fresh()->days->first()->items->pluck('id')->all()
        );
    }

    public function test_moving_a_stop_renumbers_the_day(): void
    {
        $itinerary = $this->generateItinerary('alpha', 'bravo');
        $items = $itinerary->days->first()->items;

        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $items[1]->id => $this->row($items[1], ['sort_order' => 1]),
                    $items[0]->id => $this->row($items[0], ['sort_order' => 2]),
                ],
            ])
            ->assertRedirect();

        $day = $itinerary->fresh()->days->first();

        $this->assertSame(
            [$items[1]->id, $items[0]->id],
            $day->items->pluck('id')->all()
        );

        $this->assertSame(
            [1, 2],
            $day->items->map(fn ($item) => (int) $item->sort_order)->all()
        );
    }

    public function test_the_travel_gap_follows_the_times_that_survive_a_reorder(): void
    {
        $itinerary = $this->generateItinerary('alpha', 'bravo');
        $items = $itinerary->days->first()->items;

        // Alpha is generated first, so it holds the early window. Move it second
        // without touching its times and it now starts before the stop above it.
        // Free editing means the typed times win, so the gap is clamped at zero
        // rather than going negative. The editor's live badge says "Back to
        // back" for this state, and Reflow is the way out of it.
        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $items[1]->id => $this->row($items[1], ['sort_order' => 1]),
                    $items[0]->id => $this->row($items[0], ['sort_order' => 2]),
                ],
            ])
            ->assertRedirect();

        $moved = $itinerary->fresh()->days->first()->items->keyBy('id');

        $this->assertSame(0, (int) $moved[$items[0]->id]->travel_minutes_from_previous);
        $this->assertSame(
            substr($items[0]->start_time, 0, 5),
            substr($moved[$items[0]->id]->start_time, 0, 5)
        );
    }

    public function test_reflowing_a_reordered_day_spaces_the_stops_out_again(): void
    {
        $itinerary = $this->generateItinerary('alpha', 'bravo');
        $items = $itinerary->days->first()->items;
        $dayId = $items[0]->itinerary_day_id;

        // Same reorder as above, but the traveller now asks for a reflow.
        // Bravo is 90 minutes and Alpha is 120, so Bravo takes the 08:00 opener
        // and Alpha is pushed past the 45 minute travel gap and then past lunch.
        $response = $this->actingAs($this->user)
            ->post(route('itineraries.schedule', $itinerary), [
                'items' => [
                    $items[1]->id => [
                        'day_id' => $dayId,
                        'sort_order' => 1,
                        'destination_id' => $items[1]->destination_id,
                        'start_time' => $this->row($items[1])['start_time'],
                        'end_time' => $this->row($items[1])['end_time'],
                    ],
                    $items[0]->id => [
                        'day_id' => $dayId,
                        'sort_order' => 2,
                        'destination_id' => $items[0]->destination_id,
                        'start_time' => $this->row($items[0])['start_time'],
                        'end_time' => $this->row($items[0])['end_time'],
                    ],
                ],
            ])
            ->assertOk();

        $slots = $response->json('days.'.$dayId);

        $this->assertSame('08:00', $slots[$items[1]->id]['start_time']);
        $this->assertSame('09:30', $slots[$items[1]->id]['end_time']);
        $this->assertSame('13:00', $slots[$items[0]->id]['start_time']);
        $this->assertSame('15:00', $slots[$items[0]->id]['end_time']);
    }

    public function test_the_times_on_a_stop_can_be_rewritten(): void
    {
        $itinerary = $this->generateItinerary('alpha');
        $item = $itinerary->days->first()->items->first();

        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $item->id => $this->row($item, [
                        'start_time' => '05:30',
                        'end_time' => '06:15',
                    ]),
                ],
            ])
            ->assertRedirect();

        // No window is enforced on a hand-edited trip: a 05:00 registration
        // cut-off is a real thing to want.
        $this->assertSame('05:30', substr($item->fresh()->start_time, 0, 5));
        $this->assertSame('06:15', substr($item->fresh()->end_time, 0, 5));
    }

    public function test_a_stop_that_ends_before_it_starts_is_rejected(): void
    {
        $itinerary = $this->generateItinerary('alpha');
        $item = $itinerary->days->first()->items->first();
        $before = $item->start_time;

        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $item->id => $this->row($item, [
                        'start_time' => '14:00',
                        'end_time' => '11:00',
                    ]),
                ],
            ])
            ->assertSessionHasErrors('items');

        $this->assertSame($before, $item->fresh()->start_time);
    }

    public function test_a_stop_left_out_of_the_payload_is_removed(): void
    {
        $itinerary = $this->generateItinerary('alpha', 'bravo');
        $items = $itinerary->days->first()->items;

        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $items[0]->id => $this->row($items[0]),
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('itinerary_items', ['id' => $items[1]->id]);
        $this->assertDatabaseHas('itinerary_items', ['id' => $items[0]->id]);
    }

    public function test_removing_a_stop_lowers_the_total_on_the_header(): void
    {
        $itinerary = $this->generateItinerary('alpha', 'bravo');
        $items = $itinerary->days->first()->items;

        $this->assertSame('1200.00', $itinerary->total_estimated_cost);

        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $items[0]->id => $this->row($items[0]),
                ],
            ])
            ->assertRedirect();

        $this->assertSame('800.00', $itinerary->fresh()->total_estimated_cost);
    }

    public function test_editing_a_cost_moves_the_header_total(): void
    {
        $itinerary = $this->generateItinerary('alpha');
        $item = $itinerary->days->first()->items->first();

        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $item->id => $this->row($item, ['estimated_cost' => '1250.50']),
                ],
            ])
            ->assertRedirect();

        $this->assertSame('1250.50', $itinerary->fresh()->total_estimated_cost);
    }

    public function test_a_liked_place_that_is_not_on_the_trip_can_be_added(): void
    {
        $itinerary = $this->generateItinerary('alpha');
        $day = $itinerary->days->first();
        $item = $day->items->first();
        $charlie = $this->destinations['charlie'];

        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $item->id => $this->row($item),
                    // Negative keys are what the browser invents for a stop the
                    // traveller has just added.
                    '-1' => [
                        'day_id' => $day->id,
                        'sort_order' => 2,
                        'destination_id' => $charlie->id,
                        'start_time' => null,
                        'end_time' => null,
                        'estimated_cost' => '600.00',
                        'note' => '',
                    ],
                ],
            ])
            ->assertRedirect();

        $added = ItineraryItem::where('destination_id', $charlie->id)->firstOrFail();

        $this->assertSame($day->id, $added->itinerary_day_id);
        $this->assertSame(2, (int) $added->sort_order);
        $this->assertSame('1400.00', $itinerary->fresh()->total_estimated_cost);
    }

    public function test_a_stop_added_to_a_reordered_day_does_not_overlap_one_already_there(): void
    {
        $itinerary = $this->generateItinerary('alpha', 'bravo');
        $items = $itinerary->days->first()->items;
        $dayId = $items[0]->itinerary_day_id;

        // The generator gives Bravo the later window, because Alpha runs 08:00-10:00
        // and Bravo is pushed past lunch. Moving Alpha to second leaves a day
        // whose stops are not in chronological order: Bravo ends at 13:00 and the
        // stop after it ends at 10:00.
        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $items[1]->id => $this->row($items[1], ['sort_order' => 1]),
                    $items[0]->id => $this->row($items[0], ['sort_order' => 2]),
                ],
            ])
            ->assertRedirect();

        $this->assertSame('14:30', substr($items[1]->fresh()->end_time, 0, 5));
        $this->assertSame('10:00', substr($items[0]->fresh()->end_time, 0, 5));

        // Now add an untimed stop. Following the last row's end would put it at
        // 10:45, straight inside Bravo, so it has to go after the day really ends
        // at 14:30, plus the 45 minute travel gap.
        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $items[1]->id => $this->row($items[1], ['sort_order' => 1]),
                    $items[0]->id => $this->row($items[0], ['sort_order' => 2]),
                    '-1' => [
                        'day_id' => $dayId,
                        'sort_order' => 3,
                        'destination_id' => $this->destinations['charlie']->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $added = ItineraryItem::where('destination_id', $this->destinations['charlie']->id)
            ->firstOrFail();

        $this->assertSame('15:15', substr($added->start_time, 0, 5));
        $this->assertSame('16:15', substr($added->end_time, 0, 5));

        // The stored gap is from the row above, which is Alpha at 10:00, so it
        // reads as 315 minutes. That is what the column means, and the editor's
        // live badge shows the same thing, so the number is at least honest
        // rather than flattering.
        $this->assertSame(315, (int) $added->travel_minutes_from_previous);
    }

    public function test_a_stop_added_without_times_is_given_the_next_free_slot(): void
    {
        $itinerary = $this->generateItinerary('alpha');
        $day = $itinerary->days->first();
        $item = $day->items->first();

        // Alpha is 08:00-10:00 and Charlie wants 60 minutes, so the server puts
        // it at 10:45 rather than leaving the traveller a blank row.
        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $item->id => $this->row($item),
                    '-1' => [
                        'day_id' => $day->id,
                        'sort_order' => 2,
                        'destination_id' => $this->destinations['charlie']->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $added = ItineraryItem::where('destination_id', $this->destinations['charlie']->id)
            ->firstOrFail();

        $this->assertSame('10:45', substr($added->start_time, 0, 5));
        $this->assertSame('11:45', substr($added->end_time, 0, 5));
        $this->assertSame(45, (int) $added->travel_minutes_from_previous);
    }

    public function test_a_place_the_traveller_never_liked_cannot_be_added(): void
    {
        $itinerary = $this->generateItinerary('alpha');
        $day = $itinerary->days->first();
        $item = $day->items->first();

        $stranger = Destination::create([
            'name' => 'Never Seen It',
            'slug' => 'never-seen-it',
            'description' => 'A place.',
            'province' => 'Laguna',
            'latitude' => 14.4,
            'longitude' => 121.5,
            'budget_level' => 'economy',
            'estimated_cost' => 100,
            'recommended_minutes' => 60,
        ]);

        $this->actingAs($this->user)
            ->from(route('itineraries.show', $itinerary))
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $item->id => $this->row($item),
                    '-1' => [
                        'day_id' => $day->id,
                        'sort_order' => 2,
                        'destination_id' => $stranger->id,
                    ],
                ],
            ])
            ->assertSessionHasErrors('itinerary');

        $this->assertDatabaseMissing('itinerary_items', ['destination_id' => $stranger->id]);
    }

    public function test_a_place_already_on_the_trip_cannot_be_added_twice(): void
    {
        $itinerary = $this->generateItinerary('alpha');
        $day = $itinerary->days->first();
        $item = $day->items->first();

        $this->actingAs($this->user)
            ->from(route('itineraries.show', $itinerary))
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $item->id => $this->row($item),
                    '-1' => [
                        'day_id' => $day->id,
                        'sort_order' => 2,
                        'destination_id' => $this->destinations['alpha']->id,
                    ],
                ],
            ])
            ->assertSessionHasErrors('itinerary');

        $this->assertSame(1, ItineraryItem::count());
    }

    public function test_another_travellers_itinerary_cannot_be_edited(): void
    {
        $itinerary = $this->generateItinerary('alpha');
        $item = $itinerary->days->first()->items->first();

        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $item->id => $this->row($item, ['start_time' => '03:00', 'end_time' => '04:00']),
                ],
            ])
            ->assertForbidden();

        $this->assertSame(
            substr($item->start_time, 0, 5),
            substr($item->fresh()->start_time, 0, 5)
        );
    }

    public function test_a_stop_belonging_to_another_itinerary_cannot_be_edited(): void
    {
        $mine = $this->generateItinerary('alpha');
        $mineItem = $mine->days->first()->items->first();

        // A second traveller with a trip of their own.
        $other = User::factory()->create();
        $theirs = Itinerary::create([
            'user_id' => $other->id,
            'title' => 'Someone else',
            'area' => 'Laguna',
            'budget_level' => 'economy',
            'trip_duration_days' => 1,
        ]);
        $theirsDay = $theirs->days()->create(['day_number' => 1]);
        $theirsItem = $theirsDay->items()->create([
            'destination_id' => $this->destinations['bravo']->id,
            'sort_order' => 1,
            'start_time' => '08:00',
            'end_time' => '09:30',
            'estimated_cost' => 400,
        ]);

        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $mine), [
                'items' => [
                    $mineItem->id => $this->row($mineItem),
                    $theirsItem->id => $this->row($theirsItem, ['sort_order' => 2]),
                ],
            ])
            ->assertSessionHasErrors('items');

        $this->assertSame(
            substr($theirsItem->start_time, 0, 5),
            substr($theirsItem->fresh()->start_time, 0, 5)
        );
    }

    public function test_a_day_from_another_itinerary_cannot_be_targeted(): void
    {
        $mine = $this->generateItinerary('alpha');
        $mineItem = $mine->days->first()->items->first();

        $other = User::factory()->create();
        $theirs = Itinerary::create([
            'user_id' => $other->id,
            'title' => 'Someone else',
            'area' => 'Laguna',
            'budget_level' => 'economy',
            'trip_duration_days' => 1,
        ]);
        $theirsDay = $theirs->days()->create(['day_number' => 1]);

        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $mine), [
                'items' => [
                    $mineItem->id => $this->row($mineItem, ['day_id' => $theirsDay->id]),
                ],
            ])
            ->assertSessionHasErrors('items.*.day_id');

        $this->assertSame($mineItem->itinerary_day_id, $mineItem->fresh()->itinerary_day_id);
    }

    public function test_reflow_returns_times_without_writing_them(): void
    {
        $itinerary = $this->generateItinerary('alpha', 'bravo');
        $items = $itinerary->days->first()->items;
        $dayId = $items[0]->itinerary_day_id;

        // The traveller shortens the first stop to 30 minutes and leaves the
        // second blank. Reflow keeps the 30 minutes, restarts the day at 08:00,
        // and pushes the second stop up by the 45 minute travel gap. Only the
        // server can do that, because it owns the travel gap and the lunch
        // block.
        $response = $this->actingAs($this->user)
            ->post(route('itineraries.schedule', $itinerary), [
                'items' => [
                    $items[0]->id => [
                        'day_id' => $dayId,
                        'sort_order' => 1,
                        'destination_id' => $items[0]->destination_id,
                        'start_time' => '09:00',
                        'end_time' => '09:30',
                    ],
                    $items[1]->id => [
                        'day_id' => $dayId,
                        'sort_order' => 2,
                        'destination_id' => $items[1]->destination_id,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonStructure(['days' => [(string) $dayId]]);

        $slots = $response->json('days.'.$dayId);

        // Reflow lays the day out again from the durations, so the typed 09:00
        // is deliberately not the answer.
        $this->assertSame('08:00', $slots[$items[0]->id]['start_time']);
        $this->assertSame('08:30', $slots[$items[0]->id]['end_time']);
        $this->assertSame(0, $slots[$items[0]->id]['travel_minutes_from_previous']);

        // Bravo wants 90 minutes. 08:30 plus 45 minutes of travel is 09:15,
        // which would run to 10:45, so no lunch nudge is needed.
        $this->assertSame('09:15', $slots[$items[1]->id]['start_time']);
        $this->assertSame('10:45', $slots[$items[1]->id]['end_time']);
        $this->assertSame(45, $slots[$items[1]->id]['travel_minutes_from_previous']);

        // Nothing was persisted.
        $this->assertSame('08:00', substr($items[0]->fresh()->start_time, 0, 5));
    }

    public function test_reflow_keeps_a_stop_out_of_the_lunch_block(): void
    {
        $itinerary = $this->generateItinerary('alpha', 'bravo');
        $items = $itinerary->days->first()->items;
        $dayId = $items[0]->itinerary_day_id;

        // A 120 minute first stop followed by Bravo's 90 minutes would reach
        // 12:15, straight through the 12:00-13:00 lunch block, so reflow has to
        // push the second stop to 13:00.
        $response = $this->actingAs($this->user)
            ->post(route('itineraries.schedule', $itinerary), [
                'items' => [
                    $items[0]->id => [
                        'day_id' => $dayId,
                        'sort_order' => 1,
                        'destination_id' => $items[0]->destination_id,
                        'start_time' => '10:00',
                        'end_time' => '12:00',
                    ],
                    $items[1]->id => [
                        'day_id' => $dayId,
                        'sort_order' => 2,
                        'destination_id' => $items[1]->destination_id,
                    ],
                ],
            ])
            ->assertOk();

        $slots = $response->json('days.'.$dayId);

        $this->assertSame('08:00', $slots[$items[0]->id]['start_time']);
        $this->assertSame('13:00', $slots[$items[1]->id]['start_time']);
        $this->assertSame('14:30', $slots[$items[1]->id]['end_time']);
    }

    public function test_reflow_reports_a_day_that_runs_past_the_window(): void
    {
        $itinerary = $this->generateItinerary('alpha', 'bravo', 'charlie');
        $items = $itinerary->days->first()->items;
        $dayId = $items[0]->itinerary_day_id;

        $draft = array_map(function ($item, $index) use ($dayId) {
            return [
                'day_id' => $dayId,
                'sort_order' => $index + 1,
                'destination_id' => $item->destination_id,
            ];
        }, $items->all(), array_keys($items->all()));

        // Four hours apiece cannot fit 08:00-18:00 once travel and lunch are
        // accounted for. Reflow does not refuse them, because the traveller
        // typed the durations; it reports the finish time and the browser warns
        // from it.
        $draft[array_keys($draft)[0]]['start_time'] = '08:00';
        $draft[array_keys($draft)[0]]['end_time'] = '12:00';
        $draft[array_keys($draft)[1]]['start_time'] = '12:00';
        $draft[array_keys($draft)[1]]['end_time'] = '16:00';
        $draft[array_keys($draft)[2]]['start_time'] = '16:00';
        $draft[array_keys($draft)[2]]['end_time'] = '19:00';

        $response = $this->actingAs($this->user)
            ->post(route('itineraries.schedule', $itinerary), ['items' => $draft])
            ->assertOk();

        $slots = $response->json('days.'.$dayId);
        $keys = array_keys($slots);

        $this->assertSame('12:00', $slots[$keys[0]]['end_time']);

        // The second stop would have reached 16:45 straight through lunch, so
        // it is pushed to 13:00.
        $this->assertSame('13:00', $slots[$keys[1]]['start_time']);

        // The third is what carries the day past 18:00.
        $this->assertSame('17:45', $slots[$keys[2]]['start_time']);
        $this->assertSame('20:45', $slots[$keys[2]]['end_time']);
    }

    public function test_the_show_page_offers_the_liked_places_that_are_still_missing(): void
    {
        $itinerary = $this->generateItinerary('alpha');

        $response = $this->actingAs($this->user)
            ->get(route('itineraries.show', $itinerary))
            ->assertOk();

        $response->assertSee('Bravo Falls');
        $response->assertSee('Charlie Ridge');
        $response->assertDontSee('>Alpha Trail</a>', false);
    }

    /**
     * The picker once went through RecommendationService, which only returns
     * places that also clear the travel profile. That made a liked-but-unscored
     * place addable yet invisible, and the empty state then claimed the
     * traveller had liked nothing at all.
     */
    public function test_a_liked_place_is_offered_even_when_it_does_not_fit_the_profile(): void
    {
        $itinerary = $this->generateItinerary('alpha');

        $splurge = Destination::create([
            'name' => 'Splurge Hotel',
            'slug' => 'splurge-hotel',
            'description' => 'A place.',
            'province' => 'Laguna',
            'latitude' => 14.5,
            'longitude' => 121.6,
            // Nothing in the recommendations set will match an economy
            // traveller's profile, but the swipe is what the editor checks.
            'budget_level' => 'luxury',
            'estimated_cost' => 9000,
            'recommended_minutes' => 90,
        ]);

        DestinationSwipe::create([
            'user_id' => $this->user->id,
            'destination_id' => $splurge->id,
            'action' => 'liked',
        ]);

        $this->actingAs($this->user)
            ->get(route('itineraries.show', $itinerary))
            ->assertOk()
            ->assertSee('Splurge Hotel');

        // And the save path accepts it, which is the point.
        $item = $itinerary->days->first()->items->first();

        $this->actingAs($this->user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $item->id => $this->row($item),
                    '-1' => [
                        'day_id' => $item->itinerary_day_id,
                        'sort_order' => 2,
                        'destination_id' => $splurge->id,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('itinerary_items', [
            'destination_id' => $splurge->id,
        ]);
    }

    public function test_an_archived_place_they_liked_is_not_offered(): void
    {
        $itinerary = $this->generateItinerary('alpha');

        Destination::where('slug', 'bravo')->update(['is_active' => false]);

        $this->actingAs($this->user)
            ->get(route('itineraries.show', $itinerary))
            ->assertOk()
            ->assertDontSee('Bravo Falls');
    }

    public function test_the_editor_page_carries_the_contract_the_script_depends_on(): void
    {
        $itinerary = $this->generateItinerary('alpha');
        $item = $itinerary->days->first()->items->first();

        $response = $this->actingAs($this->user)
            ->get(route('itineraries.show', $itinerary))
            ->assertOk();

        $html = $response->getContent();

        // The bundle the editor lives in.
        $response->assertSee('itinerary-editor', false);

        // Every hook itinerary-editor.js looks up. A renamed attribute here is
        // silent in the browser: the script simply finds nothing and every
        // control becomes a dead one.
        foreach ([
            'data-itinerary-editor',
            'data-editor-toggle',
            'data-editor-form',
            'data-editor-read',
            'data-editor-edit',
            'data-editor-cancel',
            'data-editor-notice',
            'data-stop-template',
            'data-day-card',
            'data-stop-list',
            'data-stop',
            'data-key',
            'data-drag-handle',
            'data-move="up"',
            'data-move="down"',
            'data-remove',
            'data-reflow',
            'data-add',
            'data-add-dialog',
            'data-add-confirm',
            'data-add-count',
            'data-day-option',
            'data-travel',
        ] as $hook) {
            $this->assertStringContainsString(
                $hook,
                $html,
                'the editor markup no longer exposes '.$hook.', so the script cannot find it'
            );
        }

        // The field names the payload regex is built around.
        foreach ([
            'items['.$item->id.'][day_id]',
            'items['.$item->id.'][sort_order]',
            'items['.$item->id.'][destination_id]',
            'items['.$item->id.'][start_time]',
            'items['.$item->id.'][end_time]',
            'items['.$item->id.'][estimated_cost]',
            'items['.$item->id.'][note]',
            'items[__KEY__][start_time]',
        ] as $name) {
            $this->assertStringContainsString(
                'name="'.$name.'"',
                $html,
                'the editor form lost the '.$name.' field'
            );
        }

        // The row template must not leak a real day id, because it sits outside
        // the day loop and there is no day to bind to.
        $this->assertStringContainsString('data-field="day-id" value=""', $html);

        $document = new DOMDocument();

        @$document->loadHTML($html);

        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        // chosenDay() reads the checked [data-day-option], so the dialog has to
        // offer exactly one per day and each has to carry that day's id. When
        // these went missing the script silently fell back to the day whose
        // button opened the dialog, and picking a different day did nothing.
        $optionValues = [];

        foreach ($xpath->query('//*[@data-day-option]/@value') as $attribute) {
            $optionValues[] = $attribute->nodeValue;
        }

        $this->assertSame(
            $itinerary->days->pluck('id')->map(fn ($id) => (string) $id)->all(),
            $optionValues,
            'the add dialog must offer one day option per day, valued with that day id'
        );

        // addStop() copies these onto the cloned row. A missing one leaves an
        // "undefined" label or a link back to the destinations index.
        $picker = $xpath->query('//*[@data-destination]');

        $this->assertGreaterThan(0, $picker->length, 'the picker offered nothing to assert on');

        foreach (['name', 'place', 'cost', 'fee', 'url'] as $attribute) {
            $filled = 0;

            foreach ($picker as $checkbox) {
                if (trim((string) $checkbox->getAttribute('data-'.$attribute)) !== '') {
                    $filled++;
                }
            }

            $this->assertSame(
                $picker->length,
                $filled,
                'every picker row must carry a non-empty data-'.$attribute
                .', because addStop copies it onto the stop it creates'
            );
        }
    }

    /**
     * The script shows and hides things with the HTML `hidden` *attribute*
     * (`element.hidden = true`). Tailwind's `hidden` *class* is `display: none`,
     * and an author-level display rule beats the browser's own
     * `[hidden] { display: none }`.
     *
     * So an element carrying both keeps the class's display:none forever, while
     * clearing the attribute does nothing. That is exactly what happened: the
     * edit form had `class="hidden"`, so clicking Edit hid the read view and the
     * form never appeared, leaving a blank page and nothing editable.
     *
     * Nothing about that failure is visible without a browser, so pin it here.
     */
    public function test_elements_the_script_toggles_carry_no_hidden_class(): void
    {
        $itinerary = $this->generateItinerary('alpha');

        $html = $this->actingAs($this->user)
            ->get(route('itineraries.show', $itinerary))
            ->assertOk()
            ->getContent();

        $document = new DOMDocument();

        @$document->loadHTML($html);

        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        $toggled = [
            'data-editor-read',
            'data-editor-edit',
            'data-editor-notice',
            'data-travel',
        ];

        foreach ($toggled as $hook) {
            foreach ($xpath->query('//*[@'.$hook.']') as $element) {
                $classes = preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);

                $this->assertNotContains(
                    'hidden',
                    $classes,
                    $hook.' carries Tailwind\'s hidden class, so clearing the hidden attribute '
                    .'leaves it at display:none and the script can never show it'
                );
            }
        }

        // The edit view starts hidden and the read view does not, or the two are
        // both on screen until the script initialises.
        $this->assertSame(
            1,
            $xpath->query('//*[@data-editor-edit][@hidden]')->length,
            'the edit form must start hidden, or the editor is on screen before anyone clicks Edit'
        );

        $this->assertSame(
            0,
            $xpath->query('//*[@data-editor-read][@hidden]')->length,
            'the read view is what a traveller sees first, so it must not start hidden'
        );
    }

    public function test_the_editor_form_does_not_nest_inside_another_form(): void
    {
        $itinerary = $this->generateItinerary('alpha');

        $html = $this->actingAs($this->user)
            ->get(route('itineraries.show', $itinerary))
            ->assertOk()
            ->getContent();

        $document = new DOMDocument();

        @$document->loadHTML($html);

        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        $this->assertSame(
            0,
            $xpath->query('//form//form')->length,
            'a form is nested inside another, which makes the browser submit to the wrong endpoint'
        );
    }
}
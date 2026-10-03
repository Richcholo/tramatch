<?php

namespace Tests\Feature\Admin;

use App\Jobs\CrawlSourceJob;
use App\Http\Controllers\Admin\ProposalController;
use App\Models\Destination;
use App\Models\DestinationSource;
use App\Models\DestinationSourceCrawl;
use App\Models\DestinationUpdateProposal;
use App\Models\Itinerary;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use App\Services\Crawling\EthicalSourceFetcher;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class SourceCrawlingTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);

        TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => 'economy',
            'group_size' => 1,
            'trip_duration_days' => 1,
        ]);

        return $user;
    }

    private function source(array $attributes = []): DestinationSource
    {
        $this->sequence++;

        $destination = Destination::create([
            'name' => 'Fort Santiago '.$this->sequence,
            'slug' => 'fort-santiago-'.$this->sequence,
            'description' => 'A fort.',
            'province' => 'Manila',
            'latitude' => 14.6,
            'longitude' => 120.9,
            'budget_level' => 'economy',
            'estimated_cost' => 500,
            'recommended_minutes' => 120,
        ]);

        return DestinationSource::create(array_merge([
            'destination_id' => $destination->id,
            'source_name' => 'Intramuros Administration',
            'source_url' => 'https://intramuros.gov.ph/fs/'.$this->sequence,
            'source_type' => 'official_lgu',
            'status' => 'pending',
        ], $attributes));
    }

    public function test_single_crawl_queues_the_source_without_erroring(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $source = $this->source();

        $response = $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.crawl', $source));

        $response->assertRedirect(route('admin.sources.index'));
        $response->assertSessionHasNoErrors();

        Queue::assertPushed(CrawlSourceJob::class, 1);
        Queue::assertPushed(
            CrawlSourceJob::class,
            fn (CrawlSourceJob $job) => $job->sourceId === $source->id
        );

        $this->assertSame('queued', $source->fresh()->status);
    }

    public function test_single_crawl_does_not_queue_a_source_twice(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $source = $this->source([
            'status' => 'queued',
            'last_checked_at' => now(),
        ]);

        $this->assertTrue($source->isQueued());
        $this->assertTrue($source->hasCrawlInFlight());

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.crawl', $source))
            ->assertRedirect(route('admin.sources.index'));

        Queue::assertNothingPushed();
    }

    public function test_bulk_crawl_queues_every_selected_source(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $first = $this->source();
        $second = $this->source();

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.bulk-crawl'), [
                'source_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect(route('admin.sources.index'))
            ->assertSessionHasNoErrors();

        Queue::assertPushed(CrawlSourceJob::class, 2);
        $this->assertSame('queued', $first->fresh()->status);
        $this->assertSame('queued', $second->fresh()->status);
    }

    public function test_bulk_crawl_skips_sources_already_in_flight(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $queued = $this->source([
            'status' => 'queued',
            'last_checked_at' => now(),
        ]);
        $crawling = $this->source([
            'status' => 'crawling',
            'last_checked_at' => now(),
        ]);
        $idle = $this->source();

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.bulk-crawl'), [
                'source_ids' => [$queued->id, $crawling->id, $idle->id],
            ])
            ->assertRedirect(route('admin.sources.index'));

        Queue::assertPushed(CrawlSourceJob::class, 1);
        Queue::assertPushed(
            CrawlSourceJob::class,
            fn (CrawlSourceJob $job) => $job->sourceId === $idle->id
        );
    }

    public function test_bulk_crawl_requires_at_least_one_source(): void
    {
        Queue::fake();

        $admin = $this->admin();

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.bulk-crawl'), ['source_ids' => []])
            ->assertSessionHasErrors('source_ids');

        Queue::assertNothingPushed();
    }

    public function test_queued_row_without_a_timestamp_stays_retryable(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $source = $this->source(['status' => 'queued']);

        $this->assertTrue($source->isCrawlStale());
        $this->assertFalse($source->hasCrawlInFlight());
        $this->assertSame('stale', $source->displayStatus());

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.crawl', $source))
            ->assertRedirect(route('admin.sources.index'));

        Queue::assertPushed(CrawlSourceJob::class, 1);
    }

    public function test_stalled_rows_stay_retryable(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $stale = $this->source([
            'status' => 'crawling',
            'last_checked_at' => now()->subHours(2),
        ]);

        $this->assertTrue($stale->isCrawlStale());
        $this->assertFalse($stale->hasCrawlInFlight());
        $this->assertSame('stale', $stale->displayStatus());

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.crawl', $stale))
            ->assertRedirect(route('admin.sources.index'));

        Queue::assertPushed(CrawlSourceJob::class, 1);
    }

    public function test_status_endpoint_reports_the_queue(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $source = $this->source();

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.crawl', $source));

        $response = $this->actingAs($admin)
            ->getJson(route('admin.sources.status'));

        $response->assertOk();
        $response->assertJsonPath('queued', 1);
        $response->assertJsonPath('counts.queued', 1);
        $response->assertJsonCount(1, 'queue');
    }

    public function test_status_endpoint_does_not_conflict_with_a_source_route(): void
    {
        $admin = $this->admin();
        $source = $this->source();

        $this->actingAs($admin)
            ->get(route('admin.sources.status'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.sources.show', $source))
            ->assertOk();
    }

    public function test_it_filters_by_status(): void
    {
        $admin = $this->admin();

        $this->source();
        $this->source(['status' => 'success']);
        $failed = $this->source([
            'status' => 'failed',
            'error_message' => 'Could not reach the source.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sources.index', ['status' => 'failed']))
            ->assertOk()
            ->assertSee($failed->destination->name)
            ->assertDontSee($this->source()->destination->name, false);
    }

    public function test_stalled_rows_are_filtered_separately_from_crawling(): void
    {
        $admin = $this->admin();

        $crawling = $this->source([
            'status' => 'crawling',
            'last_checked_at' => now(),
        ]);
        $stale = $this->source([
            'status' => 'crawling',
            'last_checked_at' => now()->subHours(3),
        ]);

        $staleList = $this->actingAs($admin)
            ->get(route('admin.sources.index', ['status' => 'stale']))
            ->assertOk();

        $staleList->assertViewHas(
            'sources',
            fn ($paginator) => $paginator->total() === 1
                && $paginator->first()->is($stale)
        );

        $crawlingList = $this->actingAs($admin)
            ->get(route('admin.sources.index', ['status' => 'crawling']))
            ->assertOk();

        $crawlingList->assertViewHas(
            'sources',
            fn ($paginator) => $paginator->total() === 1
                && $paginator->first()->is($crawling)
        );
    }

    public function test_it_searches_destination_source_and_province(): void
    {
        $admin = $this->admin();

        $target = $this->source(['source_name' => 'National Museum']);
        $other = $this->source(['source_name' => 'Some Other Authority']);

        $this->actingAs($admin)
            ->get(route('admin.sources.index', ['search' => 'National Museum']))
            ->assertOk()
            ->assertSee($target->destination->name)
            ->assertDontSee($other->destination->name);

        $this->actingAs($admin)
            ->get(route('admin.sources.index', ['search' => $target->destination->province]))
            ->assertOk()
            ->assertSee($target->destination->name);
    }

    public function test_search_and_status_combine(): void
    {
        $admin = $this->admin();

        $match = $this->source([
            'status' => 'failed',
            'source_name' => 'Baguio Tourism',
        ]);
        $this->source(['status' => 'success', 'source_name' => 'Baguio Tourism']);
        $this->source(['status' => 'failed', 'source_name' => 'Manila Authority']);

        $this->actingAs($admin)
            ->get(route('admin.sources.index', [
                'search' => 'Baguio',
                'status' => 'failed',
            ]))
            ->assertOk()
            ->assertSee($match->destination->name)
            ->assertDontSee('Manila Authority');
    }

    public function test_an_unknown_status_filter_is_ignored(): void
    {
        $admin = $this->admin();
        $source = $this->source();

        $this->actingAs($admin)
            ->get(route('admin.sources.index', ['status' => 'not-a-status']))
            ->assertOk()
            ->assertSee($source->destination->name);
    }

    public function test_pagination_keeps_the_active_filters(): void
    {
        $admin = $this->admin();

        foreach (range(1, 25) as $i) {
            $this->source(['source_name' => 'Bulk Source '.$i]);
        }

        $html = $this->actingAs($admin)
            ->get(route('admin.sources.index', ['status' => 'pending', 'search' => 'Bulk']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('status=pending', (string) $html);
        $this->assertStringContainsString('search=Bulk', (string) $html);
    }

    public function test_search_filters_are_not_nested_inside_the_bulk_form(): void
    {
        $admin = $this->admin();
        $this->source();

        $html = $this->actingAs($admin)
            ->get(route('admin.sources.index'))
            ->assertOk()
            ->getContent();

        $document = new DOMDocument();

        libxml_use_internal_errors(true);
        $document->loadHTML((string) $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);
        $inputs = $xpath->query('//input[@name="search"]');

        $this->assertGreaterThan(0, $inputs->length);

        foreach ($inputs as $input) {
            $form = $input->parentNode;

            while ($form instanceof DOMElement && strtolower($form->nodeName) !== 'form') {
                $form = $form->parentNode;
            }

            $method = $form instanceof DOMElement
                ? strtoupper($form->getAttribute('method') ?: 'GET')
                : 'NONE';

            $this->assertSame(
                'GET',
                $method,
                'the search box must live in a GET form, not the bulk POST form'
            );
        }
    }

    public function test_the_sources_page_contains_no_nested_forms(): void
    {
        $admin = $this->admin();
        $source = $this->source();
        $tag = Tag::create(['name' => 'Beach', 'slug' => 'beach']);
        $source->destination->tags()->attach($tag);

        foreach ([
            route('admin.sources.index'),
            route('admin.sources.show', $source),
        ] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $document = new DOMDocument();

            libxml_use_internal_errors(true);
            $document->loadHTML((string) $html);
            libxml_clear_errors();

            $xpath = new DOMXPath($document);

            foreach ($xpath->query('//form') as $form) {
                for ($node = $form->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
                    $this->assertNotSame(
                        'form',
                        strtolower($node->nodeName),
                        'a form is nested inside another form on '.$url
                    );
                }
            }

            foreach ($xpath->query('//button[@form]') as $button) {
                $this->assertSame(
                    1,
                    $xpath->query('//form[@id="'.$button->getAttribute('form').'"]')->length,
                    'button references a missing form on '.$url
                );
            }
        }
    }

    public function test_every_form_posts_to_a_route_that_accepts_its_method(): void
    {
        $admin = $this->admin();
        $source = $this->source();
        $tag = Tag::create(['name' => 'Beach', 'slug' => 'beach']);
        $source->destination->tags()->attach($tag);
        $destination = $source->destination;
        $destination->update([
            'opening_time' => '08:00',
            'closing_time' => '17:00',
        ]);

        $proposal = $source->proposals()->create([
            'destination_id' => $destination->id,
            'field_name' => 'closing_time',
            'old_value' => '17:00',
            'proposed_value' => '18:00',
            'confidence' => 55,
            'status' => 'pending',
        ]);

        // Not an admin page, but its PATCH form is the same class of trap this
        // guard exists for, so the editor has to appear on the list too.
        $traveller = User::factory()->create();

        $trip = Itinerary::create([
            'user_id' => $traveller->id,
            'title' => 'Guarded trip',
            'area' => 'Laguna',
            'budget_level' => 'economy',
            'trip_duration_days' => 1,
        ]);

        $tripDay = $trip->days()->create(['day_number' => 1]);

        $tripDay->items()->create([
            'destination_id' => $destination->id,
            'sort_order' => 1,
            'start_time' => '09:00',
            'end_time' => '10:30',
            'estimated_cost' => 500,
        ]);

        // Keyed by viewer, because a page belonging to someone else returns 403
        // and would otherwise be skipped, quietly checking less than before.
        $pages = [
            route('admin.sources.index') => $admin,
            route('admin.sources.show', $source) => $admin,
            route('admin.proposals.index') => $admin,
            route('admin.destinations.index') => $admin,
            route('admin.destinations.create') => $admin,
            route('admin.destinations.edit', $destination) => $admin,
            route('profile.edit') => $admin,
            route('preferences.edit') => $admin,
            route('itineraries.show', $trip) => $traveller,
        ];

        $router = app('router');
        $checked = 0;
        $visited = [];

        foreach ($pages as $url => $viewer) {
            $response = $this->actingAs($viewer)->get($url);

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            $visited[] = $url;

            $document = new DOMDocument();

            @$document->loadHTML((string) $response->getContent());

            libxml_clear_errors();

            $xpath = new DOMXPath($document);

            foreach ($xpath->query('//form') as $form) {
                $action = $form->getAttribute('action');

                if ($action === '') {
                    $this->fail('a form on '.$url.' has no action attribute');
                }

                $method = $this->formMethod($xpath, $form);

                try {
                    $route = $router->getRoutes()->match(
                        Request::create($action, $method)
                    );
                } catch (MethodNotAllowedHttpException $exception) {
                    $this->fail(sprintf(
                        'the form on %s sends %s to %s, but that route only accepts %s'
                            .' — a POST form hitting a %s route needs a @method(%s) field',
                        $url,
                        $method,
                        $action,
                        // Symfony returns Allow as a string, not an array of
                        // methods. Casting keeps the message readable instead of
                        // fataling on the one run that needs it.
                        implode('/', (array) ($exception->getHeaders()['Allow'] ?? ['?'])),
                        $method,
                        $method
                    ));
                } catch (NotFoundHttpException $exception) {
                    $this->fail(
                        'the form on '.$url.' posts to '.$action.', which matches no route'
                    );
                }

                $this->assertContains($method, $route->methods());

                $checked++;
            }
        }

        $this->assertGreaterThan(
            0,
            $checked,
            'no forms were checked, so this test proves nothing'
        );

        // The skip above is silent, so a page that stops rendering would drop
        // out of the guard without anyone noticing.
        $this->assertContains(
            route('itineraries.show', $trip),
            $visited,
            'the itinerary editor did not render, so its PATCH form went unchecked'
        );
    }

    private function formMethod(DOMXPath $xpath, DOMElement $form): string
    {
        $override = $xpath->query(
            './/input[@name="_method"]',
            $form
        )->item(0);

        $method = $override instanceof DOMElement
            ? strtoupper(trim($override->getAttribute('value')))
            : strtoupper(trim($form->getAttribute('method') ?: 'GET'));

        return $method === '' ? 'GET' : $method;
    }

    public function test_every_form_on_the_sources_page_is_reachable(): void
    {
        $admin = $this->admin();
        $source = $this->source();

        $html = $this->actingAs($admin)
            ->get(route('admin.sources.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'form="crawl-source-'.$source->id.'"',
            (string) $html
        );

        $this->assertStringContainsString(
            'id="crawl-source-'.$source->id.'"',
            (string) $html
        );
    }

    public function test_the_queue_panel_reports_waiting_and_running_sources(): void
    {
        $admin = $this->admin();

        $queued = $this->source([
            'status' => 'queued',
            'last_checked_at' => now(),
        ]);
        $crawling = $this->source([
            'status' => 'crawling',
            'last_checked_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.sources.status'))
            ->assertOk()
            ->assertJsonPath('queued', 1)
            ->assertJsonPath('active', 1)
            ->assertJsonPath('queue.0.destination', $queued->destination->name)
            ->assertJsonPath('running.0.destination', $crawling->destination->name);

        $this->actingAs($admin)
            ->get(route('admin.sources.index'))
            ->assertOk()
            ->assertSee('Running now')
            ->assertSee('Waiting')
            ->assertSee('php artisan dev');
    }

    public function test_the_page_never_shows_a_worker_error(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $source = $this->source();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\CrawlSourceJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.sources.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($source->destination->name, (string) $html);

        $this->assertStringNotContainsString(
            'Nothing is processing the crawl queue',
            (string) $html
        );

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.crawl', $source))
            ->assertSessionMissing('worker_missing');
    }

    public function test_dispatch_never_claims_the_worker_is_missing(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $source = $this->source();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\CrawlSourceJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.crawl', $source))
            ->assertSessionHas('toast_message');

        $message = (string) session('toast_message');

        $this->assertStringContainsString('Queued', $message);
        $this->assertStringNotContainsString('Nothing is processing', $message);
    }

    public function test_the_status_endpoint_has_no_worker_heuristic(): void
    {
        $admin = $this->admin();

        $payload = $this->actingAs($admin)
            ->getJson(route('admin.sources.status'))
            ->assertOk()
            ->json();

        $this->assertArrayNotHasKey('worker_down', $payload);
        $this->assertArrayNotHasKey('worker_hint', $payload);
        $this->assertArrayNotHasKey('worker_command', $payload);
    }

    public function test_the_detail_page_shows_the_page_that_will_be_crawled(): void
    {
        $admin = $this->admin();
        $source = $this->source();

        $this->actingAs($admin)
            ->get(route('admin.sources.show', $source))
            ->assertOk()
            ->assertSee('Page that will be crawled')
            ->assertSee(e($source->source_url), false);

        $source->update(['details_url' => 'https://example.test/location-info/']);

        $html = $this->actingAs($admin)
            ->get(route('admin.sources.show', $source))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('https://example.test/location-info/', (string) $html);
        $this->assertStringContainsString('Registered source page', (string) $html);

        preg_match(
            '/Page that will be crawled.*?<a[^>]*href="([^"]+)"/s',
            (string) $html,
            $match
        );

        $this->assertSame(
            'https://example.test/location-info/',
            $match[1] ?? null,
            'the headline crawl URL must follow details_url, not source_url'
        );
    }

    public function test_the_list_flags_a_custom_crawl_page(): void
    {
        $admin = $this->admin();
        $source = $this->source(['details_url' => 'https://example.test/rates/']);

        $html = $this->actingAs($admin)
            ->get(route('admin.sources.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Crawls https://example.test/rates/', (string) $html);
    }

    public function test_the_flash_is_rendered_exactly_once(): void
    {
        $admin = $this->admin();
        $this->source();

        foreach ([
            route('admin.dashboard'),
            route('admin.sources.index'),
            route('admin.proposals.index'),
            route('admin.destinations.index'),
        ] as $url) {
            $response = $this->actingAs($admin)
                ->withSession(['status' => 'A single banner message.'])
                ->get($url)
                ->assertOk();

            $this->assertSame(
                1,
                substr_count($response->getContent(), 'A single banner message.'),
                'the flash rendered more than once on '.$url
            );
        }
    }

    public function test_queue_confirmations_use_the_toast_not_the_banner(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $this->source();

        $this->actingAs($admin)
            ->withSession(['toast_message' => 'Queued something for crawling.'])
            ->get(route('admin.sources.index'))
            ->assertOk()
            ->assertSee('data-toast', false)
            ->assertSee('Queued something for crawling.');

        $this->actingAs($admin)
            ->withSession(['toast_message' => 'Queued something for crawling.'])
            ->get(route('admin.sources.show', $this->source()))
            ->assertOk()
            ->assertSee('data-toast', false);
    }

    public function test_the_toast_names_the_destination(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $source = $this->source();

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.crawl', $source))
            ->assertSessionHas('toast_message');

        $message = (string) session('toast_message');

        $this->assertStringContainsString($source->destination->name, $message);
        $this->assertStringContainsString('Queued', $message);
        $this->assertStringNotContainsString('Queued  for', $message);
    }

    public function test_an_already_queued_source_toasts_instead_of_banning(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $source = $this->source([
            'status' => 'queued',
            'last_checked_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.crawl', $source))
            ->assertSessionHas('toast_message')
            ->assertSessionMissing('status');

        $this->assertStringContainsString(
            'already queued',
            (string) session('toast_message')
        );
    }

    public function test_a_successful_crawl_is_logged_with_what_it_found(): void
    {
        Http::fake([
            'bencabmuseum.org/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
            'bencabmuseum.org/*' => Http::response(
                '<html><head><script type="application/ld+json">'.
                json_encode([
                    '@type' => 'TouristAttraction',
                    'openingHoursSpecification' => [
                        '@type' => 'OpeningHoursSpecification',
                        'opens' => '09:00:00',
                        'closes' => '18:00:00',
                    ],
                ]).
                '</script></head><body>Open daily.</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $admin = $this->admin();
        $source = $this->source([
            'source_url' => 'https://bencabmuseum.org/',
            'crawl_delay_seconds' => 0,
        ]);

        app(EthicalSourceFetcher::class)->fetch($source, 1);

        $crawl = DestinationSourceCrawl::latest('id')->firstOrFail();

        $this->assertSame('success', $crawl->outcome);
        $this->assertSame(1, $crawl->attempt);
        $this->assertSame(200, $crawl->http_status);
        $this->assertGreaterThan(0, $crawl->bytes);
        $this->assertNull($crawl->content_changed, 'a first crawl has nothing to compare against');
        $this->assertSame(
            ['opening_time', 'closing_time', 'operating_status'],
            $crawl->extracted_fields
        );
        $this->assertSame(3, $crawl->proposals_created);
        $this->assertNotNull($crawl->duration_ms);
        $this->assertTrue($crawl->producedData());
    }

    public function test_a_re_crawl_records_whether_the_page_changed(): void
    {
        $html = '<html><head><script type="application/ld+json">'.
            json_encode([
                '@type' => 'TouristAttraction',
                'offers' => ['price' => '150.00', 'priceCurrency' => 'PHP'],
            ]).
            '</script></head><body>Admission Php 150</body></html>';

        Http::fake([
            'bencabmuseum.org/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
            'bencabmuseum.org/*' => Http::response($html, 200, ['Content-Type' => 'text/html']),
        ]);

        $source = $this->source([
            'source_url' => 'https://bencabmuseum.org/',
            'crawl_delay_seconds' => 0,
        ]);

        $fetcher = app(EthicalSourceFetcher::class);
        $fetcher->fetch($source, 1);
        $fetcher->fetch($source->fresh(), 1);

        $second = DestinationSourceCrawl::latest('id')->firstOrFail();

        $this->assertFalse($second->content_changed, 'identical page must report no change');
        $this->assertSame(
            0,
            $second->proposals_created,
            'a repeat proposal must not be counted as a new one'
        );
        $this->assertFalse($second->producedData());
    }

    public function test_a_blocked_source_is_still_logged(): void
    {
        Http::fake([
            'bencabmuseum.org/robots.txt' => Http::response(
                "User-agent: TraMatchBot\nDisallow: /",
                200
            ),
        ]);

        $source = $this->source([
            'source_url' => 'https://bencabmuseum.org/',
            'crawl_delay_seconds' => 0,
        ]);

        try {
            app(EthicalSourceFetcher::class)->fetch($source, 1);
        } catch (Throwable) {
        }

        $crawl = DestinationSourceCrawl::latest('id')->firstOrFail();

        $this->assertSame('blocked', $crawl->outcome);
        $this->assertNotNull($crawl->error_message);
        $this->assertSame(0, $crawl->proposals_created);
    }

    public function test_the_crawl_history_is_shown_on_the_detail_page(): void
    {
        $admin = $this->admin();
        $source = $this->source();

        $source->crawls()->create([
            'attempt' => 1,
            'outcome' => 'success',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour()->addSeconds(11),
            'duration_ms' => 11000,
            'http_status' => 200,
            'bytes' => 25928,
            'extracted_fields' => ['opening_time'],
            'proposals_created' => 0,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sources.show', $source))
            ->assertOk()
            ->assertSee('Crawl history')
            ->assertSee('Fetched')
            ->assertSee('11.0s')
            ->assertSee('opening_time')
            ->assertSee('already proposed or unchanged');
    }

    public function test_a_source_that_never_produced_anything_is_flagged(): void
    {
        $admin = $this->admin();
        $source = $this->source();

        $source->crawls()->create([
            'attempt' => 1,
            'outcome' => 'success',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
            'http_status' => 200,
            'requested_url' => 'https://example.test/',
            'final_url' => 'https://example.test/',
            'bytes' => 5000,
            'extracted_fields' => [],
            'proposals_created' => 0,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sources.index'))
            ->assertOk()
            ->assertSee('Crawled the site homepage')
            ->assertSee('Set a details URL');
    }

    public function test_a_readable_page_with_no_data_says_so_without_blaming_the_url(): void
    {
        $admin = $this->admin();
        $source = $this->source();

        $source->crawls()->create([
            'attempt' => 1,
            'outcome' => 'success',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
            'http_status' => 200,
            'requested_url' => 'https://example.test/tickets',
            'final_url' => 'https://example.test/tickets',
            'bytes' => 5000,
            'extracted_fields' => [],
            'proposals_created' => 0,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sources.index'))
            ->assertOk()
            ->assertSee('readable but published no fee or hours data')
            ->assertDontSee('Set a details URL');
    }

    public function test_a_redirect_is_followed_instead_of_failing_the_crawl(): void
    {
        Http::fake([
            'campjohnhay.ph/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
            'campjohnhay.ph/*' => Http::response('', 301, [
                'Location' => 'https://johnhayhotels.com/location-info/',
            ]),
            'johnhayhotels.com/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
            'johnhayhotels.com/*' => Http::response(
                '<html><body>Admission Php 150</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $source = $this->source([
            'source_url' => 'https://campjohnhay.ph/',
            'crawl_delay_seconds' => 0,
        ]);

        app(EthicalSourceFetcher::class)->fetch($source, 1);

        $crawl = DestinationSourceCrawl::latest('id')->firstOrFail();

        $this->assertSame('success', $crawl->outcome);
        $this->assertSame(200, $crawl->http_status);
        $this->assertSame('https://campjohnhay.ph/', $crawl->requested_url);
        $this->assertSame(
            'https://johnhayhotels.com/location-info/',
            $crawl->final_url
        );
        $this->assertTrue($crawl->redirected());
    }

    public function test_a_redirect_to_a_url_robots_disallows_is_refused(): void
    {
        Http::fake([
            'campjohnhay.ph/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
            'campjohnhay.ph/*' => Http::response('', 301, [
                'Location' => 'https://private.example.test/tour/',
            ]),
            'private.example.test/robots.txt' => Http::response(
                "User-agent: *\nDisallow: /tour",
                200
            ),
            'private.example.test/*' => Http::response(
                '<html><body>secret</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $source = $this->source([
            'source_url' => 'https://campjohnhay.ph/',
            'crawl_delay_seconds' => 0,
        ]);

        try {
            app(EthicalSourceFetcher::class)->fetch($source, 1);
            $this->fail('the redirect to a disallowed path should have been refused');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('robots.txt disallows', $exception->getMessage());
        }

        $crawl = DestinationSourceCrawl::latest('id')->firstOrFail();

        $this->assertSame('failed', $crawl->outcome);
        $this->assertSame(0, $crawl->proposals_created);
    }

    public function test_an_unreadable_robots_txt_is_not_reported_as_a_disallow(): void
    {
        Http::fake([
            'npdc.gov.ph/robots.txt' => Http::response('<html>forbidden</html>', 403),
            'npdc.gov.ph/*' => Http::response(
                '<html><body>Rizal Park</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $source = $this->source([
            'source_url' => 'https://npdc.gov.ph/',
            'crawl_delay_seconds' => 0,
        ]);

        app(EthicalSourceFetcher::class)->fetch($source, 1);

        $crawl = DestinationSourceCrawl::latest('id')->firstOrFail();

        $this->assertSame(
            'success',
            $crawl->outcome,
            'a 403 on robots.txt means the policy is unknown, not that the path is forbidden'
        );
        $this->assertNotSame('blocked', $source->fresh()->status);
    }

    public function test_a_host_that_does_not_resolve_is_marked_dead_and_not_retried(): void
    {
        $pageRequests = 0;

        Http::fake(function ($request) use (&$pageRequests) {
            if (!str_ends_with($request->url(), '/robots.txt')) {
                $pageRequests++;
            }

            throw new ConnectionException(
                'cURL error 6: Could not resolve host: gone.example.test'
            );
        });

        $source = $this->source([
            'source_url' => 'https://gone.example.test/',
            'crawl_delay_seconds' => 0,
        ]);

        try {
            app(EthicalSourceFetcher::class)->fetch($source, 3);
            $this->fail('a dead host should not resolve into a snapshot');
        } catch (ConnectionException) {
            $this->assertSame(
                1,
                $pageRequests,
                'DNS failure is not transient, so the page must not be retried three times'
            );
        }

        $source->refresh();

        $this->assertSame('failed', $source->status);
        $this->assertSame(DestinationSource::DEAD, $source->fetchability);
        $this->assertStringContainsString('does not resolve', (string) $source->error_message);
        $this->assertStringNotContainsString('curl.se', (string) $source->error_message);
    }

    public function test_it_proposes_nothing_when_the_value_already_matches(): void
    {
        Http::fake([
            'bencabmuseum.org/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
            'bencabmuseum.org/*' => Http::response(
                '<html><body>Open daily 9:00 AM to 6:00 PM.</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $destination = Destination::create([
            'name' => 'BenCab Museum',
            'slug' => 'bencab-museum-hours-match',
            'description' => 'A museum.',
            'province' => 'Benguet',
            'latitude' => 16.4,
            'longitude' => 120.6,
            'budget_level' => 'moderate',
            'estimated_cost' => 500,
            'recommended_minutes' => 120,
            'opening_time' => '09:00',
            'closing_time' => '18:00',
        ]);

        $source = $this->source([
            'destination_id' => $destination->id,
            'source_url' => 'https://bencabmuseum.org/',
            'crawl_delay_seconds' => 0,
        ]);

        app(EthicalSourceFetcher::class)->fetch($source, 1);

        $proposals = DestinationUpdateProposal::where('destination_id', $destination->id)
            ->pluck('field_name')
            ->all();

        $this->assertSame(
            [],
            array_values(array_intersect($proposals, ['opening_time', 'closing_time'])),
            'a proposal that changes nothing is noise, and 09:00 equals 09:00:00'
        );
    }

    public function test_it_never_proposes_narrower_hours_for_an_ungated_destination(): void
    {
        Http::fake([
            'sagada.gov.ph/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
            'sagada.gov.ph/*' => Http::response(
                '<html><body>Tourist office 8:00 AM to 10:00 PM daily.</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $destination = Destination::create([
            'name' => 'Sagada',
            'slug' => 'sagada-always-open',
            'description' => 'A municipality.',
            'province' => 'Mountain Province',
            'latitude' => 17.1,
            'longitude' => 121.6,
            'budget_level' => 'economy',
            'estimated_cost' => 500,
            'recommended_minutes' => 120,
            'opening_time' => '00:00',
            'closing_time' => '23:59',
            'hours_kind' => Destination::HOURS_ALWAYS_OPEN,
        ]);

        $source = $this->source([
            'destination_id' => $destination->id,
            'source_url' => 'https://sagada.gov.ph/',
            'crawl_delay_seconds' => 0,
        ]);

        app(EthicalSourceFetcher::class)->fetch($source, 1);

        $proposals = DestinationUpdateProposal::where('destination_id', $destination->id)
            ->pluck('field_name')
            ->all();

        $this->assertSame(
            [],
            array_values(array_intersect($proposals, ['opening_time', 'closing_time'])),
            'a curated ungated value must not be narrowed by a text snippet'
        );
    }

    public function test_it_never_flattens_curated_per_day_hours_into_one_pair(): void
    {
        Http::fake([
            'intramuros.gov.ph/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
            'intramuros.gov.ph/*' => Http::response(
                '<html><body>Open 4:00 PM to 6:00 PM daily.</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $destination = Destination::create([
            'name' => 'Fort Santiago',
            'slug' => 'fort-santiago-per-day',
            'description' => 'A fort.',
            'province' => 'Metro Manila',
            'latitude' => 14.6,
            'longitude' => 120.9,
            'budget_level' => 'economy',
            'estimated_cost' => 500,
            'recommended_minutes' => 120,
            'daily_hours' => [
                'monday' => ['open' => '08:00', 'close' => '22:00'],
                'saturday' => ['open' => '06:00', 'close' => '22:00'],
            ],
            'hours_kind' => Destination::HOURS_PER_DAY,
        ]);

        $source = $this->source([
            'destination_id' => $destination->id,
            'source_url' => 'https://intramuros.gov.ph/fs/',
            'crawl_delay_seconds' => 0,
        ]);

        app(EthicalSourceFetcher::class)->fetch($source, 1);

        $proposals = DestinationUpdateProposal::where('destination_id', $destination->id)
            ->pluck('field_name')
            ->all();

        $this->assertSame(
            [],
            array_values(array_intersect($proposals, ['opening_time', 'closing_time'])),
            'one text pair would erase a deliberate weekday/weekend split'
        );
    }

    public function test_a_cloudflare_challenge_is_named_as_such_not_as_a_crawler_policy(): void
    {
        Http::fake([
            'mwss.gov.ph/robots.txt' => Http::response('<html>forbidden</html>', 403),
            'mwss.gov.ph/*' => Http::response(
                '<html><head><title>Just a moment...</title></head><body>'
                .'Please enable JavaScript and cookies to continue.</body></html>',
                403,
                ['Server' => 'cloudflare']
            ),
        ]);

        $source = $this->source([
            'source_url' => 'https://www.mwss.gov.ph/la-mesa-ecopark/',
            'crawl_delay_seconds' => 0,
        ]);

        try {
            app(EthicalSourceFetcher::class)->fetch($source, 1);
            $this->fail('a 403 should fail the crawl');
        } catch (RuntimeException) {
            $this->assertNotSame('blocked', $source->fresh()->status);
        }

        $source->refresh();

        $this->assertSame('failed', $source->status);
        $this->assertSame(DestinationSource::BOT_WALL, $source->fetchability);
        $this->assertStringContainsString(
            'Cloudflare challenge',
            (string) $source->fetchability_note,
            'a challenge page is not a statement about crawlers'
        );
        $this->assertStringContainsString(
            'JavaScript challenge',
            (string) $source->fetchability_note
        );
    }

    public function test_an_azure_waf_block_is_named_as_a_firewall(): void
    {
        Http::fake([
            'denr.gov.ph/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
            'denr.gov.ph/*' => Http::response(
                '<html><body>Azure WAF</body></html>',
                403,
                ['Server' => 'Microsoft-IIS/10.0']
            ),
        ]);

        $source = $this->source([
            'source_url' => 'https://calabarzon.denr.gov.ph/',
            'crawl_delay_seconds' => 0,
        ]);

        try {
            app(EthicalSourceFetcher::class)->fetch($source, 1);
            $this->fail('a 403 should fail the crawl');
        } catch (RuntimeException) {
            $this->assertStringContainsString(
                'Azure web application firewall',
                (string) $source->fresh()->fetchability_note
            );
        }
    }

    public function test_a_gone_page_is_recorded_as_dead(): void
    {
        Http::fake([
            'example.test/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
            'example.test/*' => Http::response('gone', 404),
        ]);

        $source = $this->source([
            'source_url' => 'https://example.test/old-page',
            'crawl_delay_seconds' => 0,
        ]);

        try {
            app(EthicalSourceFetcher::class)->fetch($source, 1);
            $this->fail('a 404 should fail the crawl');
        } catch (RuntimeException) {
            $this->assertSame(DestinationSource::DEAD, $source->fresh()->fetchability);
        }
    }

    public function test_a_successful_crawl_clears_a_stale_dead_verdict(): void
    {
        Http::fake([
            'example.test/robots.txt' => Http::response("User-agent: *\nAllow: /", 200),
            'example.test/*' => Http::response(
                '<html><body>Back online.</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $source = $this->source([
            'source_url' => 'https://example.test/',
            'crawl_delay_seconds' => 0,
            'fetchability' => DestinationSource::DEAD,
            'fetchability_note' => 'The host does not resolve.',
        ]);

        app(EthicalSourceFetcher::class)->fetch($source->fresh(), 1);

        $source->refresh();

        $this->assertSame('success', $source->status);
        $this->assertNull(
            $source->fetchability,
            'a 200 disproves a stale dead verdict, so the source must not stay flagged'
        );
        $this->assertNull($source->fetchability_note);
    }

    public function test_the_bulk_action_approves_the_selected_proposals(): void
    {
        $admin = $this->admin();
        $source = $this->source();
        $destination = $source->destination;

        $destination->update([
            'opening_time' => '08:00',
            'closing_time' => '17:00',
        ]);

        $first = $source->proposals()->create([
            'destination_id' => $destination->id,
            'field_name' => 'closing_time',
            'old_value' => '17:00',
            'proposed_value' => '18:00',
            'confidence' => 55,
            'status' => 'pending',
        ]);

        $second = $source->proposals()->create([
            'destination_id' => $destination->id,
            'field_name' => 'operating_status',
            'old_value' => 'unknown',
            'proposed_value' => 'open',
            'confidence' => 45,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.proposals.bulk'), [
                'proposal_ids' => [$first->id, $second->id],
                'action' => 'approve',
            ])
            ->assertRedirect(route('admin.proposals.index'));

        $this->assertSame('approved', $first->fresh()->status);
        $this->assertSame('approved', $second->fresh()->status);
        $this->assertSame('18:00', $destination->fresh()->closing_time);
        $this->assertSame($admin->id, $first->fresh()->reviewed_by);
    }

    public function test_the_bulk_action_rejects_the_selected_proposals(): void
    {
        $admin = $this->admin();
        $source = $this->source();

        $source->destination->update([
            'opening_time' => '08:00',
            'closing_time' => '17:00',
        ]);

        $proposal = $source->proposals()->create([
            'destination_id' => $source->destination_id,
            'field_name' => 'closing_time',
            'old_value' => '17:00',
            'proposed_value' => '18:00',
            'confidence' => 55,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.proposals.bulk'), [
                'proposal_ids' => [$proposal->id],
                'action' => 'reject',
            ])
            ->assertRedirect(route('admin.proposals.index'));

        $this->assertSame('rejected', $proposal->fresh()->status);
        $this->assertSame(
            '17:00',
            $source->destination->fresh()->closing_time,
            'rejecting a proposal must not change the destination'
        );
    }

    public function test_the_bulk_form_renders_a_method_override_the_route_accepts(): void
    {
        $admin = $this->admin();
        $source = $this->source();

        $source->proposals()->create([
            'destination_id' => $source->destination_id,
            'field_name' => 'closing_time',
            'old_value' => '17:00',
            'proposed_value' => '18:00',
            'confidence' => 55,
            'status' => 'pending',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.proposals.index'))
            ->assertOk()
            ->getContent();

        $document = new DOMDocument();

        @$document->loadHTML((string) $html);

        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        $form = $xpath->query(
            '//form[contains(@action, "/admin/proposals/bulk")]'
        )->item(0);

        $this->assertInstanceOf(
            DOMElement::class,
            $form,
            'the proposals page must have a bulk form'
        );

        $override = $xpath->query('.//input[@name="_method"]', $form)->item(0);

        $this->assertInstanceOf(
            DOMElement::class,
            $override,
            'the bulk form posts to a PATCH route, so it needs a @method field'
        );

        $this->assertSame('PATCH', strtoupper($override->getAttribute('value')));
    }

    public function test_select_all_queues_every_source_across_pages(): void
    {
        Queue::fake();

        $admin = $this->admin();

        foreach (range(1, 45) as $i) {
            $this->source(['source_name' => 'Source '.$i]);
        }

        $this->assertSame(20, 45 % 20 ? 20 : 20);

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.bulk-crawl'), [
                'select_all' => 1,
                'filter_search' => '',
                'filter_status' => '',
            ])
            ->assertRedirect(route('admin.sources.index'))
            ->assertSessionHasNoErrors();

        Queue::assertPushed(CrawlSourceJob::class, 45);

        $this->assertSame(
            45,
            DestinationSource::where('status', 'queued')->count(),
            'sources on every page must be queued, not just the visible ones'
        );
    }

    public function test_select_all_respects_the_active_filter(): void
    {
        Queue::fake();

        $admin = $this->admin();

        foreach (range(1, 30) as $i) {
            $this->source([
                'source_name' => $i <= 25 ? 'Keep' : 'Drop',
            ]);
        }

        $this->actingAs($admin)
            ->from(route('admin.sources.index', ['search' => 'Keep']))
            ->post(route('admin.sources.bulk-crawl'), [
                'select_all' => 1,
                'filter_search' => 'Keep',
                'filter_status' => '',
            ])
            ->assertRedirect(route('admin.sources.index', ['search' => 'Keep']));

        Queue::assertPushed(CrawlSourceJob::class, 25);

        $this->assertSame(0, DestinationSource::where('source_name', 'Drop')
            ->where('status', 'queued')
            ->count());
    }

    public function test_select_all_with_no_matches_is_reported_not_queued(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $this->source();

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.bulk-crawl'), [
                'select_all' => 1,
                'filter_search' => 'nothing-matches-this',
                'filter_status' => '',
            ])
            ->assertSessionHas('toast_message');

        Queue::assertNothingPushed();
    }

    public function test_select_all_still_skips_sources_already_in_flight(): void
    {
        Queue::fake();

        $admin = $this->admin();

        $this->source(['status' => 'queued', 'last_checked_at' => now()]);
        $idle = $this->source();

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.bulk-crawl'), [
                'select_all' => 1,
                'filter_search' => '',
                'filter_status' => '',
            ]);

        Queue::assertPushed(CrawlSourceJob::class, 1);
        Queue::assertPushed(
            CrawlSourceJob::class,
            fn (CrawlSourceJob $job) => $job->sourceId === $idle->id
        );
    }

    public function test_the_page_carries_the_cross_page_selection_controls(): void
    {
        $admin = $this->admin();
        $this->source();

        $html = $this->actingAs($admin)
            ->get(route('admin.sources.index'))
            ->assertOk()
            ->getContent();

        foreach ([
            'data-select-all-input',
            'data-selection-banner',
            'data-select-all-matching',
            'name="filter_search"',
            'name="filter_status"',
        ] as $needle) {
            $this->assertStringContainsString($needle, (string) $html);
        }
    }

    public function test_dev_command_registers_a_bounded_queue_worker(): void
    {
        $commands = collect(DevCommands::commands())->keyBy('name');

        $queue = $commands->get('queue');

        $this->assertNotNull($queue, 'artisan dev must register a queue process');
        $this->assertStringContainsString('queue:listen', $queue['command']);
        $this->assertStringNotContainsString(
            '--timeout=0',
            $queue['command'],
            'the dev worker must not run without a timeout'
        );
    }

    public function test_only_hours_and_fees_may_be_approved(): void
    {
        $approved = (new ReflectionClass(ProposalController::class))
            ->getConstant('APPROVED_FIELDS');

        $this->assertSame([
            'entrance_fee',
            'entrance_fee_display',
            'opening_time',
            'closing_time',
            'operating_status',
        ], $approved);

        foreach (['name', 'description', 'latitude', 'longitude'] as $field) {
            $this->assertNotContains(
                $field,
                $approved,
                $field.' is owned by the CSV seeder and must not be crawler writable'
            );
        }
    }

    public function test_admin_pages_link_to_the_public_destination_page(): void
    {
        $admin = $this->admin();
        $source = $this->source();

        $publicUrl = route('destinations.show', $source->destination);

        $this->assertStringContainsString('/destinations/', $publicUrl);

        foreach ([
            route('admin.sources.index'),
            route('admin.sources.show', $source),
        ] as $url) {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk()
                ->assertSee('View public page')
                ->assertSee($publicUrl, false);
        }
    }

    public function test_the_crawl_page_can_be_overridden(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $source = $this->source();

        $this->assertSame($source->source_url, $source->crawlUrl());

        $this->actingAs($admin)
            ->from(route('admin.sources.show', $source))
            ->patch(route('admin.sources.update', $source), [
                'details_url' => 'https://bencabmuseum.org/location-info/',
            ])
            ->assertRedirect(route('admin.sources.show', $source))
            ->assertSessionHasNoErrors();

        $source->refresh();

        $this->assertSame(
            'https://bencabmuseum.org/location-info/',
            $source->crawlUrl()
        );

        $this->actingAs($admin)
            ->patch(route('admin.sources.update', $source), ['details_url' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($source->fresh()->details_url);
        $this->assertSame($source->source_url, $source->fresh()->crawlUrl());
    }

    public function test_the_crawl_page_must_be_a_valid_url(): void
    {
        $admin = $this->admin();
        $source = $this->source();

        $this->actingAs($admin)
            ->from(route('admin.sources.show', $source))
            ->patch(route('admin.sources.update', $source), [
                'details_url' => 'not a url',
            ])
            ->assertSessionHasErrors('details_url');
    }

    public function test_guests_cannot_reach_the_crawl_routes(): void
    {
        $source = $this->source();

        $this->post(route('admin.sources.crawl', $source))->assertRedirect('/login');
        $this->post(route('admin.sources.bulk-crawl'))->assertRedirect('/login');
        $this->get(route('admin.sources.index'))->assertRedirect('/login');
    }

    public function test_non_admins_cannot_reach_the_crawl_routes(): void
    {
        Queue::fake();

        $user = User::factory()->create(['role' => 'user']);
        $source = $this->source();

        $this->actingAs($user)
            ->post(route('admin.sources.crawl', $source))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }
}

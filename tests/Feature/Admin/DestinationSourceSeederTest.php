<?php

namespace Tests\Feature\Admin;

use App\Models\DestinationSource;
use Database\Seeders\DestinationSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationSourceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cited_url_becomes_the_crawl_target(): void
    {
        $this->refreshDatabaseWithCatalogue();

        $source = DestinationSource::whereHas(
            'destination',
            fn ($query) => $query->where('slug', 'bencab-museum')
        )->firstOrFail();

        $this->assertSame(
            $source->destination->hours_source_url,
            $source->crawlUrl(),
            'the crawler should read the page the hours were actually verified against'
        );
    }

    public function test_it_never_crawls_wikipedia(): void
    {
        $this->refreshDatabaseWithCatalogue();

        $wikipedia = DestinationSource::whereHas(
            'destination',
            fn ($query) => $query->where('name', 'Burnham Park')
        )->firstOrFail();

        $this->assertStringContainsString(
            'wikipedia.org',
            (string) $wikipedia->destination->hours_source_url,
            'this row is cited to Wikipedia on purpose, to prove the exclusion'
        );

        $this->assertNotSame(
            $wikipedia->destination->hours_source_url,
            $wikipedia->crawlUrl(),
            'Wikipedia must not become a crawl target'
        );
    }

    public function test_every_seeded_source_has_a_crawl_target(): void
    {
        $this->refreshDatabaseWithCatalogue();

        $missing = DestinationSource::query()
            ->where(function ($query) {
                $query->whereNull('details_url')
                    ->orWhere('details_url', '');
            })
            ->whereHas('destination', fn ($q) => $q->whereNull('hours_source_url'))
            ->pluck('id')
            ->all();

        $this->assertSame(
            [],
            $missing,
            'a source with neither an explicit details URL nor a cited hours URL has no target'
        );
    }

    private function refreshDatabaseWithCatalogue(): void
    {
        $this->seed(\Database\Seeders\TagSeeder::class);
        $this->seed(\Database\Seeders\LuzonLocationsCsvSeeder::class);
        $this->seed(DestinationSourceSeeder::class);
    }
}

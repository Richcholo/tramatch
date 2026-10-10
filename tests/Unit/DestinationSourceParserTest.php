<?php

namespace Tests\Unit;

use App\Services\Crawling\DestinationSourceParser;
use Tests\TestCase;

class DestinationSourceParserTest extends TestCase
{
    private function parser(): DestinationSourceParser
    {
        return app(DestinationSourceParser::class);
    }

    private function page(string $body): string
    {
        return '<!doctype html><html><head><title>Test</title></head><body>'
            .$body
            .'</body></html>';
    }

    private function jsonLd(array $data): string
    {
        return '<script type="application/ld+json">'
            .json_encode($data, JSON_UNESCAPED_SLASHES)
            .'</script>';
    }

    public function test_it_extracts_a_php_offer_as_an_entrance_fee(): void
    {
        $html = $this->page($this->jsonLd([
            '@type' => 'TouristAttraction',
            'name' => 'Fort Santiago',
            'offers' => [
                '@type' => 'Offer',
                'price' => '150.00',
                'priceCurrency' => 'PHP',
            ],
        ]));

        $values = $this->parser()->extract($html);

        $this->assertArrayHasKey('entrance_fee', $values);
        $this->assertSame('150.00', $values['entrance_fee']['value']);
        $this->assertArrayHasKey('entrance_fee_display', $values);
        $this->assertStringContainsString('150', $values['entrance_fee_display']['value']);
    }

    public function test_it_ignores_offers_in_a_currency_other_than_php(): void
    {
        $html = $this->page($this->jsonLd([
            '@type' => 'TouristAttraction',
            'offers' => ['price' => '3.50', 'priceCurrency' => 'USD'],
        ]));

        $this->assertSame([], $this->parser()->extract($html));
    }

    public function test_it_ignores_a_non_numeric_offer(): void
    {
        $html = $this->page($this->jsonLd([
            '@type' => 'TouristAttraction',
            'offers' => ['price' => 'Free', 'priceCurrency' => 'PHP'],
        ]));

        $this->assertSame([], $this->parser()->extract($html));
    }

    public function test_it_extracts_opening_hours(): void
    {
        $html = $this->page($this->jsonLd([
            '@type' => 'TouristAttraction',
            'openingHoursSpecification' => [
                '@type' => 'OpeningHoursSpecification',
                'opens' => '08:30:00',
                'closes' => '17:00:00',
            ],
        ]));

        $values = $this->parser()->extract($html);

        $this->assertSame('08:30', $values['opening_time']['value']);
        $this->assertSame('17:00', $values['closing_time']['value']);
        $this->assertSame('open', $values['operating_status']['value']);
    }

    public function test_it_never_proposes_csv_owned_fields(): void
    {
        $html = $this->page(
            '<meta name="description" content="A page description.">'
            .'<meta property="og:description" content="An og description.">'
            .$this->jsonLd([
                '@type' => 'TouristAttraction',
                'name' => 'Fort Santiago',
                'description' => 'Structured description.',
                'geo' => ['latitude' => 14.6, 'longitude' => 120.9],
                'offers' => ['price' => '150.00', 'priceCurrency' => 'PHP'],
            ])
        );

        $values = $this->parser()->extract($html);

        $this->assertArrayNotHasKey('name', $values);
        $this->assertArrayNotHasKey('description', $values);
        $this->assertArrayNotHasKey('latitude', $values);
        $this->assertArrayNotHasKey('longitude', $values);
        $this->assertArrayHasKey('entrance_fee', $values);
    }

    public function test_it_ignores_event_sidebar_entities(): void
    {
        $html = $this->page($this->jsonLd([
            ['@type' => 'Event', 'name' => 'Pasig River Esplanade (Bazaar)'],
            ['@type' => 'Event', 'name' => 'Free Walking Tour'],
        ]));

        $this->assertSame([], $this->parser()->extract($html));
    }

    public function test_it_ignores_an_event_price_rather_than_attributing_it(): void
    {
        $html = $this->page($this->jsonLd([
            '@type' => 'Event',
            'name' => 'Bazaar',
            'offers' => ['price' => '250.00', 'priceCurrency' => 'PHP'],
        ]));

        $this->assertSame(
            [],
            $this->parser()->extract($html),
            'an event price must never be proposed as a destination entrance fee'
        );
    }

    public function test_it_reads_entities_from_a_json_ld_graph(): void
    {
        $html = $this->page($this->jsonLd([
            '@context' => 'https://schema.org',
            '@graph' => [
                '@type' => 'WebSite',
                'name' => 'Intramuros Administration',
            ],
        ]));

        $this->assertSame([], $this->parser()->extract($html));

        $graph = $this->page($this->jsonLd([
            '@context' => 'https://schema.org',
            '@graph' => [
                '@type' => 'TouristAttraction',
                'offers' => ['price' => '75', 'priceCurrency' => 'PHP'],
            ],
        ]));

        $values = $this->parser()->extract($graph);

        $this->assertSame('75.00', $values['entrance_fee']['value']);
    }

    public function test_it_reads_opening_hours_from_page_text(): void
    {
        $html = $this->page(
            '<p>INFORMATION Tuesdays to Sundays 9:00am to 6:00pm '.
            '(last entry at 5:30pm) Closed Mondays.</p>'
        );

        $values = $this->parser()->extract($html);

        $this->assertSame('09:00', $values['opening_time']['value']);
        $this->assertSame('18:00', $values['closing_time']['value']);
        $this->assertSame('open', $values['operating_status']['value']);
    }

    public function test_it_reads_hours_without_minutes_or_in_24_hour_form(): void
    {
        $this->assertSame(
            '09:00',
            $this->parser()->extract($this->page('<p>From 9am to 6pm daily</p>'))['opening_time']['value']
        );

        $this->assertSame(
            '18:00',
            $this->parser()->extract($this->page('<p>09:00 to 18:00</p>'))['closing_time']['value']
        );
    }

    public function test_it_ignores_ranges_of_bare_numbers(): void
    {
        $html = $this->page('<p>Guides for children aged 6 to 12 in groups of 10.</p>');

        $this->assertSame([], $this->parser()->extract($html));
    }

    public function test_it_ignores_phone_numbers(): void
    {
        $html = $this->page('<p>Call 0917 123 4567 or 0917-1234567 for details.</p>');

        $this->assertSame([], $this->parser()->extract($html));
    }

    public function test_it_ignores_a_departure_slot_that_is_too_short_to_be_opening_hours(): void
    {
        $html = $this->page(
            '<p>Riders depart daily at 7:00 AM to 8:00 AM. Please arrive 30 minutes early.</p>'
        );

        $this->assertSame(
            [],
            $this->parser()->extract($html),
            'a one-hour slot is a departure time, not a gate schedule'
        );
    }

    public function test_it_proposes_nothing_when_two_hours_windows_disagree(): void
    {
        $html = $this->page(
            '<p>Open 8:00 AM - 5:00 PM and Saturdays 9am to 4pm.</p>'
        );

        $this->assertSame(
            [],
            $this->parser()->extract($html),
            'conflicting hours must be left to a human'
        );
    }

    public function test_it_returns_the_fee_block_for_a_human_to_compare(): void
    {
        $html = $this->page(
            '<p>Admission to the Museum: Php 200 – General admission '.
            'Php 150 – Senior/PWD with valid ID. EcoTrail tour: Php 200.</p>'
        );

        $notice = $this->parser()->feeNotice($html);

        $this->assertStringContainsString('Php 200', (string) $notice);
        $this->assertStringContainsString('Senior/PWD', (string) $notice);
    }

    public function test_it_returns_no_fee_notice_when_a_page_has_no_prices(): void
    {
        $html = $this->page('<p>Opening hours 9:00am to 6:00pm. Closed Mondays.</p>');

        $this->assertNull($this->parser()->feeNotice($html));
    }

    public function test_it_returns_nothing_for_a_page_without_structured_data(): void
    {
        $html = $this->page('<h1>Fort Santiago</h1><p>Open daily.</p>');

        $this->assertSame([], $this->parser()->extract($html));
    }
}

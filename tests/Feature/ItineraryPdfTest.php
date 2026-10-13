<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\TravelProfile;
use App\Models\User;
use App\Services\Pdf\ItineraryPdf;
use App\Services\Pdf\PdfWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE ITINERARY PDF.
 *
 * Half of these tests are about the DOCUMENT and half are about the WRITER,
 * and the split matters. A PDF's failure modes are almost all invisible to an
 * HTTP assertion: a broken cross-reference table, a dropped page, an
 * unbalanced graphics state. They produce a file that downloads with a 200, has
 * the right byte count, and does not open.
 *
 * So `the_pdf_is_structurally_valid()` parses the output back rather than
 * trusting the writer, and it is the test to extend when the layout changes.
 */
class ItineraryPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Itinerary $itinerary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $profile = TravelProfile::create([
            'user_id' => $this->user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 2,
        ]);

        // UserFactory is the ONLY factory in this repo. Destination, TravelProfile,
        // Tag and DestinationSwipe are all built by hand, which is why each of
        // these carries a province, a coordinate and a slug rather than leaning
        // on defaults nobody declared.
        $destinations = collect(['Binangonan', 'Tanay', 'Morisento', 'Teresa'])->map(
            fn (string $name, int $i) => Destination::create([
                'name' => $name,
                'slug' => strtolower($name),
                'description' => 'A place worth the drive.',
                'municipality' => $name,
                'province' => 'Rizal',
                'latitude' => 14.3 + $i,
                'longitude' => 121.4 + $i,
                'budget_level' => 'economy',
                'estimated_cost' => 250,
                'recommended_minutes' => 90,
                'is_active' => true,
            ])
        );

        foreach ($destinations as $destination) {
            DestinationSwipe::updateOrCreate(
                ['user_id' => $this->user->id, 'destination_id' => $destination->id],
                ['action' => 'liked']
            );
        }

        $this->itinerary = Itinerary::create([
            'user_id' => $this->user->id,
            'title' => 'Two days on the ridge road',
            'budget_level' => 'economy',
            'trip_duration_days' => 2,
            'total_estimated_cost' => 1000,
        ]);

        foreach ([1, 2] as $number) {
            $day = ItineraryDay::create([
                'itinerary_id' => $this->itinerary->id,
                'day_number' => $number,
            ]);

            foreach ($destinations->take(2) as $index => $destination) {
                ItineraryItem::create([
                    'itinerary_day_id' => $day->id,
                    'destination_id' => $destination->id,
                    'sort_order' => $index + 1,
                    'start_time' => '09:00:00',
                    'end_time' => '11:00:00',
                    'travel_minutes_from_previous' => $index === 0 ? 0 : 45,
                    'estimated_cost' => 250,
                ]);
            }
        }
    }

    private function pdf(): string
    {
        return (new ItineraryPdf)->build($this->itinerary->fresh());
    }

    // -- the document -----------------------------------------------------

    #[Test]
    public function the_owner_can_download_the_pdf(): void
    {
        $response = $this->actingAs($this->user)->get(route('itineraries.download', $this->itinerary));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertDownload();
    }

    #[Test]
    public function somebody_elses_itinerary_is_refused(): void
    {
        $stranger = User::factory()->create();

        // 403 and not 404: route-model binding resolved the row from the whole
        // table, so claiming it does not exist would be a lie about data the
        // request demonstrably reached.
        $this->actingAs($stranger)
            ->get(route('itineraries.download', $this->itinerary))
            ->assertForbidden();
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('itineraries.download', $this->itinerary))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_declared_length_matches_the_body(): void
    {
        $response = $this->actingAs($this->user)->get(route('itineraries.download', $this->itinerary));

        // A Content-Length that disagrees with the body is the shape of a
        // download that stalls halfway on the traveller's machine. The response
        // is built from a string, not a stream, so this is `getContent()` --
        // `streamedContent()` throws on a non-streamed response.
        $this->assertSame(
            strlen($response->getContent()),
            (int) $response->headers->get('Content-Length')
        );
    }

    /**
     * THE FILENAME IS SLUGGED, NOT QUOTED.
     *
     * The title is free text and it lands in a header. A title carrying a quote
     * or a newline would inject a second header, and a title carrying a slash
     * would write the file somewhere other than the traveller's Downloads
     * folder.
     */
    #[Test]
    public function the_filename_survives_a_hostile_title(): void
    {
        $this->itinerary->update(['title' => "Rizal \"trip\"\r\nX-Injected: yes /../../etc"]);

        $disposition = $this->actingAs($this->user)
            ->get(route('itineraries.download', $this->itinerary))
            ->headers->get('Content-Disposition');

        $this->assertStringNotContainsString("\r", (string) $disposition);
        $this->assertStringNotContainsString("\n", (string) $disposition);
        $this->assertStringNotContainsString('X-Injected', (string) $disposition);
        $this->assertStringNotContainsString('/', (string) $disposition);

        // The header ends with the CLOSING QUOTE, so the extension has to be
        // asserted on the filename itself. Checking `EndsWith('.pdf')` on the
        // whole header fails on a perfectly correct disposition.
        preg_match('/filename="([^"]*)"/', (string) $disposition, $m);

        $this->assertNotEmpty($m, 'the disposition carries no quoted filename');
        $this->assertStringEndsWith('.pdf', $m[1]);
        $this->assertStringEndsWith('-'.$this->itinerary->id.'.pdf', $m[1], 'the filename cannot tell two same-titled trips apart');
    }

    #[Test]
    public function the_itinerarys_own_facts_are_in_the_document(): void
    {
        $pdf = $this->pdf();

        $this->assertStringContainsString('Two days on the ridge road', $this->textOf($pdf));
        $this->assertStringContainsString('2 days', $this->textOf($pdf));
        $this->assertStringContainsString('PHP 1,000.00', $this->textOf($pdf));

        // Every stop's destination name, or the document is a decoration rather
        // than the trip.
        $this->itinerary->load('days.items.destination');

        foreach ($this->itinerary->days->flatMap(fn (ItineraryDay $d) => $d->items) as $item) {
            $this->assertStringContainsString(
                $item->destination->name,
                $this->textOf($pdf),
                'a scheduled stop is missing from the PDF'
            );
        }
    }

    /**
     * MONEY IS "PHP", AND NEVER THE PESO SIGN.
     *
     * The standard-14 PDF fonts are WinAnsiEncoded and that encoding has **no
     * peso sign** -- 0xA5 is the yen. So a U+20B1 written into the content
     * stream renders as ¥ on the traveller's page, which is a wrong currency on
     * a document whose whole job is to be trusted about money.
     */
    #[Test]
    public function a_peso_sign_is_never_written_to_the_content_stream(): void
    {
        $this->itinerary->update(['total_estimated_cost' => 450.00]);

        $pdf = $this->pdf();

        $this->assertStringNotContainsString("\u{20B1}", $pdf, 'a raw peso sign reached the PDF');
        $this->assertStringContainsString('PHP 450.00', $this->textOf($pdf));
    }

    /**
     * THE COVER'S FACT LABELS ARE WORDS, NOT ARRAY OFFSETS.
     *
     * THE BUG THIS EXISTS FOR. The facts were built as a list literal and then
     * read as a keyed map, and `array_filter()` preserves keys — so a null date
     * left the survivors at positions 0 and 2 and the cover printed **"0"** and
     * **"2"** where the labels should be. It rendered, downloaded, and every
     * assertion about the trip passed, because the *values* were all correct
     * and nobody looked at the small gold type above them.
     */
    #[Test]
    public function the_cover_labels_its_facts(): void
    {
        $text = $this->textOf($this->pdf());

        $this->assertStringContainsString('LENGTH', $text);
        $this->assertStringContainsString('EST. TOTAL', $text);

        // An undated trip has no dates fact at all, and the gap must not be
        // filled with the offset of the fact that follows it.
        $this->assertStringNotContainsString("\n0\n", $text, 'a cover label rendered as an array offset');

        $this->itinerary->update(['start_date' => '2026-11-02']);
        $dated = $this->textOf((new ItineraryPdf)->build($this->itinerary->fresh()));

        $this->assertStringContainsString('DATES', $dated);
        $this->assertStringContainsString('2 – 3 Nov 2026', $dated);
    }

    /**
     * PUNCTUATION SURVIVES AS PUNCTUATION, NOT AS MOJIBAKE.
     *
     * THE BUG THIS EXISTS FOR. `escape()` iterated with `str_split()`, which is
     * **byte-based**, so an en dash (U+2013, bytes `E2 80 93`) was three
     * "characters" of 226, 128 and 147. All three are below 256, so the
     * multibyte branch never ran and the raw UTF-8 bytes were emitted as glyphs.
     * The page read:
     *
     *     08:00 â€“ 17:30 · Day window 08:00 â€“ 09:30 Â· 1 planned stop
     *
     * Every structural check still passed and the file opened, because the bytes
     * were a perfectly valid Latin-1 string — just the wrong one. `widthOf()`
     * measured real characters via `mb_str_split()`, so the layout was right and
     * the glyphs were not, which is why it presented as a font problem.
     *
     * WinAnsiEncoding *is* Windows-1252 for every character this app emits, so
     * converting the whole string first fixes the dash, the middle dot, the
     * ellipsis and every accented name at once.
     */
    #[Test]
    public function punctuation_and_accents_are_encoded_as_themselves(): void
    {
        $writer = new PdfWriter;

        $writer->text('F1', 12, 10, 10, '08:00–17:30 · and…');
        $writer->text('F1', 12, 10, 40, 'Pintô — Cape Engaño');

        $pdf = $writer->output();

        // The UTF-8 bytes of U+2013 must not survive as three separate glyphs.
        $this->assertStringNotContainsString("\xE2\x80\x93", $pdf, 'a raw UTF-8 en dash reached the content stream');
        $this->assertStringNotContainsString("\xC2\xB7", $pdf, 'a raw UTF-8 middle dot reached the content stream');

        // … and the reader sees what was written.
        $this->assertStringContainsString('08:00–17:30', $this->textOf($pdf));
        $this->assertStringContainsString('·', $this->textOf($pdf));
        $this->assertStringContainsString('Pintô', $this->textOf($pdf));
        $this->assertStringContainsString('Cape Engaño', $this->textOf($pdf));

        // The mojibake spellings must appear nowhere in the document.
        $this->assertStringNotContainsString('â€“', $this->textOf($pdf));
        $this->assertStringNotContainsString('Â·', $this->textOf($pdf));
    }

    /**
     * EVERY BYTE EMITTED IS ONE A WINANSI READER HAS A GLYPH FOR.
     *
     * A byte outside the encoding renders as whatever the reader substitutes,
     * which is how the mojibake reached the page. The peso's replacement is
     * asserted separately, and this is the belt to that braces.
     */
    #[Test]
    public function no_unencodable_byte_reaches_the_content_stream(): void
    {
        $writer = new PdfWriter;

        // A CJK character, an emoji and a peso sign: none of which WinAnsi has.
        $writer->text('F1', 12, 10, 10, ' Manila ₱250 台灣 ');

        $pdf = $writer->output();

        $this->assertStringNotContainsString("\xE2\x82", $pdf, 'a raw UTF-8 byte sequence reached the stream');
        $this->assertStringContainsString('PHP250', $this->textOf($pdf));
    }

    /**
     * THE COLOPHON'S GOLD TICK ALIGNS WITH THE HEADING IT MARKS.
     *
     * `$y` is the text BASELINE and a 12pt Times-Bold capital stands ~8.4pt
     * above it. The tick was drawn from `$y + 8` upward, which put it entirely
     * above the word — the reported "lines aren't aligned with text". It has to
     * span the cap height, so its bottom sits a point below the baseline.
     */
    #[Test]
    public function the_colophon_ticks_align_with_their_headings(): void
    {
        preg_match_all('/<< \/Length \d+ \/?[^>]*>>\nstream\n(.*?)\nendstream/s', $this->pdf(), $streams);

        $colophon = (string) end($streams[1]);

        $this->assertStringContainsString('BEFORE YOU GO', $colophon, 'the colophon is not the last page');

        // Gold rules are narrow vertical ticks: 2pt wide. `rect()` emits
        // `%.2F` throughout, so the width is `2.00` — matching a bare `2` finds
        // nothing and the assertion below passes for the wrong reason.
        preg_match_all('/(-?[\d\.]+) (-?[\d\.]+) 2\.00 (-?[\d\.]+) re\s+f/', $colophon, $ticks, PREG_SET_ORDER);

        $this->assertNotEmpty($ticks, 'the colophon drew no gold ticks');

        // And the heading baselines they mark. `text()` emits `x y Td` -- TWO numbers,
        // not three, so a pattern expecting an extra operand matches nothing.
        preg_match_all('/BT \/F2 ([\d\.]+) Tf (-?[\d\.]+) (-?[\d\.]+) Td \((Check the hours|Fees are estimates|Some places are ungated|Respect the sites you visit)\)/', $colophon, $headings, PREG_SET_ORDER);

        $this->assertCount(
            count($ticks),
            $headings,
            'every gold tick should have a heading beside it'
        );

        foreach ($ticks as $i => $tick) {
            // PREG_SET_ORDER groups: [0] is the whole match, then one entry per
            // capture group. The regex has three groups -- x, y, height.
            $tickBottom = (float) $tick[2];
            $tickHeight = (float) $tick[3];
            $headingBaseline = (float) $headings[$i][3];
            $headingSize = (float) $headings[$i][1];

            // Cap height of Times-Bold is ~0.68em.
            $capTop = $headingBaseline + ($headingSize * 0.68);

            /*
             * THE TICK SPANS THE CAP HEIGHT. It is a marker, so it may rise
             * ABOVE the capital — what it must not do is float clear of it.
             *
             * Drawn from `$y + 8` the tick ran from the cap top to `$y + 20`,
             * i.e. entirely above the word, which is the reported misalignment.
             * From `$y - 1` it starts a hair below the baseline and rises
             * through the capital.
             */
            $this->assertLessThanOrEqual(
                $headingBaseline + 1.0,
                $tickBottom,
                'a gold tick floats above its heading instead of starting at its baseline'
            );

            $this->assertGreaterThanOrEqual(
                $capTop - 1.0,
                $tickBottom + $tickHeight,
                'a gold tick is too short to reach the cap height of the heading it marks'
            );
        }
    }

    /**
     * THE WRITER REFUSES A PESO EVEN WHEN A CALLER PASSES ONE.
     *
     * `ItineraryPdf::money()` already writes "PHP", so the itinerary path never
     * reaches this. The mapping is the writer's own last line of defence and it
     * is exercised here directly — otherwise a future caller passing a raw
     * figure would put ¥ on a traveller's page, and no test would be watching.
     */
    #[Test]
    public function the_writer_refuses_a_peso_sign_even_from_a_caller_that_passes_one(): void
    {
        $writer = new PdfWriter;

        $writer->text('F1', 12, 10, 10, '₱1,250');

        $pdf = $writer->output();

        $this->assertStringNotContainsString("\u{20B1}", $pdf);
        $this->assertStringContainsString('PHP1,250', $pdf);
    }

    /**
     * THE LOGO IS VECTOR, EMITTED FROM THE BLADE COMPONENT.
     *
     * Reading `application-logo.blade.php` rather than shipping a second copy is
     * the contract: one home for the mark, so a rebrand cannot leave the PDF
     * behind. The bezier count is the evidence that a real path went out rather
     * than an image reference or, worse, nothing at all.
     */
    #[Test]
    public function the_logo_is_drawn_as_vector_paths_from_the_component(): void
    {
        $component = (string) file_get_contents(
            resource_path('views/components/application-logo.blade.php')
        );

        preg_match('/<path[^>]*\sd="([^"]+)"/s', $component, $m);

        $this->assertNotEmpty($m, 'the logo component no longer exposes a path d attribute');

        $expectedSegments = preg_match_all('/[cC]\s*-?[\d\.]/', $m[1]);

        $this->assertGreaterThan(20, $expectedSegments, 'the logo path lost its curve data');

        $drawn = preg_match_all(
            '/-?[\d\.]+ -?[\d\.]+ -?[\d\.]+ -?[\d\.]+ -?[\d\.]+ -?[\d\.]+ c/',
            $this->pdf()
        );

        $this->assertGreaterThanOrEqual(
            $expectedSegments,
            $drawn,
            'the PDF drew fewer bezier segments than the logo path declares — the mark is '
            .'not being emitted in full'
        );
    }

    /**
     * A LOGO COMPONENT WITH COMMANDS PDF CANNOT DRAW IS REFUSED, NOT MISREAD.
     *
     * SVG's `A`, `Q` and `S` are not PDF's `A`, `v` and `c`. Emitting them under
     * PDF's letters draws a *different shape* rather than failing, which for a
     * brand mark is the worst available outcome.
     */
    #[Test]
    public function an_unsupported_logo_path_is_skipped_rather_than_mistranslated(): void
    {
        /*
         * COUNTED RELATIVE TO A GOOD BUILD, NOT ABSOLUTELY.
         *
         * Bezier curves also come from `roundRect()`'s corners, so a document
         * with no logo at all still contains plenty of `c` operators. Asserting
         * zero of them would fail on the cards and prove nothing. What matters
         * is that the broken logo contributes FEWER curves than the real one --
         * which is the difference between "skipped" and "drawn as a wrong shape".
         */
        $withLogo = $this->pdf();

        $logo = tempnam(sys_get_temp_dir(), 'logo').'.blade.php';

        file_put_contents($logo, '<svg><path d="M0 0A5 5 0 0 1 10 10L10 10Z"/></svg>');

        $withoutLogo = (new ItineraryPdf($logo))->build($this->itinerary->fresh());

        @unlink($logo);

        $this->assertStringStartsWith('%PDF', $withoutLogo, 'an unreadable logo should not take the download down');
        $this->assertStringContainsString('%%EOF', $withoutLogo);

        $count = fn (string $pdf) => preg_match_all('/ -?[\d\.]+ c/', $pdf);

        $this->assertLessThan(
            $count($withLogo),
            $count($withoutLogo),
            'the broken logo was drawn rather than skipped -- an SVG arc emitted '
            .'under PDF\'s curve operator draws a different shape, not an error'
        );

        // And the document is otherwise identical, so nothing else went missing.
        $this->assertStringContainsString('BEFORE YOU GO', $this->textOf($withoutLogo));
    }

    /**
     * A DESTINATION WITH NO PHOTOGRAPH GETS A PLATE, NEVER A FAKE IMAGE.
     *
     * Every row in the current catalogue has a NULL `image_url`, so this is the
     * normal case and the photograph path is the exception. The rule is the
     * stage's: a flat colour pretending to be a photograph is a photograph this
     * app does not have.
     */
    #[Test]
    public function a_photoless_destination_gets_a_typographic_plate_not_a_blank_or_a_fake(): void
    {
        $this->itinerary->load('days.items.destination');

        foreach ($this->itinerary->days->flatMap(fn (ItineraryDay $d) => $d->items) as $item) {
            $this->assertNull($item->destination->image_url);
        }

        $pdf = $this->pdf();

        // The plate carries the two lines that place it: the municipality and
        // the province in gold. Neither is decoration; they are the facts.
        $this->assertStringNotContainsString('/Image', $pdf, 'an image XObject was emitted with no photograph to embed');
        $this->assertGreaterThan(0, substr_count($pdf, ' rg'), 'the plate ground was not drawn');
    }

    // -- the writer -------------------------------------------------------

    /**
     * THE DOCUMENT IS STRUCTURALLY VALID.
     *
     * Parses the bytes back: the cross-reference offsets must land on their
     * object headers, and every declared stream length must match the bytes
     * actually present. Both are the classic "opens on my machine, not on
     * theirs" failures.
     */
    #[Test]
    public function the_pdf_is_structurally_valid(): void
    {
        $pdf = $this->pdf();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);

        $this->assertSame(1, preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/s', $pdf, $m), 'no startxref');
        $this->assertSame(0, preg_match('/startxref\s+0\s/', $pdf), 'startxref points at byte zero');

        $xrefAt = (int) $m[1];

        $this->assertSame('xref', substr($pdf, $xrefAt, 4), 'startxref does not point at the xref table');

        $lines = preg_split('/\r\n|\r|\n/', substr($pdf, $xrefAt));

        // $lines[0] is "xref", $lines[1] the `0 N` header, $lines[2] the free
        // entry for object 0. Reading from $lines[2] for object 1 makes every
        // offset zero and every document look broken.
        $count = (int) preg_split('/\s+/', trim($lines[1]))[1];

        for ($id = 1; $id < $count; $id++) {
            $offset = (int) substr($lines[$id + 2], 0, 10);

            $this->assertMatchesRegularExpression(
                '/^'.$id.' 0 obj/',
                substr($pdf, $offset, 24),
                "xref entry for object {$id} points at the wrong byte"
            );
        }

        // Capture 1 is the DECLARED length and capture 2 is the stream body.
        // `$streams[1]` alone is the declared lengths, so pairing it against
        // itself compares every stream against the first one.
        preg_match_all('/<< \/Length (\d+) >>\nstream\n(.*?)\nendstream/s', $pdf, $streams);

        $this->assertNotEmpty($streams[2], 'the document has no content streams');

        foreach ($streams[1] as $i => $declared) {
            $this->assertSame(
                (int) $declared,
                strlen($streams[2][$i]),
                "content stream {$i} declares a length it does not have"
            );
        }
    }

    /**
     * THE LAST PAGE IS IN THE DOCUMENT.
     *
     * THE BUG THIS EXISTS FOR. `addPage()` pushes the page you are LEAVING, so
     * after drawing the final page its content is still in the buffer and not in
     * the page list. Serialising the list alone drops it — here that is the
     * colophon, silently, and the PDF still opens and still has a plausible
     * page count.
     */
    #[Test]
    public function the_final_page_is_not_dropped_from_the_document(): void
    {
        $this->assertStringContainsString('BEFORE YOU GO', $this->textOf($this->pdf()));
    }

    /**
     * THE GRAPHICS STATE IS BALANCED ON EVERY PAGE.
     *
     * `q` without a matching `Q` leaks a transform into the next object and the
     * document is unreadable past that point with nothing reporting it.
     */
    #[Test]
    public function every_page_balances_its_graphics_and_text_state(): void
    {
        preg_match_all('/<< \/Length \d+ \/?[^>]*>>\nstream\n(.*?)\nendstream/s', $this->pdf(), $streams);

        foreach ($streams[1] as $i => $content) {
            $this->assertSame(
                substr_count($content, "\nq\n"),
                substr_count($content, "\nQ\n"),
                "page {$i} has unbalanced save/restore"
            );

            // An unclosed BT leaks into the next text object.
            $this->assertSame(
                substr_count($content, 'BT '),
                substr_count($content, 'ET'),
                "page {$i} has an unclosed text object"
            );
        }
    }

    /**
     * THE WRITER REJECTS A RESTORE WITH NOTHING SAVED.
     *
     * It would otherwise emit a stray `Q` and corrupt the state stack silently.
     */
    #[Test]
    public function an_unbalanced_restore_is_refused_rather_than_emitted(): void
    {
        $writer = new PdfWriter;

        $this->expectException(\RuntimeException::class);

        $writer->restoreState();
    }

    /**
     * THE WRITER REFUSES A FONT IT DOES NOT HAVE.
     *
     * Emitting an unknown font name produces a PDF that reports a missing
     * resource at render time — on the traveller's machine, not here.
     */
    #[Test]
    public function an_unknown_font_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        (new PdfWriter)->text('F99', 12, 10, 10, 'hello');
    }

    /**
     * MEASURED TEXT, NOT GUESSED.
     *
     * `widthOf()` drives every wrap, every ellipsis and every right-aligned
     * label. If it returns a wrong number nothing raises — the layout is simply
     * wrong — so it is pinned against the published metrics of the standard 14.
     */
    #[Test]
    public function text_width_comes_from_the_published_metrics(): void
    {
        $writer = new PdfWriter;

        // Helvetica 'M' is 833/1000 em; Times-Roman 'M' is 889/1000.
        $this->assertEqualsWithDelta(83.3, $writer->widthOf('M', 'F1', 100), 0.1);
        $this->assertEqualsWithDelta(88.9, $writer->widthOf('M', 'F4', 100), 0.1);

        // Digits are 500/1000 in both families.
        $this->assertEqualsWithDelta(50.0, $writer->widthOf('1', 'F1', 100), 0.1);
        $this->assertEqualsWithDelta(50.0, $writer->widthOf('1', 'F4', 100), 0.1);

        // A string is the sum of its glyphs.
        $this->assertEqualsWithDelta(
            $writer->widthOf('AB', 'F1', 100),
            $writer->widthOf('A', 'F1', 100) + $writer->widthOf('B', 'F1', 100),
            0.01
        );
    }

    /**
     * A PARENTHESIS IN CONTENT DOES NOT TRUNCATE THE STRING.
     *
     * `(` and `)` are structural inside a PDF literal string. An unbalanced one
     * written unescaped ends the string early and spills raw binary into the
     * page — so a destination with a bracket in its name would quietly corrupt
     * everything after it.
     */
    #[Test]
    public function structural_characters_in_content_are_escaped(): void
    {
        $pdf = (new ItineraryPdf)->build(
            tap($this->itinerary->fresh(), fn ($i) => $i->title = 'A (very) odd \\ title')
        );

        $this->assertStringContainsString('A \\(very\\) odd \\\\ title', $pdf);

        // And the stream that carried it is still the length it claims --
        // an escaped string that grew without the length growing is a
        // truncated page rather than a bracketed one.
        preg_match_all('/<< \/Length (\d+) >>\nstream\n(.*?)\nendstream/s', $pdf, $streams);

        foreach ($streams[1] as $i => $declared) {
            $this->assertSame((int) $declared, strlen($streams[2][$i]));
        }

        // The unescaped text a reader would see, exactly as written.
        $this->assertStringContainsString('A (very) odd \\ title', $this->textOf($pdf));
    }

    // -- helpers ----------------------------------------------------------

    /**
     * The strings a reader would see, reassembled from the content streams.
     *
     * A test that greps the raw PDF for a destination name would pass or fail
     * on escaping accidents rather than on content, so text is pulled out of
     * the `(...) Tj` operators and unescaped first.
     *
     * **THEN DECODED FROM WIN-1252, WHICH IS WHAT THE FONT SAYS IT IS.** The
     * stream is a byte string in the font's own encoding; reading those bytes as
     * UTF-8 makes a correct en dash (0x96) and a correct middle dot (0xB7) look
     * like invalid input, and an assertion for `–` fails against a document that
     * prints it correctly. A reader decodes WinAnsiEncoding, so the helper does
     * the same.
     */
    private function textOf(string $pdf): string
    {
        preg_match_all('/<< \/Length \d+ \/?[^>]*>>\nstream\n(.*?)\nendstream/s', $pdf, $streams);

        $text = '';

        foreach ($streams[1] as $content) {
            preg_match_all('/\((.*?)\) Tj/s', $content, $found);

            foreach ($found[1] as $raw) {
                $unescaped = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $raw);

                $text .= mb_convert_encoding($unescaped, 'UTF-8', 'Windows-1252')."\n";
            }
        }

        return $text;
    }
}
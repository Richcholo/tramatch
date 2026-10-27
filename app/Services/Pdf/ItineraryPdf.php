<?php

namespace App\Services\Pdf;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * THE ITINERARY, AS A PRINTED DOCUMENT.
 *
 * The visual argument is the same one the destinations stage makes: one dark
 * surface, Deep Volcanic Teal, with the brand's gold as the accent and Island
 * White carrying the text. A traveller prints this, so the cover is the part
 * that gets looked at — it is a full-bleed teal page with the logo in vector,
 * the title set large, and the trip's own facts on a gold rule.
 *
 * **THE LOGO IS VECTOR, NOT A BITMAP.** `application-logo.blade.php` is a single
 * SVG `<path>` in a 316×316 viewBox using only `M L C H V Z`, every one of which
 * is a PDF operator under a different letter. So the mark is emitted as path
 * operators rather than as an image: no decoding, no GD, no pixelation at any
 * zoom, and nothing that can fail on a host where the image libraries are not
 * installed. A raster logo at print resolution would also have needed
 * resampling, which is exactly the capability this machine does not have.
 *
 * **A DESTINATION WITH NO PHOTOGRAPH GETS A TYPOGRAPHIC PLATE.** All 65 rows in
 * the catalogue currently have a NULL `image_url`, so the photograph path is the
 * exception and the plate is the normal case. The plate is province and
 * municipality set in type on a lifted ground — the same rule the stage and the
 * discover deck follow, and for the same reason: a flat colour pretending to be
 * a photograph is a photograph this app does not have.
 *
 * Coordinates are converted to PDF-native (origin bottom-left, y up) once, in
 * `y()`, and never anywhere else.
 */
class ItineraryPdf
{
    private const TEAL = '#0B252B';

    private const GOLD = '#F4B41A';

    private const SAND = '#FDFBF7';

    private const CHARCOAL = '#2C3531';

    private const WHITE = '#FFFFFF';

    private const BORACAY = '#00A896';

    private const MUTED = '#7C8B87';

    /** A raised surface on the teal ground — the page/card colour, never opaque sand. */
    private const SLAB = '#122F36';

    private const MARGIN = 48.0;

    /** How far the day rail runs down the left edge. */
    private const RAIL = 3.0;

    private PdfWriter $pdf;

    private string $logoPath;

    /**
     * @param  string  $logoPath  the SVG source, read for its single `<path d>`
     */
    public function __construct(?string $logoPath = null)
    {
        $this->pdf = new PdfWriter;
        $this->logoPath = $logoPath ?? resource_path('views/components/application-logo.blade.php');
    }

    public function build(Itinerary $itinerary): string
    {
        $itinerary->loadMissing(['days.items.destination', 'user']);

        $days = $itinerary->days->sortBy('day_number')->values();

        $this->cover($itinerary, $days);

        foreach ($days as $day) {
            $this->day($itinerary, $day);
        }

        $this->colophon($itinerary);

        return $this->pdf->output();
    }

    // -- cover ------------------------------------------------------------

    private function cover(Itinerary $itinerary, Collection $days): void
    {
        $p = $this->pdf;

        $p->rect(0, 0, $p->width(), $p->height(), self::TEAL);

        // A wide gold rule under the wordmark, inset from the margin.
        $p->rect(self::MARGIN, $p->height() - 232, 64, 3, self::GOLD);

        $this->logo(64, 32, self::WHITE);

        $p->tracked('F2', 11, $p->width() - self::MARGIN - $p->widthOf('TRAVEL ITINERARY', 'F2', 11) - 11 * 15,
            $p->height() - 62, 'TRAVEL ITINERARY', 2.4, self::GOLD);

        // The title, wrapped by hand against the measure of Times-Bold so it
        // never overruns the margin. No layout engine means the wrap is ours.
        $title = $itinerary->title !== '' ? $itinerary->title : 'Your itinerary';
        $lines = $this->wrap($title, 'F5', 40, $p->width() - (self::MARGIN * 2), 4);

        $y = $p->height() - 300 - (count($lines) - 1) * 46;

        foreach ($lines as $i => $line) {
            $p->text('F5', 40, self::MARGIN, $y - ($i * 46), $line, self::WHITE);
        }

        /*
         * THREE FACTS ON ONE BASELINE, because they are what a traveller checks
         * first and a column of them would waste the cover.
         *
         * KEYED, NOT A LIST. `array_filter()` preserves keys, so a list literal
         * leaves the surviving entries at their original integer positions and
         * `foreach ($facts as $label => $value)` then prints "0" and "2" as the
         * labels. That is what shipped in the first version: the cover read
         * `0 / 2 days / PHP 1,000.00` with the labels being the array offsets.
         */
        $facts = array_filter([
            'Length' => $this->dayCount($days),
            'Dates' => $this->dateRange($itinerary),
            'Est. total' => $this->money($itinerary->total_estimated_cost),
        ]);

        $fy = 214.0;
        $x = self::MARGIN;

        foreach ($facts as $label => $value) {
            $p->tracked('F1', 7.5, $x, $fy + 20, strtoupper((string) $label), 1.6, self::GOLD);
            $p->text('F2', 11, $x, $fy, (string) $value, self::WHITE);
            $x += max(120.0, $p->widthOf((string) $value, 'F2', 11) + 44);
        }

        // The destination count as a quiet ledger line, bottom left.
        $stops = $days->sum(fn (ItineraryDay $d) => $d->items->count());

        $p->text('F1', 10, self::MARGIN, 108, sprintf(
            '%d %s · %d %s',
            $stops,
            $stops === 1 ? 'stop' : 'stops',
            $days->count(),
            $days->count() === 1 ? 'day' : 'days'
        ), self::MUTED);

        // THE TOTAL IS A SUM OF VERIFIED PRICES, AND SAYS SO WHEN IT ISN'T
        // THE WHOLE STORY. An unpriced stop stores 0 because the column cannot
        // hold null, so the cover's figure silently counts it as free. The web
        // page says "3 stop(s) without a verified price — the total above is
        // incomplete"; a printed trip is the artefact a traveller keeps and
        // trusts, so it cannot be the one place that claim is missing.
        $unpriced = $this->unpricedCount($days);

        if ($unpriced > 0) {
            $p->text('F1', 8.5, self::MARGIN, 96, sprintf(
                '%d stop%s ha%s no published price — the total above counts the verified ones.',
                $unpriced,
                $unpriced === 1 ? '' : 's',
                $unpriced === 1 ? 's' : 've'
            ), self::MUTED);
        }

        $p->text('F1', 9, self::MARGIN, 84, 'Budget: '.ucfirst((string) $itinerary->budget_level), self::MUTED);

        // A hairline above the footer, in gold at low presence.
        $p->rect(self::MARGIN, 62, $p->width() - (self::MARGIN * 2), 0.5, self::SLAB);

        $p->text('F1', 9, self::MARGIN, 46, 'Prepared by TraMatch', self::MUTED);
        $p->textRight('F1', 9, $p->width() - self::MARGIN, 46,
            'Generated '.now()->format('j M Y'), self::MUTED);
    }

    // -- a day -----------------------------------------------------------

    private function day(Itinerary $itinerary, ItineraryDay $day): void
    {
        $p = $this->pdf;

        $p->addPage();
        $p->rect(0, 0, $p->width(), $p->height(), self::SAND);

        // The rail runs the full page height on the teal edge, and the day
        // number sits on it. The rail is the one place teal appears on a light
        // page, which is what makes the pages read as a sequence.
        $p->rect(0, 0, 26, $p->height(), self::TEAL);

        $p->tracked('F2', 8, 13 - $p->widthOf((string) $day->day_number, 'F2', 8) / 2,
            $p->height() - 96, (string) $day->day_number, 0.6, self::GOLD);

        $this->logo(14, 26, self::TEAL, 30);

        $x = 54.0;
        $top = $p->height() - 92;

        $p->text('F5', 28, $x, $top, $this->dayTitle($day), self::CHARCOAL);

        if ($day->date) {
            $p->text('F2', 11, $x, $top - 24, $this->longDate($day->date), self::MUTED);
        }

        $p->textRight('F2', 10, $p->width() - self::MARGIN, $top, $this->dayWindow($day), self::CHARCOAL);

        $p->rect($x, $top - 42, $p->width() - self::MARGIN - $x, 1.2, self::TEAL);

        // Content starts under the rule and flows down.
        $y = $top - 78;

        if ($day->items->isEmpty()) {
            $this->emptyDay($x, $y);

            return;
        }

        $y = $this->dayIntro($x, $y, $day);

        foreach ($day->items->sortBy('sort_order') as $item) {
            if ($y < 150) {
                // A day longer than one page continues rather than being
                // clipped. The rail and the header go with it, so the reader
                // can still tell which day they are looking at.
                $p->addPage();
                $p->rect(0, 0, $p->width(), $p->height(), self::SAND);
                $p->rect(0, 0, 26, $p->height(), self::TEAL);
                $this->logo(14, 26, self::TEAL, 30);

                $trackedY = $p->height() - 56;

                $p->tracked('F2', 8, 54, $trackedY, 'DAY '.$day->day_number.' CONTINUED', 2.0, self::BORACAY);

                $y = $trackedY - 34;
            }

            $y = $this->stop($x, $y, $item, $day);
        }

        $this->dayFooter($itinerary, $x, $y, $day);
    }

    private function dayIntro(float $x, float $y, ItineraryDay $day): float
    {
        $p = $this->pdf;

        $last = $day->items->sortBy('sort_order')->last();

        if ($last?->end_time) {
            $p->text('F1', 9, $x, $y, sprintf(
                'Day window %s – %s · %s',
                $day->items->min('start_time') ? $this->clock($day->items->min('start_time')) : 'flexible',
                $this->clock($last->end_time),
                $day->items->count() === 1 ? '1 planned stop' : $day->items->count().' planned stops'
            ), self::MUTED);

            return $y - 26;
        }

        return $y;
    }

    /**
     * ONE STOP. The card is the unit the whole document is built from.
     */
    private function stop(float $x, float $y, ItineraryItem $item, ItineraryDay $day): float
    {
        $p = $this->pdf;

        $destination = $item->destination;
        $cardW = $p->width() - self::MARGIN - $x;
        $imageW = 92.0;
        $cardH = 80.0;

        // THE CARD. Light, because this page is light — the teal ground belongs
        // to the cover, and putting it here would make every page a different
        // document.
        $p->roundRect($x, $y - $cardH, $cardW, $cardH, 5, self::WHITE, '#E4EAE8', 0.7);

        // The time, in its own narrow column, is what makes a schedule a
        // schedule rather than a list.
        $p->rect($x, $y - $cardH, 2.5, $cardH, self::GOLD);

        $timeX = $x + 14;

        if ($item->start_time) {
            $p->text('F2', 12, $timeX, $y - 24, $this->clock($item->start_time), self::TEAL);
        }

        // A zero-length window — the lodging rows carry none — prints one
        // time: "08:00 to 08:00" reads as a rendering fault.
        if ($item->end_time && $item->end_time !== $item->start_time) {
            $p->text('F1', 9, $timeX, $y - 38, 'to '.$this->clock($item->end_time), self::MUTED);
        }

        // THE KIND, on the same column as the time, because a day reads as a
        // schedule only if you can tell at a glance which stop is dinner.
        $p->text('F3', 7.5, $timeX, $y - 68, $this->fit($item->kindLabel(), 'F3', 7.5, 56), self::GOLD);

        // The same rule the page follows, and for the same reason. A printed trip
        // is the one artefact a traveller takes away and trusts, so the gap
        // between two stops must say whether the drive was measured or is the
        // plan's spacing. "+45 min travel" on a trip nobody edited is the flat
        // estimate wearing a measurement's clothes.
        if ($item->routeSummary()) {
            $p->text('F3', 7.5, $timeX, $y - 52, $item->routeSummary().' by road', self::MUTED);
        } elseif ($item->travel_minutes_from_previous > 0) {
            $p->text('F3', 7.5, $timeX, $y - 52, sprintf(
                '+%d min gap (drive not measured)',
                $item->travel_minutes_from_previous
            ), self::MUTED);
        }

        $textX = $x + 74;
        $textW = $cardW - 74 - $imageW - 16;

        // EVERY STOP IS READ THROUGH ITS OWN READERS. A meal, a hotel and a
        // traveller's own entry have no destination: this used to read
        // `$destination->name ?? 'Stop'`, so half the itinerary printed the
        // literal word "Stop" with no place, no price and no source — a
        // document that looked complete and described a third of the trip.
        $name = $this->fit($item->placeName(), 'F2', 12, $textW);
        $p->text('F2', 12, $textX, $y - 24, $name, self::CHARCOAL);

        $place = $this->stopPlaceLine($item);
        $p->text('F1', 8.5, $textX, $y - 38, $this->fit($place, 'F1', 8.5, $textW), self::MUTED);

        // The fee and the opening hours, side by side, because they are the two
        // facts a traveller actually needs at this point in the plan. A PLACE
        // prints its band WITH ITS UNIT — "per room, per night" beside
        // "₱4,500" is the difference between a price and a rumour — and a stop
        // nobody has priced says so instead of printing ₱0.
        $facts = array_filter([
            $this->costLine($item),
            $destination ? $this->hoursLine($destination) : null,
        ]);

        if ($facts !== []) {
            $p->text('F1', 8, $textX, $y - 52, $this->fit(implode('  ·  ', $facts), 'F1', 8, $textW), self::BORACAY);
        }

        if ($item->note) {
            $p->text('F3', 8, $textX, $y - 66, $this->fit((string) $item->note, 'F3', 8, $textW), self::MUTED);
        }

        // WHERE THE PRICE CAME FROM. Every curated place in this app carries a
        // source URL, and a printed trip is the artefact a traveller keeps — a
        // price with no citation cannot be checked when it turns out to be
        // wrong. Printed on its own line because a full URL is long.
        $source = $this->sourceLine($item);

        if ($source !== null) {
            $p->text('F3', 6.5, $textX, $y - 74, $this->fit($source, 'F3', 6.5, $textW), self::MUTED);
        }

        // THE IMAGE SLOT. A photograph when the owner has uploaded one, and a
        // typographic plate when they have not — which, for the whole catalogue
        // right now, is always.
        $this->imageSlot($x + $cardW - $imageW - 8, $y - $cardH + 8, $imageW, $cardH - 16, $item);

        return $y - $cardH - 14;
    }

    /**
     * WHERE A STOP IS, for the four stop shapes.
     *
     * A custom entry prints the address the traveller typed, because that is
     * the only location this app holds for it.
     */
    private function stopPlaceLine(ItineraryItem $item): string
    {
        if ($item->isCustom()) {
            return (string) ($item->custom_address ?: 'Your own stop');
        }

        if ($item->place) {
            return $item->place->municipality.', '.$item->place->province;
        }

        if ($item->destination) {
            return $this->placeLine($item->destination);
        }

        return '';
    }

    /**
     * THE PRICE LINE, WHICH MAY BE THE ABSENCE OF A PRICE.
     *
     * `PHP 0` is not a free lunch: an unpriced stop stores 0 because the
     * column cannot hold null, and printing that figure here would state a
     * price nobody published. So a stop with no verified price says so, and a
     * place prints the band's own unit rather than a bare number.
     */
    private function costLine(ItineraryItem $item): ?string
    {
        if (! $item->hasVerifiedPrice()) {
            return 'No published price';
        }

        if ($item->place) {
            return $item->place->priceLabel();
        }

        if ($item->destination && $item->destination->estimated_cost) {
            return 'Est. '.$this->money($item->destination->estimated_cost);
        }

        return null;
    }

    /**
     * THE CITATION, or null.
     *
     * Only where there is one to give: a curated place carries the URL its
     * price and hours were read from. An attraction's hours are cited on the
     * destination page and its fee is a curated estimate, so the line is for
     * the places — the rows whose figures are somebody else's claim.
     */
    private function sourceLine(ItineraryItem $item): ?string
    {
        if (! $item->place) {
            return null;
        }

        $url = trim((string) $item->place->source_url);

        return $url === '' ? null : 'Source: '.$url;
    }

    private function imageSlot(float $x, float $y, float $w, float $h, ItineraryItem $item): void
    {
        $p = $this->pdf;

        /*
         * A BITMAP SLOT IS NOT IMPLEMENTED, DELIBERATELY.
         *
         * This is the point to read before adding one. GD and Imagick are both
         * absent on this machine and on the target host, so a photograph cannot
         * be decoded, resized or recompressed here. A PDF can embed JPEG
         * *verbatim* via DCTDecode with no image library at all, which is the
         * only photograph path available without one — and it is left for the
         * day the catalogue actually has photographs, because until then the
         * plate is not a fallback, it is the design.
         */
        $p->roundRect($x, $y, $w, $h, 4, self::SLAB, null);

        // THE PLATE NAMES THE PLACE THE STOP IS AT. The item's own readers
        // cover all four stop shapes: a destination stop names the
        // destination's municipality and province, a meal or hotel names the
        // PLACE's — which used to print the island fallback "Luzon" beside a
        // Baguio hotel — and a custom stop falls back to the island.
        $municipality = trim((string) ($item->destination?->municipality
            ?? $item->place?->municipality ?? ''));
        $province = trim((string) ($item->destination?->province
            ?? $item->place?->province ?? ''));

        // Two centred lines: the place, and the larger unit it belongs to.
        $p->textCentred('F2', 8.5, $x + ($w / 2), $y + ($h / 2) + 3,
            $this->fit($municipality !== '' ? $municipality : ($province !== '' ? $province : 'Luzon'), 'F2', 8.5, $w - 12),
            self::WHITE);

        $p->textCentred('F1', 7, $x + ($w / 2), $y + ($h / 2) - 9,
            $this->fit(strtoupper($province), 'F1', 7, $w - 12),
            self::GOLD);
    }

    private function emptyDay(float $x, float $y): void
    {
        $p = $this->pdf;

        $p->roundRect($x, $y - 70, $p->width() - self::MARGIN - $x, 70, 5, self::WHITE, '#E4EAE8', 0.7);
        $p->text('F2', 11, $x + 18, $y - 28, 'Nothing planned for this day yet.', self::CHARCOAL);
        $p->text('F1', 9, $x + 18, $y - 44, 'Edit the itinerary and add a stop.', self::MUTED);
    }

    private function dayFooter(Itinerary $itinerary, float $x, float $y, ItineraryDay $day): void
    {
        if ($y < 120) {
            return;
        }

        $p = $this->pdf;

        $spent = $day->items->sum(fn (ItineraryItem $i) => (float) $i->estimated_cost);

        $p->rect($x, $y - 4, $p->width() - self::MARGIN - $x, 0.5, '#DDE5E3');

        $p->text('F1', 9, $x, $y - 20, sprintf('Day %d total · %s', $day->day_number, $this->money($spent)), self::CHARCOAL);

        // A DAY TOTAL THAT COUNTS AN UNPRICED STOP AT ZERO IS NOT A TOTAL.
        // The same claim the cover and the page make, per day, because this is
        // the line a traveller reads when deciding whether today is affordable.
        $dayUnpriced = $day->items
            ->reject(fn (ItineraryItem $item): bool => $item->hasVerifiedPrice())
            ->count();

        if ($dayUnpriced > 0) {
            $p->text('F1', 8, $x, $y - 32, sprintf(
                '%d stop%s here ha%s no published price — this day total counts the verified ones.',
                $dayUnpriced,
                $dayUnpriced === 1 ? '' : 's',
                $dayUnpriced === 1 ? 's' : 've'
            ), self::MUTED);
        }

        $running = 0.0;

        foreach ($day->items->sortBy('sort_order') as $item) {
            $running += (float) $item->estimated_cost;
        }

        $all = $itinerary->days->sum(fn (ItineraryDay $d) => $d->items->sum(fn (ItineraryItem $i) => (float) $i->estimated_cost));

        $p->textRight('F1', 9, $p->width() - self::MARGIN, $y - 20,
            'Trip running total '.$this->money($all), self::MUTED);

        unset($running);
    }

    // -- colophon ---------------------------------------------------------

    private function colophon(Itinerary $itinerary): void
    {
        $p = $this->pdf;

        $p->addPage();
        $p->rect(0, 0, $p->width(), $p->height(), self::TEAL);

        $this->logo(64, 32, self::WHITE);

        $p->tracked('F1', 8, self::MARGIN, $p->height() - 150, 'BEFORE YOU GO', 2.4, self::GOLD);

        $notes = [
            ['Check the hours', 'Opening times and entrance fees change without notice, and they are only as good as the source they were last checked against.'],
            ['Fees are estimates', 'Figures on this sheet are planning estimates from the catalogue, not quotes. Carry cash for small entries and municipal sites.'],
            ['Some places are ungated', 'Where a destination is marked as always open, that means no gate — not that a facility, guide or permit is unnecessary.'],
            ['Respect the sites you visit', 'Many of these are churches, ancestral homes and protected landscapes. Dress for the place and follow posted rules.'],
        ];

        // A NOTE ONLY WHEN THERE IS SOMETHING IT HAS TO WARN ABOUT. Four
        // standing notes is already a page a traveller skims; a fifth about
        // missing prices on a trip where every price is published would be
        // noise wearing a warning's clothes.
        if ($this->unpricedCount($itinerary->days) > 0) {
            $notes[] = ['Some prices are missing', 'Where a stop\'s price is absent, that is a place the catalogue has no verified figure for — not a place that is free. Every curated figure on this sheet carries the source it was read from.'];
        }

        $y = $p->height() - 186;
        $colophonWidth = $p->width() - (self::MARGIN * 2) - 12;

        foreach ($notes as [$heading, $body]) {
            /*
             * THE TICK SITS ON THE TEXT'S CAP HEIGHT, NOT ABOVE ITS BASELINE.
             *
             * `$y` is the BASELINE, and a 12pt Times-Bold capital stands about
             * 8.4pt above it. Drawn from `$y + 8` upward the tick sat entirely
             * above the word it marked — which is exactly the reported "lines
             * aren't aligned with text". It starts a point below the baseline so
             * its 12pt height spans the cap height and a hair of the descender.
             */
            $p->rect(self::MARGIN, $y - 1, 2, 12, self::GOLD);

            $p->text('F2', 12, self::MARGIN + 12, $y, $heading, self::WHITE);

            /*
             * WRAPPED, NOT TRUNCATED.
             *
             * `fit()` drops whole words and ends in an ellipsis, which is right
             * for a destination name — "Minor Basilica of Our Lady of Pi…" is
             * honestly abbreviated — and wrong for a sentence, where "Dress for
             * the place and follow…" is a note that now says less than it was
             * written to say. Prose gets lines.
             */
            $lines = $this->wrap($body, 'F1', 9.5, $colophonWidth, 3);

            foreach ($lines as $i => $line) {
                $p->text('F1', 9.5, self::MARGIN + 12, $y - 15 - ($i * 12), $line, '#9FB3AF');
            }

            $y -= 24 + (count($lines) * 12) + 16;
        }

        $p->rect(self::MARGIN, 118, $p->width() - (self::MARGIN * 2), 0.5, self::SLAB);

        $p->text('F1', 9, self::MARGIN, 96, 'TraMatch · Luzon travel itineraries', self::MUTED);
        $p->text('F1', 9, self::MARGIN, 82, $this->money($itinerary->total_estimated_cost).' estimated across '
            .$itinerary->days->count().' '.(($itinerary->days->count() === 1) ? 'day' : 'days').'.', self::MUTED);
        $p->textRight('F1', 9, $p->width() - self::MARGIN, 96,
            'Generated '.now()->format('j M Y, H:i'), self::MUTED);
    }

    /**
     * HOW MANY STOPS HAVE NO VERIFIED PRICE, ANSWERED BY THE ITEM.
     *
     * The rule is `ItineraryItem::hasVerifiedPrice()` — the same one the web
     * header counts with, so the sheet and the page cannot disagree about
     * whether a total is complete. This only tallies: the days are already
     * loaded, and asking the service would re-query the whole trip to produce
     * a number this document is going to print next to the total.
     */
    private function unpricedCount(Collection $days): int
    {
        return $days
            ->sum(fn (ItineraryDay $day) => $day->items
                ->reject(fn (ItineraryItem $item): bool => $item->hasVerifiedPrice())
                ->count());
    }

    // -- the logo ---------------------------------------------------------

    /**
     * THE LOGO, AS VECTOR PATH OPERATORS.
     *
     * The SVG's y axis points down and PDF's points up, so the CTM carries the
     * only flip and the path data is emitted verbatim. `s = 34 / 316` places a
     * 34pt mark; the translation's y term is `Y + 316 * s`, which is what makes
     * the flipped coordinates land inside the requested box rather than
     * mirrored below it.
     *
     * @param  float  $yTop  distance from the TOP of the page, for the caller's
     *                       convenience; converted here so no caller does it twice
     */
    private function logo(float $x, float $yTop, string $colour, float $size = 34): void
    {
        $d = $this->logoPathData();

        if ($d === null) {
            // A missing or changed logo must not take the download down with it.
            return;
        }

        $s = $size / 316.0;
        $yBottom = $this->pdf->height() - $yTop - $size;

        $this->pdf->saveState();
        $this->pdf->concatMatrix($s, 0, 0, -$s, $x, $yBottom + (316 * $s));
        $this->pdf->svgPath($d, 0.0, $colour);
        $this->pdf->restoreState();
    }

    /**
     * Pull the single `<path d>` out of the Blade component.
     *
     * Reading the component rather than shipping a second copy of the logo is
     * the point: the mark has one home, and a PDF with a stale duplicate of it
     * is a second thing to forget when the brand changes.
     */
    private function logoPathData(): ?string
    {
        $source = @file_get_contents($this->logoPath);

        if ($source === false || ! preg_match('/<path[^>]*\sd="([^"]+)"/s', $source, $m)) {
            return null;
        }

        $d = $m[1];

        /*
         * REFUSE COMMANDS PDF DOES NOT SHARE WITH SVG.
         *
         * SVG's `A` (elliptical arc), `Q`/`T`/`S` (quadratic curves) are NOT
         * PDF's `A`, `v`/`y`, `c`/`s`. Emitting them under PDF's letters would
         * draw a different shape rather than fail, which is the one failure
         * mode a brand mark must not have. The current path uses only
         * M L C H V Z.
         */
        $unsupported = array_values(array_filter(
            preg_split('/\s*([A-Za-z])\s*/', $d, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [],
            fn ($token) => strlen($token) === 1
                && ctype_alpha($token)
                && ! str_contains('MLCHVZmlchvz', $token)
        ));

        if ($unsupported !== []) {
            return null;
        }

        return $d;
    }

    // -- helpers ----------------------------------------------------------

    /** PDF y from a distance measured down from the top of the page. */
    private function y(float $fromTop): float
    {
        return $this->pdf->height() - $fromTop;
    }

    /**
     * MONEY.
     *
     * "PHP", never "₱". The standard-14 fonts are WinAnsiEncoded and that
     * encoding has **no peso sign** — 0xA5 is the yen — so a peso sign written
     * here renders as ¥450 on the traveller's page. Every peso figure anywhere
     * in this app already has a "PHP" or a numeric fallback, so this loses
     * nothing but the glyph.
     */
    private function money(float|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return 'PHP 0';
        }

        return 'PHP '.number_format((float) $amount, 2);
    }

    private function clock(?string $time): string
    {
        if (! $time) {
            return '--:--';
        }

        $parts = explode(':', (string) $time);

        return sprintf('%02d:%02d', (int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0));
    }

    /**
     * THE HOURS, PHRASED THE WAY THEY MEAN.
     *
     * `formatHours()` is the model's renderer and is used for a plain window —
     * but it returns `00:00–23:59` for an `always_open` row, and printing that
     * here told the traveller **"Open 00:00–23:59"** about a mountainside
     * viewpoint with no gate at all. That is not a formatting nicety: it is the
     * model saying the hours are ungated, and the document saying the gate is
     * open at three in the morning. `always_open` gets its own words, and a
     * per-day week gets the summary the model already builds.
     */
    private function hoursLine(?Destination $destination): ?string
    {
        if ($destination === null) {
            return null;
        }

        if ($destination->hasPerDayHours() && $destination->perDayHoursLabel()) {
            return 'Hours '.$destination->perDayHoursLabel();
        }

        if ($destination->hours_kind === 'always_open') {
            return 'Open — no gate';
        }

        return $destination->formatHours()
            ? 'Open '.$destination->formatHours()
            : null;
    }

    private function placeLine($destination): string
    {
        if (! $destination) {
            return '';
        }

        $parts = array_filter([
            trim((string) $destination->municipality),
            trim((string) $destination->province),
        ]);

        return implode(', ', array_unique($parts));
    }

    private function dayTitle(ItineraryDay $day): string
    {
        // EVERY DAY IS HEADED THE SAME WAY. The title used to be the first
        // destination stop's name, so a day opening at a hotel was headed
        // "Day 1" while its neighbour was headed "Camp John Hay" — two shapes
        // of heading for the same kind of page. The stops name themselves on
        // their cards; the day names itself here.
        return 'Day '.$day->day_number;
    }

    private function dayWindow(ItineraryDay $day): string
    {
        $items = $day->items->sortBy('sort_order');

        $first = $items->first()?->start_time;
        $last = $items->last()?->end_time;

        if (! $first || ! $last) {
            return '';
        }

        return $this->clock($first).' – '.$this->clock($last);
    }

    private function dayCount(Collection $days): string
    {
        $count = $days->count();

        return $count.' '.(($count === 1) ? 'day' : 'days');
    }

    private function dateRange(Itinerary $itinerary): ?string
    {
        if (! $itinerary->start_date) {
            return null;
        }

        $start = $this->asDate($itinerary->start_date);
        $end = $start->modify('+'.($itinerary->trip_duration_days - 1).' days');

        if ($end === false) {
            return $start->format('j M Y');
        }

        if ($start->format('Y') === $end->format('Y')) {
            return $start->format('j').' – '.$end->format('j M Y');
        }

        return $start->format('j M Y').' – '.$end->format('j M Y');
    }

    private function longDate(DateTimeInterface|string $date): string
    {
        return $this->asDate($date)->format('l, j F Y');
    }

    /**
     * ALWAYS AN IMMUTABLE, EVEN WHEN GIVEN A MUTABLE ONE.
     *
     * THE BUG THIS EXISTS FOR. A `date` cast hands back a **Carbon**, and
     * Carbon's `modify()` mutates in place and returns `$this`. Returning the
     * cast value unchanged therefore made `$end = $start->modify('+1 day')`
     * assign the SAME object, and `$start->format('j')` afterwards printed the
     * END date — so a two-day trip starting 2 November read "3 – 3 Nov 2026".
     * Converting to `DateTimeImmutable` means `modify()` returns a new instance
     * and `$start` keeps its own date, whatever the cast handed over.
     */
    private function asDate(DateTimeInterface|string $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(
            $date instanceof DateTimeInterface
                ? $date
                : new DateTimeImmutable((string) $date)
        );
    }

    /**
     * Greedy word wrap against the real measure of the font.
     *
     * Uses `widthOf()` rather than a character count, because Times-Bold caps
     * are far wider than lowercase and a character budget wraps a title into a
     * shape that fits nothing.
     *
     * **AN OVERFLOWING TITLE IS ELIPSISED, NOT CUT AT A WORD BOUNDARY.** The
     * first version stopped filling once it reached the last line and then
     * pushed whatever single word it was holding — so a long title rendered as
     * `…the lake country and` / `the`, with a third line carrying one orphan
     * word. An ellipsis says the title continues; a stray `the` says nothing.
     *
     * @return array<int, string>
     */
    private function wrap(string $text, string $font, float $size, float $maxWidth, int $maxLines = 3): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';
        $overflowed = false;

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($this->pdf->widthOf($candidate, $font, $size) <= $maxWidth) {
                $current = $candidate;

                continue;
            }

            if (count($lines) >= $maxLines - 1) {
                // No room left for a whole word, and no partial words either.
                $overflowed = true;

                break;
            }

            $lines[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        if ($lines === []) {
            return [''];
        }

        if ($overflowed) {
            $last = array_key_last($lines);
            $lines[$last] = $this->fit($lines[$last].' more', $font, $size, $maxWidth);
        }

        return $lines;
    }

    /**
     * Shorten a string to fit, with an ellipsis.
     *
     * **An ellipsis, never a mid-word cut.** Truncating a destination name at a
     * character boundary produces "Minor Basilica of Our Lady of Pi…" which is
     * a different place; dropping whole words until it fits is honest about
     * what was left off.
     */
    private function fit(string $text, string $font, float $size, float $maxWidth): string
    {
        if ($text === '' || $this->pdf->widthOf($text, $font, $size) <= $maxWidth) {
            return $text;
        }

        $words = preg_split('/\s+/', trim($text)) ?: [];
        $kept = '';

        foreach ($words as $word) {
            $candidate = $kept === '' ? $word : $kept.' '.$word;

            if ($this->pdf->widthOf($candidate.'…', $font, $size) > $maxWidth) {
                break;
            }

            $kept = $candidate;
        }

        return $kept === '' ? mb_substr($text, 0, 3).'…' : $kept.'…';
    }
}
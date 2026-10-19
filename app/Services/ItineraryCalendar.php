<?php

namespace App\Services;

use App\Models\Itinerary;
use App\Models\ItineraryItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * THE TRIP AS A CALENDAR FILE.
 *
 * A `.ics` is a promise about somebody's day, so the awkward truths of this
 * app's schedule have to survive into it rather than be smoothed over:
 *
 * - **A STOP WITH NO TIME IS NOT INVENTED.** It is written as an ALL-DAY,
 *   `TRANSPARENT`, `STATUS:TENTATIVE` event — it occupies the day without
 *   claiming the traveller is there all day and without inventing 08:00,
 *   and most calendar clients will not mark them busy. Anything else would
 *   put a time in a traveller's calendar that this app never had.
 * - **TIMES ARE UTC (`...Z`), NOT FLOATING.** A floating local time is read
 *   in the VIEWER's timezone, which quietly moves a Baguio morning for
 *   anyone planning the trip from outside the Philippines. `APP_TIMEZONE`
 *   (Asia/Manila) is where the stored times are, and the conversion is here
 *   so exactly one file knows it.
 * - **THE FILE IS A PLAN, NOT A BOOKING.** `X-WR-CALDESC` says so, and the
 *   UID is derived from the stop's id so re-downloading updates the same
 *   events rather than duplicating them.
 *
 * Hand-rolled because the PDF writer had to be, and for the same reasons:
 * nothing embedded, nothing external, nothing that can be missing on the
 * host. The three things a calendar file must get right and that are easy to
 * get wrong are all here and all tested: **CRLF** line endings (a spec
 * requirement, and every parser is quietly forgiving about it), **75-octet
 * line folding** (RFC 5545 §3.1), and **text escaping** of `\`, `;`, `,` and
 * newlines — a stop called "Le Chef, Baguio" is a broken file otherwise.
 */
class ItineraryCalendar
{
    private const CRLF = "\r\n";

    /**
     * A trip with no start date cannot be a calendar: there is no day to
     * put anything on. Returns null and the caller explains, rather than
     * emitting a file of undated events that every client would file under
     * some arbitrary default.
     */
    public function build(Itinerary $itinerary): ?string
    {
        $dated = $itinerary->days->filter(fn ($day) => $day->date !== null);

        if ($dated->isEmpty()) {
            return null;
        }

        $undated = $itinerary->days->count() - $dated->count();
        $untimed = $itinerary->days
            ->flatMap(fn ($day) => $day->items)
            ->filter(fn (ItineraryItem $item) => ! $this->hasTimes($item))
            ->count();

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//TraMatch//Itinerary//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->escape($itinerary->title),
            'X-WR-CALDESC:' . $this->escape($this->describe($itinerary, $undated, $untimed)),
        ];

        $stamp = CarbonImmutable::now('UTC')->format('Ymd\THis\Z');

        foreach ($dated as $day) {
            foreach ($day->items as $item) {
                $lines = array_merge($lines, $this->event(
                    $item,
                    // `date` casts to a MUTABLE Carbon, and `instant()` sets a
                    // time on it. Making it immutable here is the same lesson
                    // as `Itinerary::asDate()`: a mutable cast handed to code
                    // that calls `modify()` has two names for one object.
                    CarbonImmutable::instance($day->date),
                    $stamp
                ));
            }
        }

        $lines[] = 'END:VCALENDAR';

        return $this->fold(implode(self::CRLF, $lines)) . self::CRLF;
    }

    /**
     * @return array<int, string>
     */
    private function event(ItineraryItem $item, CarbonInterface $date, string $stamp): array
    {
        $lines = [
            'BEGIN:VEVENT',
            // Stable across downloads, so re-exporting updates the same
            // events in the traveller's calendar instead of doubling them.
            'UID:itinerary-'.$item->itinerary_day_id.'-item-'.$item->id.'@tramatch',
            'DTSTAMP:'.$stamp,
            'SUMMARY:'.$this->escape($item->placeName()),
        ];

        if ($this->hasTimes($item)) {
            $lines[] = 'DTSTART:'.$this->instant($date, $item->start_time);
            $lines[] = 'DTEND:'.$this->instant($date, $item->end_time);
        } else {
            // ALL-DAY AND TRANSPARENT, and tentative besides. See the class
            // comment: the alternative is inventing a time.
            $lines[] = 'DTSTART;VALUE=DATE:'.$date->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$date->addDay()->format('Ymd');
            $lines[] = 'TRANSP:TRANSPARENT';
            $lines[] = 'STATUS:TENTATIVE';
        }

        if ($location = $this->locationFor($item)) {
            $lines[] = 'LOCATION:'.$this->escape($location);
        }

        if (! $this->hasTimes($item)) {
            $lines[] = 'DESCRIPTION:'.$this->escape(
                'No time is set for this stop. Reflow fills the times from your own plan.'
            );
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * WHAT THIS FILE IS, IN THE FILE ITSELF.
     *
     * A calendar entry reads like an appointment, and this app sells neither
     * bookings nor availability (ADR 0003). The description says which of the
     * day's stops have no time, because the alternative — a file that looks
     * fully planned — is the exact misrepresentation the rest of this
     * product refuses to make.
     */
    private function describe(Itinerary $itinerary, int $undated, int $untimed): string
    {
        $description = 'A plan, not a booking. TraMatch holds no reservation for these places.';

        if ($undated > 0) {
            $description .= sprintf(' %d day(s) carry no date and are not in this file.', $undated);
        }

        if ($untimed > 0) {
            $description .= sprintf(
                ' %d stop(s) have no time set and appear as all-day entries.',
                $untimed
            );
        }

        return $description;
    }

    private function locationFor(ItineraryItem $item): ?string
    {
        if ($item->isCustom()) {
            return $item->custom_address;
        }

        if ($item->place) {
            return $item->place->municipality.', '.$item->place->province;
        }

        if ($item->destination) {
            return $item->destination->municipality.', '.$item->destination->province;
        }

        return null;
    }

    private function hasTimes(ItineraryItem $item): bool
    {
        return $item->start_time !== null && $item->end_time !== null;
    }

    /**
     * `H:i` on the day's date, read in the app's timezone and written in UTC.
     */
    private function instant(CarbonInterface $date, string $time): string
    {
        $h = (int) substr($time, 0, 2);
        $i = (int) substr($time, 3, 2);

        return CarbonImmutable::instance($date)
            ->setTime($h, $i)
            ->setTimezone('UTC')
            ->format('Ymd\THis\Z');
    }

    /**
     * RFC 5545 §3.3.11 TEXT escaping. Without the comma escape, a stop
     * called "Le Chef, Baguio" is parsed as two properties.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\;', '\,'],
            $value
        );
    }

    /**
     * RFC 5545 §3.1: lines longer than 75 OCTETS are folded with a CRLF and a
     * single space. Counted in bytes, never characters, and never split
     * inside a multibyte sequence — "Pinto" (6 characters, 7 bytes) is
     * exactly where a character-counting fold corrupts the file.
     */
    private function fold(string $calendar): string
    {
        $folded = [];

        foreach (explode(self::CRLF, $calendar) as $line) {
            $folded[] = $this->foldLine($line);
        }

        return implode(self::CRLF, $folded);
    }

    private function foldLine(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $pieces = [];
        $current = '';

        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            // +1 for the space that continues a folded line.
            if (strlen($current) + strlen($character) > ($pieces === [] ? 75 : 74)) {
                $pieces[] = $current;
                $current = '';
            }

            $current .= $character;
        }

        $pieces[] = $current;

        // A continuation is a leading space, which the reader strips back off.
        return implode(self::CRLF . ' ', $pieces);
    }
}

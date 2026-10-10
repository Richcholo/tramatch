<?php

namespace App\Services\Crawling;

use App\Models\Destination;
use App\Models\DestinationSource;
use App\Models\DestinationUpdateProposal;
use DOMDocument;
use DOMXPath;

class DestinationSourceParser
{
    private const MIN_WINDOW_MINUTES = 120;

    private const TIME_FIELDS = ['opening_time', 'closing_time'];

    public function createProposals(
        DestinationSource $source,
        array $extracted
    ): int {
        $destination = $source->destination;

        if (!$destination) {
            return 0;
        }

        $created = 0;

        foreach ($extracted as $field => $result) {
            $oldValue = $destination->getAttribute($field);
            $newValue = trim((string) $result['value']);

            if ($newValue === '' || $this->sameValue($field, $oldValue, $newValue)) {
                continue;
            }

            if (
                in_array($field, self::TIME_FIELDS, true)
                && in_array(
                    $destination->hours_kind,
                    [Destination::HOURS_ALWAYS_OPEN, Destination::HOURS_PER_DAY],
                    true
                )
            ) {
                continue;
            }


            $keys = [
                'destination_id' => $destination->id,
                'destination_source_id' => $source->id,
                'field_name' => $field,
                'status' => 'pending',
            ];

            $isNew = !DestinationUpdateProposal::where($keys)->exists();

            DestinationUpdateProposal::updateOrCreate(
                $keys,
                [
                    'old_value' => $oldValue,
                    'proposed_value' => $newValue,
                    'confidence' => $result['confidence'],
                ]
            );

            if ($isNew) {
                $created++;
            }
        }

        return $created;
    }

    private function sameValue(string $field, mixed $stored, string $proposed): bool
    {
        if (in_array($field, self::TIME_FIELDS, true)) {
            $destination = new Destination();

            return $destination->formatTime((string) $stored)
                === $destination->formatTime($proposed);
        }

        return trim((string) $stored) === $proposed;
    }

    public function extract(string $html): array
    {
        libxml_use_internal_errors(true);

        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);

        $xpath = new DOMXPath($document);
        $values = [];

        $text = $this->visibleText($html);

        $this->extractTextHours($text, $values);

        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $json = json_decode($script->textContent, true);

            if (!is_array($json)) {
                continue;
            }

            foreach ($this->flattenJsonLd($json) as $entity) {
                $type = $entity['@type'] ?? null;
                $types = is_array($type) ? $type : [$type];

                if (!$this->isPlaceEntity($types)) {
                    continue;
                }

                $this->extractOffer($entity, $values);
                $this->extractOpeningHours($entity, $values);
            }
        }

        libxml_clear_errors();

        return $values;
    }

    public function feeNotice(string $html): ?string
    {
        $text = $this->visibleText($html);

        if (preg_match('/\b(admission|entrance fee|entrance fees|rates)\b/i', $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $match[0][1];
        $notice = trim(substr($text, $start, 700));

        if (!preg_match('/(?:\bphp\b|\bp\b|₱)\s?\d/i', $notice)) {
            return null;
        }

        return $notice;
    }

    private function extractTextHours(string $text, array &$values): void
    {
        $windows = [];

        preg_match_all(
            '/(?<![\d:])(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*(?:to|-|–|—|until)\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?(?![\d:])/i',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $hasTimeMarker = ($match[2] ?? '') !== ''
                || ($match[5] ?? '') !== ''
                || ($match[3] ?? '') !== ''
                || ($match[6] ?? '');

            if (!$hasTimeMarker) {
                continue;
            }

            $opens = $this->toMinutes($match[1], $match[2] ?? '', $match[3] ?? '');
            $closes = $this->toMinutes($match[4], $match[5] ?? '', $match[6] ?? '');

            if ($opens === null || $closes === null || $opens >= $closes) {
                continue;
            }

            if (($closes - $opens) < self::MIN_WINDOW_MINUTES) {
                continue;
            }

            $windows[sprintf('%02d:%02d-%02d:%02d', intdiv($opens, 60), $opens % 60, intdiv($closes, 60), $closes % 60)] = [
                'opens' => $opens,
                'closes' => $closes,
            ];
        }

        if (count($windows) !== 1) {
            return;
        }

        $window = reset($windows);

        $values['opening_time'] = [
            'value' => $this->formatMinutes($window['opens']),
            'confidence' => 55,
        ];

        $values['closing_time'] = [
            'value' => $this->formatMinutes($window['closes']),
            'confidence' => 55,
        ];

        $values['operating_status'] = [
            'value' => 'open',
            'confidence' => 45,
        ];
    }

    private function toMinutes(string $hours, string $minutes, string $meridiem): ?int
    {
        $hour = (int) $hours;
        $minute = $minutes === '' ? 0 : (int) $minutes;

        if ($hour < 0 || $hour > 24 || $minute < 0 || $minute > 59) {
            return null;
        }

        $meridiem = strtolower($meridiem);

        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        }

        if ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }

        $total = $hour * 60 + $minute;

        return $total > 0 && $total < 24 * 60 ? $total : null;
    }

    private function formatMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function visibleText(string $html): string
    {
        $html = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/is', ' ', $html);

        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private function extractOffer(array $entity, array &$values): void
    {
        $offers = $entity['offers'] ?? null;

        if (is_array($offers) && array_is_list($offers)) {
            $offers = $offers[0] ?? null;
        }

        if (!is_array($offers)) {
            return;
        }

        $price = $offers['price'] ?? null;
        $currency = $offers['priceCurrency'] ?? 'PHP';

        if (!is_numeric($price)) {
            return;
        }

        if (strtoupper((string) $currency) !== 'PHP') {
            return;
        }

        $values['entrance_fee'] = [
            'value' => number_format((float) $price, 2, '.', ''),
            'confidence' => 70,
        ];

        $values['entrance_fee_display'] = [
            'value' => '₱' . number_format((float) $price, 2),
            'confidence' => 70,
        ];

    }

    private function extractOpeningHours(array $entity, array &$values): void
    {
        $hours = $entity['openingHoursSpecification'] ?? null;

        if (is_array($hours) && array_is_list($hours)) {
            $hours = $hours[0] ?? null;
        }

        if (!is_array($hours)) {
            return;
        }

        $opens = $hours['opens'] ?? null;
        $closes = $hours['closes'] ?? null;

        if ($opens && preg_match('/\A\d{2}:\d{2}/', $opens)) {
            $values['opening_time'] = [
                'value' => substr($opens, 0, 5),
                'confidence' => 75,
            ];
        }

        if ($closes && preg_match('/\A\d{2}:\d{2}/', $closes)) {
            $values['closing_time'] = [
                'value' => substr($closes, 0, 5),
                'confidence' => 75,
            ];
        }

        if ($opens || $closes) {
            $values['operating_status'] = [
                'value' => 'open',
                'confidence' => 60,
            ];
        }
    }

    private function flattenJsonLd(array $data): array
    {
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            return $this->entityList($data['@graph']);
        }

        if (array_is_list($data)) {
            return $this->entityList($data);
        }

        return [$data];
    }

    private function entityList(array $data): array
    {
        if ($data !== [] && !array_is_list($data)) {
            return [$data];
        }

        return array_values(array_filter(
            $data,
            fn ($item) => is_array($item)
        ));
    }

    private function isPlaceEntity(array $types): bool
    {
        $acceptedTypes = [
            'place',
            'touristattraction',
            'localbusiness',
            'resort',
            'museum',
            'park',
            'civicstructure',
        ];

        foreach ($types as $type) {
            if (in_array(strtolower((string) $type), $acceptedTypes, true)) {
                return true;
            }
        }

        return false;
    }
}

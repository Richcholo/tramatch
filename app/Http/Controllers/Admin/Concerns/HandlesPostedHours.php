<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Destination;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Posted opening-hours handling, shared by the admin controllers that curate
 * visitable rows.
 *
 * Moved out of `Admin\DestinationController` verbatim so the upcoming
 * `Admin\PlaceController` reads the same rules rather than a second copy of
 * them. A restaurant whose hours form normalises times one way while a
 * destination's does it another is exactly the drift this prevents — and the
 * fingerprint is the load-bearing half: it decides what counts as an hours
 * change, which is what restamps `last_verified_at`.
 *
 * `Destination::daySlugs()` and `Destination::formatTime()` are read as static
 * calls, and both resolve through `HasOpeningHours`, which `Place` shares — so
 * these rules already apply to either model without a branch.
 */
trait HandlesPostedHours
{
    private function joinClosedDays(mixed $days): ?string
    {
        if (!is_array($days)) {
            return $days === null ? null : (string) $days;
        }

        $kept = array_values(array_intersect(Destination::daySlugs(), $days));

        return $kept === [] ? null : implode(',', $kept);
    }

    private function cleanDailyHours(mixed $hours): ?array
    {
        if (!is_array($hours)) {
            return null;
        }

        $clean = [];

        foreach (Destination::daySlugs() as $day) {
            $window = $hours[$day] ?? null;

            if (!is_array($window)) {
                continue;
            }

            if (($window['closed'] ?? false) === true) {
                $clean[$day] = ['closed' => true];

                continue;
            }

            $open = $this->normaliseTimeValue($window['open'] ?? null);
            $close = $this->normaliseTimeValue($window['close'] ?? null);

            if ($open === null || $close === null || $open === $close) {
                continue;
            }

            $clean[$day] = ['open' => $open, 'close' => $close];
        }

        return $clean === [] ? null : $clean;
    }

    private function normaliseTimeValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match('/\A(\d{1,2}):(\d{2})(?::\d{2})?\z/', $value, $match) !== 1) {
            return null;
        }

        $hour = (int) $match[1];
        $minute = (int) $match[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function hasAnyHours(array $data): bool
    {
        if (($data['opening_time'] ?? null) || ($data['closing_time'] ?? null)) {
            return true;
        }

        if (!empty($data['closed_days'])) {
            return true;
        }

        foreach ((array) ($data['daily_hours'] ?? []) as $window) {
            if (is_array($window)) {
                return true;
            }
        }

        return false;
    }

    private function hoursChanged(Model $model, array $data): bool
    {
        return $this->hoursFingerprint($model) !== $this->hoursFingerprint(
            $model,
            $data
        );
    }

    private function hoursFingerprint(
        Model $model,
        array $data = []
    ): string {
        $daily = $data['daily_hours'] ?? $model->daily_hours;

        if (is_string($daily)) {
            $daily = json_decode($daily, true);
        }

        $dailyHours = collect(Destination::daySlugs())
            ->mapWithKeys(function (string $day) use ($daily) {
                $window = is_array($daily) ? ($daily[$day] ?? null) : null;

                if ($window === null) {
                    return [$day => '-'];
                }

                if (($window['closed'] ?? false) === true) {
                    return [$day => 'closed'];
                }

                return [$day => ($window['open'] ?? '').'-'.($window['close'] ?? '')];
            })
            ->implode(',');

        $closedDays = $data['closed_days'] ?? $model->closedDayList();

        return implode('|', [
            (string) ($data['opening_time'] ?? $model->opening_time),
            (string) ($data['closing_time'] ?? $model->closing_time),
            is_array($closedDays)
                ? implode(',', array_values(array_intersect(
                    Destination::daySlugs(),
                    $closedDays
                )))
                : implode(',', $model->closedDayList()),
            $dailyHours,
            (string) ($data['operating_status'] ?? $model->operating_status),
            (string) ($data['hours_kind'] ?? $model->hours_kind),
            (string) ($data['hours_source_url'] ?? $model->hours_source_url),
            (string) ($data['hours_note'] ?? $model->hours_note),
        ]);
    }

    /**
     * Reduce a posted `HH:MM:SS` to `HH:MM` before validation.
     *
     * `opening_time` and `closing_time` are TIME columns, so the driver hands
     * them back as `09:00:00`. The admin form pre-fills those raw values into
     * `<input type="time">`, the browser resubmits them untouched along with
     * every other field, and `date_format:H:i` rejects the seconds. The result
     * was that editing *any* field on a row that had opening hours failed with
     * "the opening time field must match the format H:i", for a field the admin
     * never touched.
     *
     * Anything unparseable is returned unchanged rather than nulled, and that is
     * the whole point. Nulling it would make the `nullable` rule skip the field
     * entirely, so a genuinely tampered value would be silently discarded and
     * stored as "no hours". Leaving it alone keeps the validation error, which is
     * the only thing standing between a mistyped time and a wrong opening window.
     *
     * @return array<string, string|null>
     */
    private function normaliseTime(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $trimmed;
        }

        return Destination::formatTime($trimmed) ?? $trimmed;
    }

    /**
     * Normalise every posted time field, in place on the request.
     *
     * Merged rather than applied after validation so the canonical `HH:MM` is
     * what gets validated *and* stored, and so a validation failure flashes the
     * reduced value back into the form through `old()`.
     *
     * @return array<string, mixed>
     */
    private function withNormalisedTimes(Request $request): array
    {
        $normalised = [];

        foreach (['opening_time', 'closing_time'] as $field) {
            if ($request->exists($field)) {
                $normalised[$field] = $this->normaliseTime($request->input($field));
            }
        }

        $daily = $request->input('daily_hours');

        if (is_array($daily)) {
            foreach ($daily as $day => $window) {
                if (! is_array($window)) {
                    continue;
                }

                foreach (['open', 'close'] as $edge) {
                    if (array_key_exists($edge, $window)) {
                        $normalised['daily_hours'][$day][$edge] = $this->normaliseTime($window[$edge]);
                    }
                }
            }
        }

        return $normalised;
    }
}

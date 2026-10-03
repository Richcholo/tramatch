<?php

namespace App\Http\Requests;

use App\Models\Itinerary;
use App\Models\ItineraryItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the itinerary editor payload.
 *
 * Items are keyed by id so reordering never renumbers form fields. Keys the
 * browser invented are negative (see ItineraryEditor) and become new stops;
 * positive keys must be items of this itinerary, which is the only thing
 * stopping a traveller from editing someone else's trip by posting an id.
 */
class UpdateItineraryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $itinerary = $this->route('itinerary');

        return $itinerary instanceof Itinerary
            && $this->user()?->id === $itinerary->user_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $dayIds = $this->itineraryDayIds();

        return [
            'items' => ['nullable', 'array'],
            'items.*' => ['array'],

            'items.*.day_id' => [
                'required',
                'integer',
                Rule::in($dayIds),
            ],

            'items.*.sort_order' => [
                'required',
                'integer',
                'min:1',
                'max:999',
            ],

            // No window is enforced here on purpose. A traveller may legitimately
            // want 05:00 for a hike registration, well outside the 08:00 the
            // generator starts at.
            'items.*.start_time' => ['nullable', 'date_format:H:i'],
            'items.*.end_time' => ['nullable', 'date_format:H:i'],

            'items.*.destination_id' => [
                'required',
                'integer',
                'exists:destinations,id',
            ],

            'items.*.estimated_cost' => [
                'nullable',
                'numeric',
                'min:0',
                'max:99999999.99',
            ],

            'items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function messages(): array
    {
        return [
            'items.*.day_id.in' => 'One of the days you edited does not belong to this trip.',
            'items.*.destination_id.exists' => 'One of the places you added is no longer available.',
            'items.*.start_time.date_format' => 'Start times must look like 09:30.',
            'items.*.end_time.date_format' => 'End times must look like 11:45.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateItemKeys($validator);
                $this->validateWindows($validator);
            },
        ];
    }

    /**
     * Keys are ids, and ids are not something a validation rule can reach, so
     * each one is checked against the stops this itinerary actually owns. A key
     * that is a plain negative number is a stop the browser just added.
     */
    private function validateItemKeys(Validator $validator): void
    {
        $items = $this->input('items', []);

        if (! is_array($items)) {
            return;
        }

        $owned = ItineraryItem::query()
            ->whereIn('itinerary_day_id', $this->itineraryDayIds())
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        foreach (array_keys($items) as $key) {
            $key = (string) $key;

            if (ctype_digit($key) && $key !== '0') {
                if (! $owned->contains($key)) {
                    $validator->errors()->add(
                        'items',
                        'One of the stops you edited does not belong to this trip.'
                    );
                }

                continue;
            }

            if (! preg_match('/\A-[1-9][0-9]{0,5}\z/', $key)) {
                $validator->errors()->add(
                    'items',
                    'The editor sent a stop this page cannot recognise.'
                );
            }
        }
    }

    /**
     * A stop that ends before it starts is a typo, and storing it would print a
     * backwards window on the trip page forever.
     */
    private function validateWindows(Validator $validator): void
    {
        $items = $this->input('items', []);

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $fields) {
            if (! is_array($fields)) {
                continue;
            }

            $start = $fields['start_time'] ?? null;
            $end = $fields['end_time'] ?? null;

            if (! $start || ! $end) {
                continue;
            }

            if (strtotime((string) $end) <= strtotime((string) $start)) {
                $validator->errors()->add(
                    'items',
                    'A stop cannot finish before it starts.'
                );

                return;
            }
        }
    }

    /**
     * @return array<int, int>
     */
    private function itineraryDayIds(): array
    {
        $itinerary = $this->route('itinerary');

        if (! $itinerary instanceof Itinerary) {
            return [];
        }

        return $itinerary->days()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
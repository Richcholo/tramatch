<?php

namespace App\Http\Requests;

use App\Models\Itinerary;
use App\Models\ItineraryItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The draft the editor sends when the traveller asks for a day's times to be
 * recomputed. Nothing is written, so it carries only what reflow needs: the
 * order of the stops and the window each one was given.
 *
 * Catalogue references are shape-checked, never resolved: a reflow preview on
 * an unvalidated draft must not 404 a traveller who is mid-edit. Durations
 * come from the typed window first and the resolved stop second, so a meal or
 * a custom entry reflows exactly like an attraction.
 */
class ScheduleItineraryRequest extends FormRequest
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
        return [
            'items' => ['nullable', 'array'],
            'items.*' => ['array'],
            'items.*.day_id' => [
                'required',
                'integer',
                Rule::in(
                    $this->route('itinerary')->days()->pluck('id')->all()
                ),
            ],
            'items.*.sort_order' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.start_time' => ['nullable', 'date_format:H:i'],
            'items.*.end_time' => ['nullable', 'date_format:H:i'],
            'items.*.destination_id' => ['nullable', 'integer'],
            'items.*.item_type' => ['nullable', Rule::in(ItineraryItem::TYPES)],
            'items.*.place_id' => ['nullable', 'integer'],
            'items.*.custom_name' => ['nullable', 'string', 'max:150'],
            'items.*.custom_address' => ['nullable', 'string', 'max:500'],
            'items.*.custom_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'items.*.custom_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function messages(): array
    {
        return [
            'items.*.day_id.in' => 'One of the days you edited does not belong to this trip.',
            'items.*.start_time.date_format' => 'Start times must look like 09:30.',
            'items.*.end_time.date_format' => 'End times must look like 11:45.',
        ];
    }
}
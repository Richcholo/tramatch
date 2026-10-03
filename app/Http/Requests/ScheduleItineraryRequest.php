<?php

namespace App\Http\Requests;

use App\Models\Itinerary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The draft the editor sends when the traveller asks for a day's times to be
 * recomputed. Nothing is written, so it carries only what reflow needs: the
 * order of the stops and the window each one was given.
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
            'items.*.destination_id' => ['required', 'integer'],
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
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * THE TRAVELLER'S OWN CEILING, OR NONE AT ALL.
 *
 * `nullable` is the load-bearing rule and it is not "be lenient": a blank
 * field CLEARS the budget, which is the only way a traveller can say "I no
 * longer have a ceiling in mind" — and it has to be a real state rather than
 * a zero, because ₱0 of budget would render as a trip that is immediately
 * and absurdly over.
 *
 * The floor is 1 rather than 0 for the same reason. A peso budget is a
 * ceiling the traveller states; 0 is not a budget, it is a shrug typed into
 * a number field.
 *
 * The maximum exists because the column is decimal(10,2) and because a
 * billion-peso trip is a typo, not a plan.
 *
 * A blank field arrives as null (ConvertEmptyStringsToNull) and passes
 * `nullable`, so `validated()` carries a real null: that is how a traveller
 * REMOVES their ceiling. An absent field is treated the same way by the
 * controller, because this endpoint is a write of the budget and nothing
 * else — not a partial edit that leaves unmentioned columns alone.
 */
class UpdateTripBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is enforced in the controller with the same
        // ensureOwner() every other write on this resource uses; a
        // request-level guess at it would be a second implementation.
        return true;
    }

    public function rules(): array
    {
        return [
            'budget_amount' => ['nullable', 'numeric', 'min:1', 'max:10000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'budget_amount.min' => 'A budget has to be at least ₱1 — a zero budget is not a budget.',
            'budget_amount.max' => 'That is larger than this app can hold. Check the figure.',
            'budget_amount.numeric' => 'A budget is a peso amount, like 15000.',
        ];
    }
}

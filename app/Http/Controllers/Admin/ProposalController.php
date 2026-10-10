<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DestinationUpdateProposal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ProposalController extends Controller
{
    private const APPROVED_FIELDS = [
        'entrance_fee',
        'entrance_fee_display',
        'opening_time',
        'closing_time',
        'operating_status',
    ];

    public function index(): View
    {
        $proposals = DestinationUpdateProposal::query()
            ->with(['destination', 'source'])
            ->where('status', 'pending')
            ->latest()
            ->paginate(20);

        return view('admin.proposals.index', compact('proposals'));
    }

    public function approve(
        DestinationUpdateProposal $proposal
    ): RedirectResponse {
        try {
            $this->applyProposal($proposal);

            return back()->with(
                'status',
                'Proposal approved and destination updated.'
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'proposal' => $exception->getMessage(),
            ]);
        }
    }

    public function reject(
        DestinationUpdateProposal $proposal
    ): RedirectResponse {
        $proposal->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Proposal rejected.');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'proposal_ids' => ['required', 'array', 'min:1'],
            'proposal_ids.*' => ['integer', 'distinct', 'exists:destination_update_proposals,id'],
            'action' => ['required', 'in:approve,reject'],
        ]);

        $proposals = DestinationUpdateProposal::whereIn(
            'id',
            $validated['proposal_ids']
        )->where('status', 'pending')->get();

        $processed = 0;
        $failed = 0;

        foreach ($proposals as $proposal) {
            try {
                if ($validated['action'] === 'approve') {
                    $this->applyProposal($proposal);
                } else {
                    $proposal->update([
                        'status' => 'rejected',
                        'reviewed_by' => auth()->id(),
                        'reviewed_at' => now(),
                    ]);
                }

                $processed++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return redirect()
            ->route('admin.proposals.index')
            ->with(
                'status',
                "Processed {$processed} proposal(s). {$failed} proposal(s) failed."
            );
    }

    private function applyProposal(
        DestinationUpdateProposal $proposal
    ): void {
        if (!in_array($proposal->field_name, self::APPROVED_FIELDS, true)) {
            throw new RuntimeException(
                'This field is not approved for automatic admin application.'
            );
        }

        $value = $this->validatedValue(
            $proposal->field_name,
            $proposal->proposed_value
        );

        DB::transaction(function () use ($proposal, $value) {
            $proposal->destination->update([
                $proposal->field_name => $value,
            ]);

            $proposal->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            if (in_array($proposal->field_name, [
                'entrance_fee',
                'entrance_fee_display',
            ], true)) {
                $proposal->destination->update([
                    'price_verified_at' => now(),
                ]);
            }

            if (in_array($proposal->field_name, [
                'opening_time',
                'closing_time',
                'operating_status',
            ], true)) {
                $proposal->destination->update([
                    'last_verified_at' => now(),
                ]);
            }
        });
    }

    private function validatedValue(
        string $field,
        mixed $value
    ): mixed {
        $value = trim((string) $value);

        if (in_array($field, ['latitude', 'longitude', 'entrance_fee'], true)) {
            if (!is_numeric($value)) {
                throw new RuntimeException(
                    'The proposed numeric value is invalid.'
                );
            }

            return (float) $value;
        }

        if (in_array($field, ['opening_time', 'closing_time'], true)) {
            if (!preg_match('/\A\d{2}:\d{2}\z/', $value)) {
                throw new RuntimeException(
                    'The proposed time value is invalid.'
                );
            }

            return $value;
        }

        if ($field === 'operating_status') {
            if (!in_array($value, [
                'open',
                'closed',
                'temporarily_closed',
                'seasonal',
                'unknown',
            ], true)) {
                throw new RuntimeException(
                    'The proposed operating status is invalid.'
                );
            }
        }

        return $value;
    }
}

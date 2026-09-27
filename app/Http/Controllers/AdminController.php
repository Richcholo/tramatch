<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\DestinationSource;
use App\Models\DestinationUpdateProposal;
use App\Models\Review;
use App\Models\Tag;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'destinationCount' => Destination::count(),
            'tagCount' => Tag::count(),
            'reviewCount' => Review::count(),
            'pendingReviewCount' => Review::where(
                'status',
                'pending'
            )->count(),
            'sourceCount' => DestinationSource::count(),
            'successfulSourceCount' => DestinationSource::where(
                'status',
                'success'
            )->count(),
            'failedSourceCount' => DestinationSource::where(
                'status',
                'failed'
            )->count(),
            'pendingProposalCount' => DestinationUpdateProposal::where(
                'status',
                'pending'
            )->count(),
        ]);
    }
}
<?php

namespace App\Http\Controllers;

use App\Models\Destination;
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
            'pendingReviewCount' => Review::where('status', 'pending')->count(),
        ]);
    }
}
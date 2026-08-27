<?php

namespace App\Http\Controllers;

use App\Services\RecommendationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RecommendationController extends Controller
{
    public function __invoke(RecommendationService $service): View
    {
        $user = Auth::user();

        if (!$user->travelProfile) {
            return view('recommendations.index', [
                'recommendations' => collect(),
                'needsProfile' => true,
            ]);
        }

        return view('recommendations.index', [
            'recommendations' => $service->recommend($user),
            'needsProfile' => false,
        ]);
    }
}
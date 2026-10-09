<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\ItineraryItem;
use App\Models\Review;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DestinationController extends Controller
{
    public function index(): View
    {
        $destinations = Destination::with('tags')
            ->latest()
            ->paginate(15);

        return view('admin.destinations.index', compact('destinations'));
    }

    public function create(): View
    {
        $tags = Tag::orderBy('name')->get();

        return view('admin.destinations.create', compact('tags'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $tagIds = $data['tags'] ?? [];

        unset($data['tags']);

        $data['slug'] = Str::slug($data['name']);

        $destination = Destination::create($data);

        $destination->tags()->sync($tagIds);

        return redirect()
            ->route('admin.destinations.index')
            ->with('status', 'Destination created.');
    }

    public function edit(Destination $destination): View
    {
        $tags = Tag::orderBy('name')->get();
        $selectedTags = $destination->tags->pluck('id')->all();

        return view(
            'admin.destinations.edit',
            compact('destination', 'tags', 'selectedTags')
        );
    }

    public function update(
        Request $request,
        Destination $destination
    ): RedirectResponse {
        $data = $this->validated($request);
        $tagIds = $data['tags'] ?? [];

        unset($data['tags']);

        $data['slug'] = Str::slug($data['name']);

        $destination->update($data);
        $destination->tags()->sync($tagIds);

        return redirect()
            ->route('admin.destinations.index')
            ->with('status', 'Destination updated.');
    }

    public function archive(Destination $destination): RedirectResponse
    {
        $destination->update([
            'is_active' => false,
        ]);

        return back()->with(
            'status',
            'Destination archived and removed from the public catalog.'
        );
    }

    public function restore(Destination $destination): RedirectResponse
    {
        $destination->update([
            'is_active' => true,
        ]);

        return back()->with(
            'status',
            'Destination restored to the public catalog.'
        );
    }

    public function destroy(Destination $destination): RedirectResponse
    {
        DB::transaction(function () use ($destination) {
            Review::where(
                'destination_id',
                $destination->id
            )->delete();

            DestinationSwipe::where(
                'destination_id',
                $destination->id
            )->delete();

            ItineraryItem::where(
                'destination_id',
                $destination->id
            )->delete();

            $destination->tags()->detach();
            $destination->delete();
        });

        return redirect()
            ->route('admin.destinations.index')
            ->with('status', 'Destination permanently deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'description' => [
                'required',
                'string',
            ],

            'province' => [
                'required',
                'string',
                'max:100',
            ],

            'municipality' => [
                'nullable',
                'string',
                'max:100',
            ],

            'latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'budget_level' => [
                'required',
                'in:economy,mid-range,premium',
            ],

            'entrance_fee' => [
                'required',
                'numeric',
                'min:0',
            ],

            'estimated_cost' => [
                'required',
                'numeric',
                'min:0',
            ],

            'recommended_minutes' => [
                'required',
                'integer',
                'min:15',
                'max:1440',
            ],

            'opening_time' => [
                'nullable',
                'date_format:H:i',
            ],

            'closing_time' => [
                'nullable',
                'date_format:H:i',
            ],

            'image_url' => [
                'nullable',
                'url',
                'max:500',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'tags' => [
                'required',
                'array',
                'min:1',
            ],

            'tags.*' => [
                'integer',
                'exists:tags,id',
            ],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
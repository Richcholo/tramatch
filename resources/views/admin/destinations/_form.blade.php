@csrf

<div class="grid gap-5 md:grid-cols-2">
    <label class="block md:col-span-2"><span class="text-sm font-medium">Name</span><input name="name" value="{{ old('name', $destination->name ?? '') }}" required class="mt-2 w-full rounded-lg border-slate-300"></label>
    <label class="block md:col-span-2"><span class="text-sm font-medium">Description</span><textarea name="description" rows="5" required class="mt-2 w-full rounded-lg border-slate-300">{{ old('description', $destination->description ?? '') }}</textarea></label>
    <label class="block"><span class="text-sm font-medium">Province</span><input name="province" value="{{ old('province', $destination->province ?? '') }}" required class="mt-2 w-full rounded-lg border-slate-300"></label>
    <label class="block"><span class="text-sm font-medium">Municipality</span><input name="municipality" value="{{ old('municipality', $destination->municipality ?? '') }}" class="mt-2 w-full rounded-lg border-slate-300"></label>
    <label class="block"><span class="text-sm font-medium">Latitude</span><input name="latitude" type="number" step="any" value="{{ old('latitude', $destination->latitude ?? '') }}" required class="mt-2 w-full rounded-lg border-slate-300"></label>
    <label class="block"><span class="text-sm font-medium">Longitude</span><input name="longitude" type="number" step="any" value="{{ old('longitude', $destination->longitude ?? '') }}" required class="mt-2 w-full rounded-lg border-slate-300"></label>
    <label class="block"><span class="text-sm font-medium">Budget</span><select name="budget_level" class="mt-2 w-full rounded-lg border-slate-300">@foreach (['economy', 'mid-range', 'premium'] as $budget)<option value="{{ $budget }}" @selected(old('budget_level', $destination->budget_level ?? 'economy') === $budget)>{{ ucfirst($budget) }}</option>@endforeach</select></label>
    <label class="block"><span class="text-sm font-medium">Entrance fee</span><input name="entrance_fee" type="number" step="0.01" min="0" value="{{ old('entrance_fee', $destination->entrance_fee ?? 0) }}" required class="mt-2 w-full rounded-lg border-slate-300"></label>
    <label class="block"><span class="text-sm font-medium">Estimated cost</span><input name="estimated_cost" type="number" step="0.01" min="0" value="{{ old('estimated_cost', $destination->estimated_cost ?? 0) }}" required class="mt-2 w-full rounded-lg border-slate-300"></label>
    <label class="block"><span class="text-sm font-medium">Recommended minutes</span><input name="recommended_minutes" type="number" min="15" value="{{ old('recommended_minutes', $destination->recommended_minutes ?? 120) }}" required class="mt-2 w-full rounded-lg border-slate-300"></label>
    <label class="block md:col-span-2"><span class="text-sm font-medium">Image URL</span><input name="image_url" type="url" value="{{ old('image_url', $destination->image_url ?? '') }}" class="mt-2 w-full rounded-lg border-slate-300"></label>
</div>

<div class="mt-6">
    <p class="text-sm font-medium">Tags</p>
    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($tags as $tag)
            <label class="flex items-center gap-2 rounded-lg border p-3"><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, old('tags', $selectedTags ?? [])))><span>{{ $tag->name }}</span></label>
        @endforeach
    </div>
</div>

<label class="mt-6 flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $destination->is_active ?? true))><span class="text-sm">Active and visible to travelers</span></label>

<button class="mt-6 rounded-lg bg-teal-700 px-5 py-3 font-medium text-white">Save destination</button>
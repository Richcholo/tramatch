<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                Operations / Reviews
            </p>

            <h1 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal sm:text-6xl">
                Traveler feedback.
            </h1>

            <a
                href="{{ route('admin.dashboard') }}"
                class="mt-5 inline-flex rounded-full border border-boracay-light px-5 py-3 text-sm font-semibold text-benguet-charcoal transition hover:bg-island-white"
            >
                ← Admin dashboard
            </a>
        </div>
    </x-slot>

    <div class="space-y-8">
        @if (session('status'))
            <div class="rounded-2xl border border-boracay bg-boracay-light p-4 text-benguet-charcoal">
                {{ session('status') }}
            </div>
        @endif

        <div class="overflow-x-auto rounded-[2rem] bg-island-white shadow-sm ring-1 ring-boracay-light">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-boracay-light bg-palawan-sand">
                    <tr>
                        <th class="px-6 py-5 font-bold text-volcanic-teal">Reviewer</th>
                        <th class="px-6 py-5 font-bold text-volcanic-teal">Destination</th>
                        <th class="px-6 py-5 font-bold text-volcanic-teal">Rating</th>
                        <th class="px-6 py-5 font-bold text-volcanic-teal">Review</th>
                        <th class="px-6 py-5 font-bold text-volcanic-teal">Status</th>
                        <th class="px-6 py-5 font-bold text-volcanic-teal">Date</th>
                        <th class="px-6 py-5 text-right font-bold text-volcanic-teal">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($reviews as $review)
                        <tr class="border-b border-boracay-light align-top last:border-0">
                            <td class="px-6 py-5 font-semibold text-volcanic-teal">
                                {{ $review->user->name }}
                            </td>
                            <td class="px-6 py-5 text-benguet-charcoal/70">
                                {{ $review->destination->name }}
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-philippine-gold">
                                {{ $review->rating }}/5
                            </td>
                            <td class="max-w-md px-6 py-5 text-benguet-charcoal/75">
                                {{ $review->comment }}
                            </td>
                            <td class="px-6 py-5 capitalize text-benguet-charcoal/70">
                                {{ $review->status }}
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-benguet-charcoal/60">
                                {{ $review->created_at->format('M j, Y') }}
                            </td>
                            <td class="px-6 py-5 text-right">
                                <button
                                    type="button"
                                    onclick="document.getElementById('delete-review-confirmation-{{ $review->id }}').showModal()"
                                    class="font-semibold text-red-600 hover:text-red-800"
                                >
                                    Delete
                                </button>

                                <dialog
                                    id="delete-review-confirmation-{{ $review->id }}"
                                    aria-labelledby="delete-review-title-{{ $review->id }}"
                                    onclick="if (event.target === this) this.close()"
                                    class="m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border border-red-200 bg-palawan-sand p-0 text-benguet-charcoal shadow-2xl backdrop:bg-volcanic-teal/60 backdrop:backdrop-blur-sm"
                                >
                                    <div class="p-6 sm:p-7">
                                        <h2 id="delete-review-title-{{ $review->id }}" class="font-display text-xl font-semibold text-volcanic-teal">
                                            Delete this review?
                                        </h2>
                                        <p class="mt-2 text-sm leading-6 text-benguet-charcoal/70">
                                            This review by {{ $review->user->name }} will be permanently deleted.
                                        </p>

                                        <form method="POST" action="{{ route('admin.reviews.destroy', $review) }}" class="mt-7 flex justify-end gap-3">
                                            @csrf
                                            @method('DELETE')
                                            <button type="button" onclick="this.closest('dialog').close()" class="rounded-full border border-boracay-light px-4 py-2 text-sm font-semibold text-benguet-charcoal transition hover:bg-white">
                                                Cancel
                                            </button>
                                            <button type="submit" class="rounded-full bg-red-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-red-800">
                                                Delete permanently
                                            </button>
                                        </form>
                                    </div>
                                </dialog>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-benguet-charcoal/60">
                                No reviews found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $reviews->links() }}
    </div>
</x-app-layout>
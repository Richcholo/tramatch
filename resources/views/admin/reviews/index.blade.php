<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                Operations / Reviews
            </p>

            <h1 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-ink-strong sm:text-6xl">
                Traveler feedback.
            </h1>

            <a
                href="{{ route('admin.dashboard') }}"
                class="mt-5 inline-flex rounded-full border border-line px-5 py-3 text-sm font-semibold text-ink transition hover:bg-surface-raised"
            >
                ← Admin dashboard
            </a>
        </div>
    </x-slot>

    <div class="space-y-8">
        @if (session('status'))
            <div class="rounded-2xl border border-boracay bg-line p-4 text-ink">
                {{ session('status') }}
            </div>
        @endif

        <div class="overflow-x-auto rounded-[2rem] bg-surface-raised shadow-sm ring-1 ring-boracay-light">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-line bg-surface">
                    <tr>
                        <th class="px-6 py-5 font-bold text-ink-strong">Reviewer</th>
                        <th class="px-6 py-5 font-bold text-ink-strong">Destination</th>
                        <th class="px-6 py-5 font-bold text-ink-strong">Rating</th>
                        <th class="px-6 py-5 font-bold text-ink-strong">Review</th>
                        <th class="px-6 py-5 font-bold text-ink-strong">Status</th>
                        <th class="px-6 py-5 font-bold text-ink-strong">Date</th>
                        <th class="px-6 py-5 text-right font-bold text-ink-strong">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($reviews as $review)
                        <tr class="border-b border-line align-top last:border-0">
                            <td class="px-6 py-5 font-semibold text-ink-strong">
                                {{ $review->user->name }}
                            </td>
                            <td class="px-6 py-5 text-ink/70">
                                {{ $review->destination->name }}
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-philippine-gold">
                                {{ $review->rating }}/5
                            </td>
                            <td class="max-w-md px-6 py-5 text-ink/75">
                                {{ $review->comment }}
                            </td>
                            <td class="px-6 py-5 capitalize text-ink/70">
                                {{ $review->status }}
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-ink/60">
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
 class="m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border border-red-200 dark:border-red-400/30 bg-surface p-0 text-ink shadow-2xl backdrop:bg-volcanic-teal/60 backdrop:backdrop-blur-sm"
                                >
                                    <div class="p-6 sm:p-7">
                                        <h2 id="delete-review-title-{{ $review->id }}" class="font-display text-xl font-semibold text-ink-strong">
                                            Delete this review?
                                        </h2>
                                        <p class="mt-2 text-sm leading-6 text-ink/70">
                                            This review by {{ $review->user->name }} will be permanently deleted.
                                        </p>

                                        <form method="POST" action="{{ route('admin.reviews.destroy', $review) }}" class="mt-7 flex justify-end gap-3">
                                            @csrf
                                            @method('DELETE')
                                            <button type="button" onclick="this.closest('dialog').close()" class="rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink transition hover:bg-white">
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
                            <td colspan="7" class="px-6 py-12 text-center text-ink/60">
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

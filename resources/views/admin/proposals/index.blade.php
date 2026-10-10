<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                Admin / Review queue
            </p>

            <h1 class="mt-3 font-display text-4xl font-semibold text-volcanic-teal sm:text-6xl">
                Proposed updates.
            </h1>
        </div>
    </x-slot>

    <div class="space-y-8">
        <form
            method="POST"
            action="{{ route('admin.proposals.bulk') }}"
        >
            @csrf
            @method('PATCH')

            <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
                <p class="text-sm text-benguet-charcoal/60">
                    Nothing is published until an administrator approves it.
                </p>

                <div class="flex gap-3">
                    <select
                        name="action"
                        required
                        class="rounded-xl border-boracay-light bg-palawan-sand text-sm focus:border-boracay focus:ring-boracay"
                    >
                        <option value="approve">Approve selected</option>
                        <option value="reject">Reject selected</option>
                    </select>

                    <button
                        type="submit"
                        class="rounded-full bg-volcanic-teal px-5 py-3 text-sm font-bold text-white"
                    >
                        Apply
                    </button>
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($proposals as $proposal)
                    <article class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
                        <div class="flex flex-wrap items-start gap-5">
                            <input
                                type="checkbox"
                                name="proposal_ids[]"
                                value="{{ $proposal->id }}"
                                class="mt-1 h-5 w-5 rounded border-boracay text-boracay focus:ring-boracay"
                            >

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center justify-between gap-4">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                                            {{ \App\Models\DestinationUpdateProposal::fieldLabel($proposal->field_name) }}
                                        </p>

                                        <h2 class="mt-2 text-2xl font-bold text-volcanic-teal">
                                            {{ $proposal->destination->name }}
                                        </h2>
                                    </div>

                                    <span class="tm-gold-badge">
                                        {{ number_format((float) $proposal->confidence, 0) }}% sure
                                    </span>
                                </div>

                                <p class="mt-3 text-sm text-benguet-charcoal/55">
                                    Source: {{ $proposal->source->source_name }}
                                </p>

                                <div class="mt-6 grid gap-4 md:grid-cols-2">
                                    <div class="rounded-2xl bg-palawan-sand p-4">
                                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-benguet-charcoal/45">
                                            Current
                                        </p>

                                        <p class="mt-3 break-words text-sm text-benguet-charcoal/70">
                                            {{ $proposal->old_value ?: 'No existing value' }}
                                        </p>
                                    </div>

                                    <div class="rounded-2xl bg-boracay-light p-4">
                                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-boracay-dark">
                                            Proposed
                                        </p>

                                        <p class="mt-3 break-words text-sm text-volcanic-teal">
                                            {{ $proposal->proposed_value }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-6 flex flex-wrap gap-3">
                                    <button
                                        type="submit"
                                        form="proposal-approve-{{ $proposal->id }}"
                                        class="rounded-full bg-boracay px-4 py-2 text-sm font-bold text-benguet-charcoal"
                                    >
                                        Approve
                                    </button>

                                    <button
                                        type="submit"
                                        form="proposal-reject-{{ $proposal->id }}"
                                        class="rounded-full border border-red-300 px-4 py-2 text-sm font-bold text-red-600"
                                    >
                                        Reject
                                    </button>

                                    <a
                                        href="{{ $proposal->source->source_url }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="rounded-full border border-boracay px-4 py-2 text-sm font-bold text-boracay-dark"
                                    >
                                        View source ↗
                                    </a>
                                </div>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-[2rem] bg-volcanic-teal p-10 text-white">
                        <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-light">
                            Review queue clear
                        </p>

                        <h2 class="mt-5 font-display text-4xl font-semibold">
                            No pending updates.
                        </h2>

                        <p class="mt-4 text-white/65">
                            Crawl an approved source to generate new proposals.
                        </p>
                    </div>
                @endforelse
            </div>
        </form>

        @foreach ($proposals as $proposal)
            <form
                id="proposal-approve-{{ $proposal->id }}"
                method="POST"
                action="{{ route('admin.proposals.approve', $proposal) }}"
                class="hidden"
            >
                @csrf
                @method('PATCH')
            </form>

            <form
                id="proposal-reject-{{ $proposal->id }}"
                method="POST"
                action="{{ route('admin.proposals.reject', $proposal) }}"
                class="hidden"
            >
                @csrf
                @method('PATCH')
            </form>
        @endforeach

        {{ $proposals->links() }}
    </div>
</x-app-layout>

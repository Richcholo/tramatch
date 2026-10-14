<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">Add place</h2></x-slot>
    <form method="POST" action="{{ route('admin.places.store') }}" onsubmit="return confirm('Save this place?')" class="rounded-2xl bg-white p-6 shadow-sm">
        @include('admin.places._form')

        <a
            href="{{ route('admin.places.index') }}"
            class="ml-3 inline-flex rounded-lg border border-slate-300 px-5 py-3 font-medium text-slate-700 transition hover:bg-slate-100"
        >
            Cancel
        </a>
    </form>
</x-app-layout>

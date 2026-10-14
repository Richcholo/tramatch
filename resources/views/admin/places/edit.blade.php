<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit place</h2></x-slot>
    <form method="POST" action="{{ route('admin.places.update', $place) }}" onsubmit="return confirm('Save changes to this place?')" class="rounded-2xl bg-white p-6 shadow-sm">
        @method('PUT')
        @include('admin.places._form')
    </form>
</x-app-layout>

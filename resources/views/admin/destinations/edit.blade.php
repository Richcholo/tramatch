<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit destination</h2></x-slot>
    <form method="POST" action="{{ route('admin.destinations.update', $destination) }}" class="rounded-2xl bg-white p-6 shadow-sm">
        @method('PUT')
        @include('admin.destinations._form')
    </form>
</x-app-layout>
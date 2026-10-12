<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-50 dark:hover:bg-red-500/15 dark:bg-red-500/150 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-volcanic-teal transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>

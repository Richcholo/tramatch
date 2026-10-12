{{--
    THE THEME TOGGLE, to the left of the burger menu.

    IT IS A PLAIN BUTTON WITH AN INLINE `onclick`, not an Alpine component and
    not a bound listener, and that is a deliberate choice rather than a shortcut.
    The guest pages (login, register, forgot password) load
    `page-transitions.js` and never load `app.js`, so there is no bundle there to
    bind a listener into -- and the control has to work there too, or half the
    site's auth pages would be stuck in whatever theme the visitor arrived with.
    `window.tmTheme` is defined by the inline `<x-theme-init />` in `<head>` and
    is public for exactly this reason. It is also how the logout confirmation is
    already driven in this layout.

    THE GLYPH AND THE LABEL ARE BOTH CSS-SWAPPED OFF `html.dark`, never `x-show`.

    Two reasons, and both have bitten this codebase before:

      - there is no `x-cloak` rule in `app.css`, so an `x-show` pair paints both
        glyphs for a frame before Alpine initialises. That is the same trap the
        stage's pause/play control records;
      - the accessible name has to change with the theme too, and a name written
        by JavaScript is a name that is briefly wrong. Here the label is two
        spans and CSS decides which one exists, so a hidden one is `display:
        none` and is therefore not in the accessibility tree at all -- the button
        is named "Switch to light theme" or "Switch to dark theme" by what is
        actually rendered, with no script involved.

    IT LOOKS LIKE THE BURGER BESIDE IT: the same ghost ring, the same 44px
    target. Two controls that do different things should not look like one
    control with a bug.
--}}
<button
    type="button"
    data-theme-toggle
    onclick="window.tmTheme && window.tmTheme.toggle()"
    class="flex h-11 w-11 cursor-pointer items-center justify-center rounded-full border border-white/30 bg-white/10 text-white shadow-sm transition hover:bg-white/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold"
>
    {{-- Moon: shown on a light ground. "There is no dark theme yet." --}}
    <svg
        class="tm-theme-toggle__glyph tm-theme-toggle__moon"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path d="M20.5 14.3A8.5 8.5 0 1 1 9.7 3.5a6.8 6.8 0 0 0 10.8 10.8Z" />
    </svg>

    {{-- Sun: shown on the dark ground. "There is no light theme yet." --}}
    <svg
        class="tm-theme-toggle__glyph tm-theme-toggle__sun"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.2 5.2l1.4 1.4M17.4 17.4l1.4 1.4M18.8 5.2l-1.4 1.4M6.6 17.4l-1.4 1.4" />
    </svg>

    <span class="sr-only tm-theme-toggle__to-dark">Switch to dark theme</span>
    <span class="sr-only tm-theme-toggle__to-light">Switch to light theme</span>
</button>
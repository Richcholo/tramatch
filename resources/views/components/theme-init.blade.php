{{--
    THE THEME, APPLIED BEFORE FIRST PAINT.

    This is INLINE and it is in `<head>`, on purpose, twice over. A script that
    arrives with the bundle is deferred, so the browser has already painted the
    page in the wrong theme by the time it runs -- the classic flash of light
    ground on a dark-first site, and it is worse here than usual because the
    destination page and the deck are dark by design.

    So this is the one piece of behaviour that may NOT be in the bundle. It is
    four lines of real logic and it has to run before `<body>` exists.

    THE ORDER OF PREFERENCE:
      1. an explicit choice the visitor has made, in localStorage
      2. the operating system's `prefers-color-scheme`
      3. dark, which is the design

    A stored choice always beats the OS, because someone who has pressed the
    toggle once has said what they want and is not asking to be second-guessed
    every time their laptop changes wallpaper.

    `window.tmTheme` is public on purpose: the toggle button calls
    `window.tmTheme.toggle()` from an inline `onclick`, which is how the rest of
    this layout already drives its dialogs (see the logout confirmation). It
    means the control works on the guest pages too, which load
    `page-transitions.js` and never load `app.js` -- so there is no bundle there
    to bind a listener into.
--}}
<script>
    (function () {
        var key = 'tramatch-theme';
        var root = document.documentElement;

        var stored = null;

        try {
            stored = window.localStorage.getItem(key);
        } catch (error) {
            // Private browsing, or storage disabled. Fall through to the OS: a
            // visitor who cannot be remembered is better served by following
            // their system than by being pinned to one theme forever.
            stored = null;
        }

        var prefersDark = window.matchMedia
            && window.matchMedia('(prefers-color-scheme: dark)').matches;

        var theme = stored === 'dark' || stored === 'light' ? stored : (prefersDark ? 'dark' : 'light');

        var apply = function (next) {
            root.classList.toggle('dark', next === 'dark');
            root.dataset.theme = next;

            var meta = document.querySelector('meta[name="theme-color"]');

            if (meta) {
                // Keeps the browser's own chrome -- an Android status bar, a
                // Safari tab strip -- in step with the page instead of leaving a
                // light bar over a dark site.
                meta.content = next === 'dark' ? '#0B252B' : '#D7E4DB';
            }
        };

        apply(theme);

        window.tmTheme = {
            get: function () {
                return root.dataset.theme;
            },

            set: function (next) {
                apply(next);

                try {
                    window.localStorage.setItem(key, next);
                } catch (error) {
                    // Nothing to do. The theme still applies for this page; it
                    // just will not be remembered.
                }
            },

            toggle: function () {
                this.set(root.dataset.theme === 'dark' ? 'light' : 'dark');
            },
        };

        /*
         * A visitor who has made NO explicit choice should follow their system
         * as it changes -- macOS switches to dark at sunset -- rather than being
         * frozen at whatever it was when the tab opened. A stored choice is left
         * alone: that listener only exists while there is no stored choice.
         */
        if (!stored && window.matchMedia) {
            var media = window.matchMedia('(prefers-color-scheme: dark)');

            var onChange = function (event) {
                window.tmTheme.set(event.matches ? 'dark' : 'light');
            };

            if (media.addEventListener) {
                media.addEventListener('change', onChange);
            } else if (media.addListener) {
                media.addListener(onChange);
            }
        }
    })();
</script>
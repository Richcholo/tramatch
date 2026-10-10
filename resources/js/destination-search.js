const inputs = document.querySelectorAll('[data-destination-search]');

inputs.forEach((input) => {
    const listboxId = input.getAttribute('aria-controls');
    const listbox = listboxId ? document.getElementById(listboxId) : null;

    if (!listbox) {
        return;
    }

    const options = Array.from(listbox.querySelectorAll('[data-location-option]'));
    let visibleOptions = [];
    let activeIndex = -1;

    const setActiveOption = (index) => {
        activeIndex = index;

        options.forEach((option) => {
            option.setAttribute('aria-selected', 'false');
        });

        if (activeIndex < 0 || activeIndex >= visibleOptions.length) {
            input.removeAttribute('aria-activedescendant');
            return;
        }

        const activeOption = visibleOptions[activeIndex];
        activeOption.setAttribute('aria-selected', 'true');
        input.setAttribute('aria-activedescendant', activeOption.id);
        activeOption.scrollIntoView({ block: 'nearest' });
    };

    const closeSuggestions = () => {
        listbox.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        activeIndex = -1;
        options.forEach((option) => {
            option.setAttribute('aria-selected', 'false');
        });
    };

    const updateSuggestions = () => {
        const query = input.value.trim().toLocaleLowerCase();

        visibleOptions = options.filter((option) => {
            const matches = query !== ''
                && option.dataset.value.toLocaleLowerCase().includes(query);
            option.hidden = !matches;
            return matches;
        });

        if (visibleOptions.length === 0) {
            closeSuggestions();
            return;
        }

        listbox.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        setActiveOption(-1);
    };

    const selectOption = (option) => {
        input.value = option.dataset.value;
        input.focus();
        closeSuggestions();
    };

    input.addEventListener('input', updateSuggestions);
    input.addEventListener('focus', updateSuggestions);

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSuggestions();
            return;
        }

        if (listbox.hidden || visibleOptions.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveOption((activeIndex + 1) % visibleOptions.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveOption(
                activeIndex <= 0 ? visibleOptions.length - 1 : activeIndex - 1
            );
        } else if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            selectOption(visibleOptions[activeIndex]);
        }
    });

    listbox.addEventListener('click', (event) => {
        const option = event.target.closest('[data-location-option]');

        if (option) {
            selectOption(option);
        }
    });

    document.addEventListener('pointerdown', (event) => {
        if (!input.contains(event.target) && !listbox.contains(event.target)) {
            closeSuggestions();
        }
    });
});

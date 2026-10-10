const destinations = document.querySelector('[data-responsive-destinations]');

if (destinations) {
    const mobile = window.matchMedia('(max-width: 767px)');
    const synchronizePageSize = () => {
        const desiredPageSize = mobile.matches ? '6' : '9';

        if (destinations.dataset.perPage !== desiredPageSize) {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', desiredPageSize);
            url.searchParams.delete('page');
            window.location.replace(url);
        }
    };

    synchronizePageSize();
    mobile.addEventListener('change', synchronizePageSize);
}

document.addEventListener('input', (event) => {
    const input = event.target;

    // Use event delegation because digit-only inputs may be rendered after page load.
    if (input instanceof HTMLInputElement && input.hasAttribute('data-digits-only')) {
        input.value = input.value.replace(/[^0-9]/g, '');
    }
});
document.addEventListener('input', (event) => {
    const input = event.target;

    if (input instanceof HTMLInputElement && input.hasAttribute('data-digits-only')) {
        input.value = input.value.replace(/[^0-9]/g, '');
    }
});
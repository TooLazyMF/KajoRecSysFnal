<script>
    (() => {
        if (!document.documentElement.dataset.passwordToggleInitialized) {
            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-toggle-password]');
                if (!button) return;

                const input = document.getElementById(button.getAttribute('aria-controls'));
                if (!input) return;

                const isVisible = input.type === 'password';
                input.type = isVisible ? 'text' : 'password';
                button.textContent = isVisible ? 'Hide' : 'Show';
                button.setAttribute('aria-label', isVisible ? 'Hide password' : 'Show password');
                button.setAttribute('aria-pressed', String(isVisible));
            });
            document.documentElement.dataset.passwordToggleInitialized = 'true';
        }

        const passwordFields = (form) => Array.from(form.querySelectorAll('input[type="password"]'));
        const fieldName = (input) => input.name || input.getAttribute('wire:model') || input.id || '';
        // Confirmation fields use either their name or Livewire binding, not a fixed field ID.
        const isConfirmation = (input) => /confirm/i.test(fieldName(input));
        const fieldContainer = (input) => input.closest('[data-flux-field], .field, label') || input.parentElement;

        const initializeRequirements = (input) => {
            if (input.dataset.passwordRequirementsInitialized) return;

            input.dataset.passwordRequirementsInitialized = 'true';
            const container = fieldContainer(input);
            if (!container) return;

            container.classList.add('relative');
            const panel = document.createElement('div');
            panel.id = `password-requirements-${Math.random().toString(36).slice(2)}`;
            panel.className = 'absolute left-0 top-full z-50 hidden mt-2 w-64 max-w-[calc(100vw-2rem)] rounded-md border border-zinc-200 bg-white p-3 text-xs shadow-lg dark:border-zinc-700 dark:bg-zinc-900';
            panel.setAttribute('role', 'status');
            panel.setAttribute('aria-live', 'polite');

            const heading = document.createElement('p');
            heading.className = 'mb-2 font-medium text-zinc-700 dark:text-zinc-200';
            heading.textContent = 'Password must meet the following requirements:';
            panel.appendChild(heading);

            const list = document.createElement('ul');
            list.className = 'space-y-1';
            const rules = [
                { label: 'At least one letter', test: (value) => /[A-Za-z]/.test(value) },
                { label: 'At least one number', test: (value) => /\d/.test(value) },
                { label: 'At least 8 characters', test: (value) => value.length >= 8 },
                { label: 'Use letters and numbers only', test: (value) => value.length > 0 && /^[A-Za-z\d]+$/.test(value) },
            ];
            const rows = rules.map((rule) => {
                const row = document.createElement('li');
                row.className = 'flex items-center gap-1 text-red-600 dark:text-red-400';

                const icon = document.createElement('span');
                icon.setAttribute('aria-hidden', 'true');
                icon.textContent = '✗';

                const text = document.createElement('span');
                text.textContent = rule.label;
                row.append(icon, text);
                list.appendChild(row);

                return { rule, row, icon };
            });
            panel.appendChild(list);
            container.appendChild(panel);
            input.setAttribute('aria-describedby', [
                input.getAttribute('aria-describedby'),
                panel.id,
            ].filter(Boolean).join(' '));

            const update = () => {
                rows.forEach(({ rule, row, icon }) => {
                    const passed = rule.test(input.value);

                    icon.textContent = passed ? '✓' : '✗';
                    row.classList.toggle('text-green-600', passed);
                    row.classList.toggle('dark:text-green-400', passed);
                    row.classList.toggle('text-red-600', !passed);
                    row.classList.toggle('dark:text-red-400', !passed);
                });
            };

            input.addEventListener('focus', () => panel.classList.remove('hidden'));
            input.addEventListener('input', () => {
                update();
                panel.classList.remove('hidden');
            });
            input.addEventListener('blur', () => panel.classList.add('hidden'));
            update();
        };

        const initializePasswordMatch = (form) => {
            const inputs = passwordFields(form);
            const confirmation = inputs.find(isConfirmation);
            const password = inputs.find((input) => !isConfirmation(input) && (
                input.autocomplete === 'new-password' || fieldName(input) === 'password'
            ));

            if (!password || !confirmation || confirmation.dataset.passwordMatchInitialized) {
                return;
            }

            confirmation.dataset.passwordMatchInitialized = 'true';
            const container = fieldContainer(confirmation);
            if (!container) return;

            let feedback = form.querySelector('[data-password-match-feedback]');
            if (!feedback) {
                feedback = document.createElement('span');
                feedback.dataset.passwordMatchFeedback = 'true';
                container.appendChild(feedback);
            }

            feedback.setAttribute('aria-live', 'polite');
            const update = () => {
                const hasConfirmation = confirmation.value.length > 0;
                const matches = hasConfirmation && password.value === confirmation.value;

                feedback.className = hasConfirmation
                    ? `mt-2 block text-xs ${matches ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`
                    : 'hidden';
                feedback.textContent = hasConfirmation
                    ? (matches ? '✓ Passwords match.' : '✗ Passwords do not match.')
                    : '';

                confirmation.setCustomValidity(
                    hasConfirmation && !matches ? 'Passwords do not match.' : '',
                );
            };

            password.addEventListener('input', update);
            confirmation.addEventListener('input', update);
            update();
        };

        const initializeAllPasswordForms = () => {
            document.querySelectorAll('form').forEach((form) => {
                passwordFields(form).forEach((input) => {
                    if (input.autocomplete === 'new-password' && !isConfirmation(input)) {
                        initializeRequirements(input);
                    }
                });
                initializePasswordMatch(form);
            });
        };

        initializeAllPasswordForms();
        document.addEventListener('DOMContentLoaded', initializeAllPasswordForms);
        document.addEventListener('livewire:navigated', initializeAllPasswordForms);
        document.addEventListener('livewire:initialized', initializeAllPasswordForms);
    })();
</script>

import { Passkeys } from '@laravel/passkeys';

window.Passkeys = Passkeys;
// Let Blade and Livewire listeners initialize only after Passkeys is available.
window.dispatchEvent(new CustomEvent('passkeys:ready'));

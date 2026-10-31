/*
 * Livewire Shield: replaces Livewire's default error modal for the statuses Shield (and Laravel)
 * answer without a useful body, with a plain-text notice or your own notifier.
 *
 * Messages are inserted with textContent, never as HTML. Nothing is retried and the page is
 * never reloaded on its own: on 419 the user gets a button, so unsaved input is not lost silently.
 *
 * Expects `window.LivewireShieldConfig = { messages: {status: text}, reload: text, debug: bool }`,
 * printed by the @livewireShieldScripts directive. Set `window.LivewireShield.notify(message, ctx)`
 * to route notices to an existing toast library instead.
 */
(() => {
    if (window.__livewireShield) return;
    window.__livewireShield = true;

    const config = window.LivewireShieldConfig || { messages: {}, debug: false };
    window.LivewireShield = window.LivewireShield || {};

    const show = (status, incidentId) => {
        const message = config.messages[status];
        const context = { status, incidentId, reload: () => window.location.reload() };

        if (typeof window.LivewireShield.notify === 'function') {
            window.LivewireShield.notify(message, context);
            return;
        }

        let banner = document.getElementById('livewire-shield-notice');
        if (! banner) {
            banner = document.createElement('div');
            banner.id = 'livewire-shield-notice';
            banner.setAttribute('role', 'alert');
            banner.style.cssText = 'position:fixed;inset:auto 1rem 1rem 1rem;z-index:2147483647;padding:.75rem 1rem;'
                + 'border-radius:.5rem;background:#1f2937;color:#fff;font:14px/1.4 system-ui,sans-serif;'
                + 'display:flex;gap:1rem;align-items:center;justify-content:space-between';
            document.body.appendChild(banner);
        }

        banner.replaceChildren();
        const text = document.createElement('span');
        text.textContent = incidentId ? `${message} (${incidentId})` : message;
        banner.appendChild(text);

        const button = document.createElement('button');
        button.type = 'button';
        button.style.cssText = 'background:#fff;color:#1f2937;border:0;border-radius:.25rem;padding:.25rem .75rem;cursor:pointer';
        if (status === 419) {
            button.textContent = config.reload;
            button.addEventListener('click', () => window.location.reload());
        } else {
            button.textContent = '×';
            button.setAttribute('aria-label', config.close || 'Close');
            button.addEventListener('click', () => banner.remove());
        }
        banner.appendChild(button);
    };

    // In debug only empty bodies are taken over, so Laravel's error page still shows in the modal.
    const handles = (status, body) => config.messages[status] !== undefined
        && (! config.debug || ! body || String(body).trim() === '');

    const register = () => {
        if (typeof Livewire.interceptRequest === 'function') {
            Livewire.interceptRequest(({ onError }) => {
                onError(({ response, body, preventDefault }) => {
                    if (! handles(response.status, body)) return;
                    preventDefault();
                    show(response.status, response.headers?.get('X-Shield-Incident'));
                });
            });
            return;
        }

        Livewire.hook('request', ({ fail }) => {
            fail(({ status, content, preventDefault }) => {
                if (! handles(status, content)) return;
                preventDefault();
                show(status, null);
            });
        });
    };

    window.Livewire ? register() : document.addEventListener('livewire:init', register, { once: true });
})();

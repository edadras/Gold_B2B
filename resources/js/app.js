/**
 * Trader web panel entry point — doc §1.2's `app.ts`.
 *
 * One bundle serves both documents the Web module renders. Which component
 * mounts is decided by the element the Blade shell put on the page, so the
 * sign-in screen does not carry the terminal's code and the terminal does not
 * carry the login form's.
 *
 * NOT INERTIA. The doc specifies Laravel + Inertia + Vue 3. Vue 3 is here;
 * Inertia is not, because its server-side adapter (`inertiajs/inertia-laravel`)
 * cannot be installed in this environment — the package proxy refuses GitHub
 * authentication, so `composer require` fails. The panel therefore routes on
 * the client and reads data from `/api/v1`, which is the same data path an
 * Inertia build would use for its live figures anyway. See resources/README.md
 * for exactly what changes when Inertia becomes installable.
 */

import { createApp } from 'vue';

import Login from './Pages/Auth/Login.vue';
import Panel from './Panel.vue';
import { initialisePanel } from './Stores/panel.js';
import '../css/panel.css';

function readJson(id) {
    const element = document.getElementById(id);

    if (!element) {
        return null;
    }

    try {
        return JSON.parse(element.textContent || 'null');
    } catch {
        return null;
    }
}

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

const panelRoot = document.getElementById('panel');

if (panelRoot) {
    const bootstrap = readJson('panel-bootstrap') || {};

    // Without an organisation there is no tenancy and nothing safe to render.
    // Bounce rather than show a panel wired to nobody.
    if (!bootstrap.user) {
        window.location.assign('/app/login');
    } else {
        initialisePanel(bootstrap, csrfToken());

        createApp(Panel, { initialScreen: panelRoot.dataset.screen || 'terminal' })
            .mount(panelRoot);
    }
}

const loginRoot = document.getElementById('login');

if (loginRoot) {
    createApp(Login, { csrfToken: csrfToken() }).mount(loginRoot);
}

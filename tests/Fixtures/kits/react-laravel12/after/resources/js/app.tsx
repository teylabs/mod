import { resolveModulePage } from '../../vendor/tey/mod/resources/js/inertia';
import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { initializeTheme } from './hooks/use-appearance';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => resolveModulePage(name, import.meta.glob('./pages/**/*.tsx'), import.meta.glob('../../app/Modules/*/resources/js/pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <StrictMode>
                <App {...props} />
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

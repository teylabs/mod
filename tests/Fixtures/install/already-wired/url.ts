import { fileURLToPath } from 'node:url';
export default defineConfig({
    resolve: { alias: { '@modules': fileURLToPath(new URL('./app/Modules', import.meta.url)) } },
    plugins: [laravel({ refresh: ['app/Modules/**/resources/views/**'] })],
});

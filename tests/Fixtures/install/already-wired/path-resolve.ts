import path from 'node:path';
export default defineConfig({
    resolve: { alias: { '@modules': path.resolve('app/Modules') } },
    plugins: [laravel({ refresh: ['resources/views/**', 'app/Modules/**/resources/views/**'] })],
});

import { resolveModulePage } from '../../vendor/tey/mod/resources/js/inertia';

createInertiaApp({
    resolve: async (name) => {
        const page = await resolveModulePage(
        name,
        import.meta.glob<DefineComponent>(['./pages/**/*.vue', '!./pages/**/Layout.vue'], { import: 'default' }),
        import.meta.glob<DefineComponent>(['../../app/Modules/*/resources/js/pages/**/*.vue', '!../../app/Modules/*/resources/js/pages/**/Layout.vue'], { import: 'default' }),
        );
        page.default.layout ??= DefaultLayout;
        return page;
    },
});

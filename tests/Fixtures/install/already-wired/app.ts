import { resolveModulePage } from '../../vendor/tey/mod/resources/js/inertia';

createInertiaApp({
    resolve: (name) => resolveModulePage(
        name,
        import.meta.glob<DefineComponent>(['./pages/**/*.vue', '!./pages/**/Layout.vue'], { import: 'default' }),
        import.meta.glob<DefineComponent>(['../../app/Modules/*/resources/js/pages/**/*.vue', '!../../app/Modules/*/resources/js/pages/**/Layout.vue'], { import: 'default' }),
    ),
});

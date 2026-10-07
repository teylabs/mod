# tey/mod

**Private, experimental. Not released.**

`tey/mod` is a Laravel-aware generation and discovery engine that does not assume any particular application layout. Given a declared layout (ordinary Laravel, feature-first, vertical slices, type-first, or `app/Modules/<Module>`), it places generated artifacts using Laravel's native generators, resolves related artifacts (a model's factory, a controller's form requests), maps existing files back to the layout, and discovers providers, commands and listeners with explicit provenance and a cacheable inventory. Opinionated packages can then be built as thin presets on top of it.

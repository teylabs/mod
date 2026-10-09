<?php

namespace Tey\Mod\Layout\BuiltIn;

/** @internal Shipped route command definitions and human wording, not placement rules. */
final class RouteContent
{
    public const ARGUMENT = 'module';

    public const FILES_SIGNATURE = 'mod:routes {module : Module name} {--api : Also create API routes} {--console : Also create console routes} {--force : Overwrite existing files} {--dry-run : Preview without writing} {--json : Print the preview as JSON}';

    public const REGISTRAR_SIGNATURE = 'mod:route-registrar {module : Module name} {--force : Overwrite existing files} {--dry-run : Preview without writing} {--json : Print the preview as JSON}';

    public const FILES_DESCRIPTION = 'Create Laravel route files in a module';

    public const REGISTRAR_DESCRIPTION = 'Create a typed module route registrar';

    public const UNLOADED = " modules have route files, but Mod::routes() isn't called. Add then: fn () => Mod::routes() to withRouting() in bootstrap/app.php.";

    public const UNLOADED_SINGLE = " module has route files, but Mod::routes() isn't called. Add then: fn () => Mod::routes() to withRouting() in bootstrap/app.php.";

    public const DUPLICATE = 'Module [%s] routes loaded first at %s and again at %s. Remove the overlapping module from one Mod::routes() call.';

    public const UNKNOWN = 'mod.routes.order names unknown module [%s]. Remove it or correct its name in config/mod.php.';
}

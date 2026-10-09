<?php

namespace Tey\Mod\Layout\BuiltIn;

/** @internal Public source labels and module-specific generator wording. */
final class GeneratorSources
{
    public const PREFIX = 'module:';

    public const ANCHOR = '@module/';

    public const INVALID_ANCHOR = 'Module templates must use @module paths. Move this template under stubs/mod/@module/.';

    public const NO_TEMPLATE = '%s has no template for %s. Add an app template in stubs/mod/@module/ or a template in that module. Nothing was written.';

    public const WRONG_OWNER = 'mod:%s belongs to another module. Run it with its module name, or register this scaffold in the app.';

    public const TEMPLATE_LABEL = ' (module)';
}

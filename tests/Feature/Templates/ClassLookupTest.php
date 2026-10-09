<?php

use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Templates\ClassLookup;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('indexes declared names without loading classes and excludes vendor even inside a root', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->write('app/Modules/Knowledge/Stories/Different.php', '<?php namespace App\\Modules\\Knowledge\\Stories; class NeverLoaded {}');
        $w->write('app/Modules/Agents/Stories/Other.php', '<?php namespace App\\Modules\\Agents\\Stories; class NeverLoaded {}');
        $w->write('app/vendor/acme/Hidden.php', '<?php namespace App\\Vendor; class Hidden {}');
        $lookup = new ClassLookup($w->root->path, app(CompiledLayout::class));
        expect(array_column($lookup->matches('NeverLoaded'), 'class'))->toBe(['App\\Modules\\Agents\\Stories\\NeverLoaded', 'App\\Modules\\Knowledge\\Stories\\NeverLoaded'])
            ->and(array_column($lookup->matches('Knowledge/NeverLoaded'), 'class'))->toBe(['App\\Modules\\Knowledge\\Stories\\NeverLoaded'])
            ->and($lookup->matches('Hidden'))->toBe([])
            ->and($lookup->near('NeverLoadde')[0]['name'])->toBe('NeverLoaded')
            ->and(class_exists('App\\Modules\\Knowledge\\Stories\\NeverLoaded', false))->toBeFalse()
            ->and(class_exists('App\\Modules\\Agents\\Stories\\NeverLoaded', false))->toBeFalse();
    });
});

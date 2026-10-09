<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('S21 stores recursion finitely and grows paths into matching folders', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        putenv('COLUMNS=72');
        $w->write('app/Modules/Docs/.keep', '');
        $w->write('stubs/mod.view-model.section.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} { public function children(): array { return [\n// mod:children\n]; } }\n");
        $w->write('stubs/mod.insert.section-child.stub', "'{{ section.page.fqcn }}',");
        Mod::scaffold('section', fn (Scaffold $s) => $s->makes('view-model', name: '{name}SectionViewModel', as: 'page', stub: 'section')->part('section', uses: 'section', configure: fn (Part $p) => $p->inserts(into: 'page', at: 'children', stub: 'section-child')));
        $w->artisan('mod:section', ['name' => 'Docs:Guide'])->assertSuccessful();
        $w->artisan('mod:section.section', ['name' => 'Docs:Guide', 'value' => 'Install'])->assertSuccessful();
        $w->artisan('mod:section.section', ['name' => 'Docs:Guide', 'value' => 'Install/Requirements'])->assertSuccessful();
        expect($w->read('app/Modules/Docs/ViewModels/Guide/Install/RequirementsSectionViewModel.php'))->toContain('namespace App\\Modules\\Docs\\ViewModels\\Guide\\Install;');
        expect(app(ScaffoldRegistry::class)->nodes())->toHaveCount(2);
        $before = $w->files();
        $w->artisan('mod:section.section', ['name' => 'Docs:Guide', 'value' => implode('/', array_fill(0, 11, 'Deep'))])->assertFailed()->expectsOutputToContain('10');
        expect($w->files())->toBe($before);
    });
});

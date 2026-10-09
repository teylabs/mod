<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

class S5Crud
{
    public string $name = 'crud';

    public function __invoke(Scaffold $s): void
    {
        Examples::recipe($s);
    }
}

it('S5 registers invokable classes and keyed closures together', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w);
        Mod::scaffolds([S5Crud::class, 'api' => fn (Scaffold $s) => $s->include('crud')->makes('controller', name: 'Api{name}Controller', stub: 'api')]);
        $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect(str_replace("\r\n", "\n", $w->read(Examples::paths()[7])))->toBe(Examples::controller());
    });
});

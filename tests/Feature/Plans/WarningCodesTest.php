<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\Root;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('describes missing scaffold placement without matching an English sentence', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Billing/Models/Existing.php', '<?php');
        Mod::scaffold('pages', fn (Scaffold $s) => $s->makes('model'));
        $result = $w->artisan('mod:pages', ['name' => 'Invoice', '--dry-run' => true, '--json' => true])->assertSuccessful();
        $plan = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        expect($plan['would_write'])->toBeFalse()
            ->and($plan['warnings'][0]['code'])->toBe('missing_placement')
            ->and($plan['warnings'][0]['data'])->toMatchArray(['dimension' => 'module', 'file_type' => 'model', 'options' => ['--in', '--module']])
            ->and($plan['warnings'][0]['message'])->toContain('mod:pages', '--module', 'module:Invoice');
        $w->artisan('mod:pages', ['name' => 'Invoice'])->assertFailed()->expectsOutputToContain('--module');
        foreach ([['--in' => 'Billing'], ['--module' => 'Billing'], ['name' => 'Billing:Invoice']] as $placement) {
            $p = json_decode($w->artisan('mod:pages', ['name' => 'Invoice', ...$placement, '--dry-run' => true, '--json' => true])->output, true);
            expect($p['warnings'])->toBe([])->and($p['files'])->toHaveCount(1);
        }
    });
});

it('does not offer a native command flag as a missing placement option', function () {
    Workspace::run(null, function (Workspace $w) {
        Mod::layout('laravel')->mounts('app', 'App\\', 'app', fn (Root $root) => $root->generates('controller', in: 'Http/Controllers/{model}', suffix: 'Controller'));
        $plan = json_decode($w->artisan('mod:controller', ['name' => 'Invoice', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true);
        expect($plan['warnings'][0]['code'])->toBe('missing_placement')
            ->and($plan['warnings'][0]['data']['options'])->toBe(['--in'])
            ->and(str_contains($plan['warnings'][0]['message'], '--model'))->toBeFalse();
    });
});

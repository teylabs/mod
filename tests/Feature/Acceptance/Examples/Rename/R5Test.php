<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R5 retains stable shared members and blocks identity-changing keep members', function (bool $changing) {
    Workspace::run(null, function (Workspace $w) use ($changing) {
        S::setup($w);
        Mod::scaffold('shared', fn (Scaffold $s) => $s->makes('model')->makes('class', name: $changing ? 'Support/{name}Formatter' : 'Support/StockFormatter', as: 'formatter', ungrouped: true, existing: 'keep'));
        $name = $changing ? 'WidgetFormatter' : 'StockFormatter';
        $w->write('app/Support/'.$name.'.php', "<?php\nnamespace App\\Support;\nclass {$name} {}\n");
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'shared']);
        expect($data['would_write'])->toBe(! $changing);
        if ($changing) {
            expect(implode(' ', array_column($data['warnings'], 'message')))->toContain('cannot move kept member formatter');
        } else {
            expect($data['retained'])->toBe([['alias' => 'formatter', 'path' => 'app/Support/StockFormatter.php', 'reason' => 'stable kept member']]);
        }
    });
})->with([false, true]);

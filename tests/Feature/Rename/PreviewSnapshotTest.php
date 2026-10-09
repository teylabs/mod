<?php

use Tey\Mod\Rename\Contribution;
use Tey\Mod\Rename\Contributor;
use Tey\Mod\Rename\Contributors;
use Tey\Mod\Rename\Inputs;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('checks preview content and Git state while tolerating transient Git maintenance files', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        app(Contributors::class)->set('maintenance-fixture', new class implements Contributor
        {
            public function contribute(Inputs $inputs): Contribution
            {
                // Simulate independent Git housekeeping during the preview.
                file_put_contents($inputs->basePath.'/.git/objects/maintenance.lock', 'Transient Git metadata');

                return new Contribution;
            }
        });
        expect(S::preview($w)['would_write'])->toBeTrue();
    });
});

<?php

use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;

it('aliases requests with the same basename but different identities', function () {
    AcceptanceApp::run('slices', function (AcceptanceApp $app) {
        $name = 'Create'.$app->tag;
        $app->artisan('mod:model', ['name' => $name, '--all' => true, '--in' => 'Billing'])->assertSuccessful();
        expect($app->read('app/Billing/Http/Controllers/'.$name.'Controller.php'))
            ->toContain("use App\\Billing\\{$name}\\Http\\Requests\\Request;")
            ->toContain('use Illuminate\Http\Request as UpdateRequest;')
            ->toContain('public function store(Request $request)')
            ->toContain('public function update(UpdateRequest $request,');
    });
});

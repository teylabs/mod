<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('S2 replaces sibling aliases in requests and preserves the current class', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w);
        $w->artisan('mod:crud', ['name' => 'Agents:Conversation'])->assertSuccessful();
        $expected = str_replace(['{{ namespace }}', '{{ model.fqcn }}', '{{ class }}', '{{ model }}'], ['App\\Modules\\Agents\\Requests', 'App\\Modules\\Agents\\Models\\Conversation', 'StoreConversationRequest', 'Conversation'], (string) file_get_contents(__DIR__.'/../../Scaffolds/Support/request.stub'));
        expect(str_replace("\r\n", "\n", $w->read('app/Modules/Agents/Requests/StoreConversationRequest.php')))->toBe($expected);
    });
});

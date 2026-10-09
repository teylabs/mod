<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('E7 uses extraction suggestions and reports the untouched comment without a terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $source = "<?php\n\nnamespace App\\Modules\\Knowledge\\ViewModels;\n\nuse App\\Modules\\Knowledge\\Models\\Document;\nuse App\\Support\\ViewModels\\ViewModel;\n\n/** Props for the ShowDocumentPage page. */\nclass ShowDocumentPage extends ViewModel\n{\n    public function __construct(private Document \$document) {}\n\n    public function title(): string\n    {\n        return \$this->document->title;\n    }\n}";
        $w->write('app/Modules/Knowledge/ViewModels/ShowDocumentPage.php', $source);
        $result = $w->artisan('mod:template', ['--from' => 'ShowDocumentPage'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(CreationScenario::output('@module/ViewModels/show-document-page', [
            'Starts as' => 'App\\Modules\\Knowledge\\ViewModels\\ShowDocumentPage',
            'Replaced' => 'namespace (line 3), ShowDocumentPage (line 9)',
            'Left as it is' => '1 comment mentions ShowDocumentPage (line 8)',
            'Command' => 'mod:show-document-page', 'Writes' => 'app/Modules/<module>/ViewModels/<Name>.php',
            'Try' => 'php artisan mod:show-document-page Agents:<Name>',
        ], true, 'App\\Modules\\Knowledge\\ViewModels\\ShowDocumentPage'))
            ->and(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/ViewModels/show-document-page.stub')))->toBe(str_replace(['namespace App\\Modules\\Knowledge\\ViewModels;', 'class ShowDocumentPage'], ['namespace {{ namespace }};', 'class {{ class }}'], $source));
    });
});

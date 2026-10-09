<?php

use Tey\Mod\Templates\PlaceholderFiller;

it('fills the four placeholder families without consuming base placeholders', function () {
    $stub = '{{ class }}|{{class.camel}}|{{ class.kebab }}|{{ class.snake }}|{{ class.studly }}|{{ class.plural }}|{{ module }}|{{source}}|{{ rootNamespace }}|{{ baseImport }}';
    expect((new PlaceholderFiller)->fill($stub, 'SearchDocuments', ['module' => 'Agents', 'source' => 'Drive'], 'Acme\\'))
        ->toBe('SearchDocuments|searchDocuments|search-documents|search_documents|SearchDocuments|SearchDocuments|Agents|Drive|Acme\\|{{ baseImport }}');
});

<?php

use Tey\Mod\Templates\TokenExtractor;

it('extracts only the namespace and matching T_STRING tokens', function (string $declaration) {
    $source = "<?php\ndeclare(strict_types=1);\nnamespace App\\Source;\n// Sample in a comment\n/** Sample docs */\n{$declaration} Sample {\npublic function copy(): Sample { return new Sample; }\npublic const TEXT = 'Sample';\npublic const LONG = SampleLong::class;\npublic const QUALIFIED = Other\\Sample::class;\npublic const STATIC = Sample::class;\n}\n";
    $result = (new TokenExtractor)->extract($source);
    expect($result['name'])->toBe('Sample')->and($result['namespace'])->toBe('App\\Source')
        ->and($result['contents'])->toBe(str_replace(['namespace App\\Source;', "{$declaration} Sample", ': Sample', 'new Sample;', '= Sample::'], ['namespace {{ namespace }};', "{$declaration} {{ class }}", ': {{ class }}', 'new {{ class }};', '= {{ class }}::'], $source))
        ->and($result['replaced'])->toBe('namespace (line 3), Sample (lines 6, 7, 11)')
        ->and($result['left'])->toBe(['2 comments mention Sample (lines 4, 5)', '1 qualified name mentions Sample (line 10)']);
})->with(['class', 'interface', 'trait', 'enum']);

it('does not mistake anonymous classes or class constants for declarations', function () {
    $source = '<?php namespace App; $x = Foo::class; $y = new class {}; class Actual {}';
    expect((new TokenExtractor)->extract($source)['name'])->toBe('Actual');
});

it('rejects sources with several declarations or no declaration', function (string $source) {
    expect(fn () => (new TokenExtractor)->extract($source))->toThrow(RuntimeException::class);
})->with(['<?php echo 1;', '<?php class A {} class B {}']);

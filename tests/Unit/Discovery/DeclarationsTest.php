<?php

use Composer\Autoload\ClassLoader;
use Tey\Mod\Discovery\Declarations;

function declarationFile(string $source): string
{
    $file = tempnam(sys_get_temp_dir(), 'mod-declaration').'.php';
    file_put_contents($file, $source);

    return $file;
}

it('resolves parents, interfaces and traits through the namespace and imports', function () {
    $file = declarationFile(<<<'PHP'
        <?php

        namespace App\Providers;

        use Vendor\Package\BaseProvider;
        use Vendor\Contracts\{Bootable, Deferrable as Lazy};
        use function Vendor\helper;

        final class AppServiceProvider extends BaseProvider implements Lazy, Bootable, \Stringable, Local\Contract
        {
            use Concerns\HasThings, \Vendor\Traits\Logs {
                Logs::log insteadof HasThings;
            }

            public function make(): object
            {
                return new class {};
            }
        }
        PHP);

    expect((new Declarations)->dependencies($file))->toBe([
        'Vendor\\Package\\BaseProvider',
        'Vendor\\Contracts\\Deferrable',
        'Vendor\\Contracts\\Bootable',
        'Stringable',
        'App\\Providers\\Local\\Contract',
        'App\\Providers\\Concerns\\HasThings',
        'Vendor\\Traits\\Logs',
    ]);
});

it('names the first missing dependency without loading the class', function () {
    $file = declarationFile('<?php namespace ModDeclarations; class Candidate extends \\Exception { use \\MissingDev\\Behaviour; }');

    expect((new Declarations)->missing($file))->toBe('MissingDev\\Behaviour')
        ->and(class_exists('ModDeclarations\\Candidate', false))->toBeFalse()
        ->and((new Declarations)->missing(declarationFile('<?php class ModDeclarationsFine extends \\Exception implements \\Countable { public function count(): int { return 0; } }')))->toBeNull();
});

it('looks through a present parent for a missing trait further up', function () {
    $root = sys_get_temp_dir().'/mod-declarations-'.uniqid();
    mkdir($root.'/Present', 0777, true);
    file_put_contents($root.'/Present/Base.php', '<?php namespace ModDeclarationsChain\\Present; class Base { use \\MissingDev\\Deep; }');
    $loader = new ClassLoader;
    $loader->addPsr4('ModDeclarationsChain\\', $root);
    $loader->register();

    try {
        $file = declarationFile('<?php namespace ModDeclarationsChain; class Child extends Present\\Base {}');

        expect((new Declarations)->missing($file))->toBe('MissingDev\\Deep')
            ->and((new Declarations)->missingFor('ModDeclarationsChain\\Present\\Base'))->toBe('MissingDev\\Deep')
            ->and(class_exists('ModDeclarationsChain\\Present\\Base', false))->toBeFalse();
    } finally {
        $loader->unregister();
    }
});

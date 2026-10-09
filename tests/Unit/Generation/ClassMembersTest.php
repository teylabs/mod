<?php

use Tey\Mod\Generation\ClassMembers;

it('recognises declarations only in the requested class body', function () {
    $source = new ClassMembers(<<<'PHP'
    <?php
    class Other { protected $model; }
    class Target {
        // protected $model;
        public function method($model) { $model = 'protected $model'; }
        public function &newFactory() {}
        protected $first, $actual;
    }
    PHP);
    expect($source->hasProperty('Target', 'model'))->toBeFalse()
        ->and($source->hasProperty('Target', 'actual'))->toBeTrue()
        ->and($source->hasMethod('Target', 'NEWFACTORY'))->toBeTrue();
});

it('recognises grouped and aliased imports and factory traits in a bracketed namespace', function () {
    $source = new ClassMembers(<<<'PHP'
    <?php
    namespace App {
        use Illuminate\Database\Eloquent\Factories\{Factory, HasFactory as MakesFactories};
        use App\Bases\{Data, Value};
        class Target { use MakesFactories; }
    }
    PHP);
    expect($source->hasImport('App\\Bases\\Data'))->toBeTrue()
        ->and($source->hasImport('Illuminate\\Database\\Eloquent\\Factories\\HasFactory'))->toBeFalse()
        ->and($source->hasTrait('Target', 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory'))->toBeTrue();
});

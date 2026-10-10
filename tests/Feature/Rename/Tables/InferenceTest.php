<?php

use Tey\Mod\Rename\Tables\ServiceProvider;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('reports static, computed and inherited table semantics without executing model code', function (string $body, string $category, string $diagnostic) {
    Workspace::run(null, function (Workspace $w) use ($body, $category, $diagnostic) {
        S::setup($w);
        app()->register(ServiceProvider::class);
        $w->write('app/Modules/Inventory/Models/Widget.php', "<?php\nnamespace App\\Modules\\Inventory\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nclass Widget extends Model { {$body} }\n");
        S::commit($w);
        $report = S::preview($w);
        expect($report['warnings'])->toBe([])->and($report['files'])->toBe([]);
        expect(array_column($report['checklist'], 'category'))->toContain($category);
        $selected = S::preview($w, ['--table-migration' => true]);
        expect($selected['would_write'])->toBeFalse()->and($selected['files'])->toBe([]);
        expect(implode(' ', array_column($selected['warnings'], 'message')))->toContain($diagnostic);
    });
})->with([
    ["protected \$table = 'widgets';", 'database-name', 'unchanged'],
    ['public function getTable() { throw new \\RuntimeException("never execute"); }', 'table-ambiguity', 'unambiguous'],
    ['public function __construct() { $this->table = "custom"; }', 'table-ambiguity', 'unambiguous'],
    ['use CustomTableTrait;', 'table-ambiguity', 'unambiguous'],
    ['protected $table;', 'table-ambiguity', 'unambiguous'],
    ['public function configure() { $this->setTable("custom"); }', 'table-ambiguity', 'unambiguous'],
    ['public function &getTable() { return $this->table; }', 'table-ambiguity', 'unambiguous'],
    ['protected $connection = "tenant";', 'table-ambiguity', 'unambiguous'],
    ['public function configure($key) { $this->{$key} = "custom"; }', 'table-ambiguity', 'unambiguous'],
]);

it('does not offer a table rename for a module-only move', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        app()->register(ServiceProvider::class);
        $w->write('app/Modules/Inventory/Models/Widget.php', "<?php\nnamespace App\\Modules\\Inventory\\Models;\nclass Widget extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        S::commit($w);
        $json = S::preview($w, ['new' => 'Catalog:Widget']);
        expect($json['files'])->toBe([])->and(array_column($json['checklist'], 'category'))->not->toContain('inferred-table');
    });
});

it('locates unchanged Schema strings and foreign keys at original lines', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        app()->register(ServiceProvider::class);
        $w->write('app/Modules/Inventory/Models/Widget.php', "<?php\nnamespace App\\Modules\\Inventory\\Models;\nclass Widget extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        $source = "<?php\nuse Illuminate\\Support\\Facades\\Schema as DatabaseSchema;\nDatabaseSchema::rename('legacy', 'widgets');\n\$foreign = 'widget_id';\n";
        $w->write('app/Support/tables.php', $source);
        S::commit($w);
        $json = S::preview($w);
        $rows = array_values(array_filter($json['checklist'], fn (array $row): bool => $row['file'] === 'app/Support/tables.php'));
        $tableRows = array_values(array_filter($rows, fn (array $row): bool => in_array($row['suggestion'], ['Review the database name for runtime compatibility.', 'Review relation foreign keys before using the renamed model.'], true)));
        expect(array_column($tableRows, 'category'))->toBe(['database-name', 'foreign-key'])->and(array_column($tableRows, 'line'))->toBe([3, 4]);
        $phpRows = array_values(array_filter($rows, fn (array $row): bool => $row['suggestion'] === null));
        expect(array_column($phpRows, 'category'))->toBe(['database-name', 'uncertain-string'])->and(array_column($phpRows, 'line'))->toBe([3, 3]);
        expect(array_column($phpRows, 'message'))->toBe(["'legacy' is unchanged.", "'widgets' is unchanged."]);
        expect($w->read('app/Support/tables.php'))->toBe($source);
    });
});

it('resolves a directly imported framework model alias', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        app()->register(ServiceProvider::class);
        $w->write('app/Modules/Inventory/Models/Widget.php', "<?php\nnamespace App\\Modules\\Inventory\\Models;\nuse Illuminate\\Database\\Eloquent\\Model as Record;\nclass Widget extends Record { public function example() { \$table = 'unrelated'; return \$table; } }\n");
        S::commit($w);
        $json = S::preview($w, ['--table-migration' => true]);
        expect($json['warnings'])->toBe([])->and($json['files'])->toHaveCount(1);
    });
});

it('supports the framework factory and soft-delete traits without loading the model', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        app()->register(ServiceProvider::class);
        $w->write('app/Modules/Inventory/Models/Widget.php', "<?php\nnamespace App\\Modules\\Inventory\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\nuse Illuminate\\Database\\Eloquent\\SoftDeletes as Deletes;\nclass Widget extends Model { use HasFactory, Deletes; }\nthrow new \\RuntimeException('Never load the source');\n");
        S::commit($w);
        $json = S::preview($w, ['--table-migration' => true]);
        expect($json['warnings'])->toBe([])->and($json['files'])->toHaveCount(1);
    });
});

it('does not reuse a framework import from another namespace', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        app()->register(ServiceProvider::class);
        $w->write('app/Modules/Inventory/Models/Widget.php', "<?php\nnamespace Other;\nuse Illuminate\\Database\\Eloquent\\Model;\nnamespace App\\Modules\\Inventory\\Models;\nclass Widget extends Model {}\n");
        S::commit($w);
        $json = S::preview($w, ['--table-migration' => true]);
        expect($json['would_write'])->toBeFalse()->and($json['files'])->toBe([]);
        expect(implode(' ', array_column($json['warnings'], 'message')))->toContain('unambiguous');
    });
});

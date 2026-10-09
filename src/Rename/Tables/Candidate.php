<?php

namespace Tey\Mod\Rename\Tables;

use Illuminate\Database\Migrations\MigrationCreator;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Generation\ModMigrationCreator;
use Tey\Mod\Rename\GeneratedFile;
use Tey\Mod\Support\Path;

/** @internal Complete reversible source, using compiled placement and the normal creator clock. */
final readonly class Candidate
{
    public function __construct(private MigrationCreator $creator) {}

    public function build(ModelTable $table, string $basePath): GeneratedFile
    {
        if (! $this->creator instanceof ModMigrationCreator && get_class($this->creator) !== MigrationCreator::class) {
            throw GenerationRefused::because('mod:rename --table-migration cannot promise exact bytes and placement for an app-custom migration creator. Use the default creator or decline the migration and create it separately. Nothing was written.');
        }
        $old = $table->old;
        $new = $table->new;
        if ($old === null || $new === null || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $old) !== 1 || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $new) !== 1) {
            throw GenerationRefused::because('mod:rename --table-migration requires unambiguous safe table names. Remove computed table logic or decline the migration and create it separately. Nothing was written.');
        }
        $creator = $this->creator instanceof ModMigrationCreator ? $this->creator : ModMigrationCreator::fromNative($this->creator);
        $member = $table->member;
        $name = "rename_{$old}_to_{$new}_table";
        $layout = $member->newLayout;
        $probe = $layout->place('migration', $name, $member->new->context, ['timestamp' => '2000_01_01_000000']);
        $timestamp = $creator->datePrefixFor(Path::resolve($basePath, dirname($probe->path())));
        $artifact = $layout->place('migration', $name, $member->new->context, ['timestamp' => $timestamp]);
        $bytes = <<<SOURCE
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('{$old}', '{$new}');
    }

    public function down(): void
    {
        Schema::rename('{$new}', '{$old}');
    }
};

SOURCE;

        return new GeneratedFile($artifact->path(), str_replace("\r\n", "\n", $bytes), 0644, 'table-migration:'.$member->alias, 'migration', ['name' => $name, 'from_table' => $old, 'to_table' => $new], $timestamp);
    }
}

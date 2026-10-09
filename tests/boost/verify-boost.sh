#!/usr/bin/env bash
# Verify the commands and claims in resources/boost/skills/mod-development/SKILL.md.
# Usage: tests/boost/verify-boost.sh <disposable-workdir> <12|13> [...]
set -eu
if [ "$#" -lt 2 ]; then
    echo 'Usage: tests/boost/verify-boost.sh <workdir> <12|13> [...]' >&2
    exit 1
fi
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
WORK=$1; shift
PHP=${PHP:-php}
COMPOSER_BIN=${COMPOSER_BIN:-$(command -v composer)}
export COMPOSER_NO_INTERACTION=1
mkdir -p "$WORK"
WORK=$(cd "$WORK" && pwd)
for M in "$@"; do
    case $M in 12|13) ;; *) echo "Unsupported Laravel version: $M" >&2; exit 1;; esac
done
if [ ! -f "$WORK/.mod-boost-harness" ] && [ ! -f "$WORK/.mod-docs-harness" ]; then
    for M in "$@"; do
        if [ -e "$WORK/base-$M" ]; then
            echo "Refusing to reset an application not created by a harness: $WORK/base-$M" >&2
            exit 1
        fi
    done
    test ! -e "$WORK/mod-src"
    touch "$WORK/.mod-boost-harness"
    mkdir -p "$WORK/mod-src"
    rsync -a --exclude .git --exclude vendor --exclude composer.lock --exclude build --exclude .phpunit.cache "$ROOT/" "$WORK/mod-src/"
fi
for M in "$@"; do
    if [ ! -f "$WORK/base-$M/artisan" ]; then
        "$PHP" "$COMPOSER_BIN" create-project "laravel/laravel:^$M.0" "$WORK/base-$M" --prefer-dist -q
        (
            cd "$WORK/base-$M"
            "$PHP" "$COMPOSER_BIN" config repositories.mod "{\"type\":\"path\",\"url\":\"$WORK/mod-src\",\"options\":{\"symlink\":false}}"
            "$PHP" "$COMPOSER_BIN" require 'tey/mod:*@dev' -q
            git init -q
            git add -A
            git -c user.name='Boost harness' -c user.email='harness@localhost' commit -qm 'Create the disposable app'
        )
    fi
done
pass=0; fail=0
lay(){ git reset -q --hard; git clean -fdq; "$PHP" artisan vendor:publish --tag=mod-config -q; LAYOUT=$1 perl -0pi -e 's/\x27layout\x27 => \x27laravel\x27/\x27layout\x27 => \x27$ENV{LAYOUT}\x27/' config/mod.php; }
chk(){ local d=$1; shift; if eval "$@" >/dev/null 2>&1; then pass=$((pass+1)); echo "PASS L$M: $d"; else fail=$((fail+1)); echo "FAIL L$M: $d"; fi; }
art(){ "$PHP" artisan "$@" --no-ansi 2>&1; }
for M in "$@"; do cd "$WORK/base-$M" || exit 1
lay modules
chk "Knowledge:Document -mf" 'art mod:model Knowledge:Document -mf | grep -q "Model \[app/Modules/Knowledge/Models/Document.php\]"'
chk "--in" 'art mod:model Note --in=Knowledge | grep -q "app/Modules/Knowledge/Models/Note.php"'
chk "--module" 'art mod:model Tag --module=Knowledge | grep -q "app/Modules/Knowledge/Models/Tag.php"'
chk "no placement exits 1 with a hint" '! "$PHP" artisan mod:model Orphan >/dev/null 2>&1 && art mod:model Orphan | grep -q "Pass --module=<module>"'
chk "existing file: already exists" 'art mod:model Knowledge:Document | grep -q "app/Modules/Knowledge/Models/Document.php already exists."'
chk "--force with an existing related file: Nothing was written, exit 1" '! "$PHP" artisan mod:model Knowledge:Document -f --force >/dev/null 2>&1 && art mod:model Knowledge:Document -f --force | grep -q "Nothing was written."'
chk "--force overwrites the primary file" 'echo "// mine" >> app/Modules/Knowledge/Models/Tag.php && art mod:model Knowledge:Tag --force | grep -q created && ! grep -q "// mine" app/Modules/Knowledge/Models/Tag.php'
chk "requests with a resource controller" 'art mod:model Knowledge:Page --controller --resource --requests | grep -q StorePageRequest'
chk "mod:dto base in app/Support" 'art mod:dto Knowledge:DocumentData | grep -q "app/Support/Data/DataTransferObject.php"'
chk "mod:data alias" 'art mod:data Knowledge:ChunkData | grep -q "app/Modules/Knowledge/Data/ChunkData.php"'
chk "mod:view-model base in app/Support" 'art mod:view-model Knowledge:ShowDocument | grep -q "app/Support/ViewModels/ViewModel.php"'
chk "mod:value-object, mod:value alias and mod:action" 'art mod:value Knowledge:Amount | grep -q ValueObjects && art mod:value-object Knowledge:ContentHash | grep -q ValueObjects && art mod:action Knowledge:IndexDocument | grep -q Actions'
chk "mod:bases second run" 'art mod:bases | grep -q "Every base class already exists."'
chk "mod:bases writes missing bases" 'rm -rf app/Support && art mod:bases | grep -q "app/Support/Data/DataTransferObject.php"'
chk "help shows --module" 'art help mod:model | grep -q -- "--module=MODULE"'
chk "configured base" "perl -0pi -e \"s/'dto' => null/'dto' => App\\\\\\\\Support\\\\\\\\Data::class/\" config/mod.php && art mod:dto Knowledge:SummaryData | grep -q 'configured base App.Support.Data'"
chk "mod:cache and mod:clear" 'art mod:cache | grep -q "Discovery cached" && art mod:clear | grep -q "cleared"'
chk "mod:cache reports rejected with a reason" 'art mod:cache | grep -q "placed by no file type" && art mod:cache -v | grep -q "app/Models/User.php: placed by no file type" && art mod:clear'
chk "case-only mismatch uses the existing folder" 'art mod:model knowledge:Note | grep -q "Using existing module Knowledge (you typed knowledge)." && grep -q "namespace App.Modules.Knowledge.Models;" app/Modules/Knowledge/Models/Note.php'
chk "new module line" 'art mod:model Agents:Conversation | grep -q "Created new module Agents"'
chk "dash-free mod:viewmodel" 'art mod:viewmodel Knowledge:ListDocuments | grep -q ViewModels/ListDocuments.php'
chk "a command the layout lacks exits 1 and names the layout" '! "$PHP" artisan mod:handler Knowledge:X >/dev/null 2>&1 && art mod:handler Knowledge:X | grep -q "The slices layout has it."'
chk "module routes via a provider" 'art mod:provider Knowledge:Knowledge | grep -q Providers/KnowledgeServiceProvider.php && perl -0pi -e "s/(public function boot\\(\\): void\\s*\\{)\\s*\\/\\/\\n/\\1\\n        \\\$this->loadRoutesFrom(__DIR__.\x27\\/..\\/routes\\/web.php\x27);\\n/" app/Modules/Knowledge/Providers/KnowledgeServiceProvider.php && mkdir -p app/Modules/Knowledge/routes && printf "<?php\n\nuse Illuminate\\\\Support\\\\Facades\\\\Route;\n\nRoute::middleware(\x27web\x27)->group(function () {\n    Route::get(\x27knowledge\x27, fn () => \x27ok\x27)->name(\x27knowledge.home\x27);\n});\n" > app/Modules/Knowledge/routes/web.php && art route:list --name=knowledge.home | grep -q knowledge'

chk "optimize runs mod" 'art optimize | grep -q " mod " && art optimize:clear | grep -q " mod "'
chk "package docs installed" 'test -f vendor/tey/mod/docs/layouts.md'
lay modules
chk "inventory JSON first" 'art mod:list --json > inventory.json && "$PHP" -r '\''$i=json_decode(file_get_contents("inventory.json"),true,512,JSON_THROW_ON_ERROR); exit(isset($i["layout"],$i["types"],$i["templates"],$i["scaffolds"])?0:1);'\'''
chk "create a generator template" 'art mod:template class tool --no-interaction | grep -q "stubs/mod/@module/Tools/tool.stub"'
chk "generate from the template" 'art mod:tool Knowledge:SearchDocuments --no-interaction | grep -q "app/Modules/Knowledge/Tools/SearchDocuments.php"'
chk "extract a template without loading the class" 'art mod:template --from=SearchDocuments --into=@module/Tools/search --no-interaction | grep -q "stubs/mod/@module/Tools/search.stub"'
cat > app/Providers/AppServiceProvider.php <<'PHP'
<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mod::scaffold('document', fn (Scaffold $s) => $s
            ->makes('model', as: 'model')
            ->makes('controller', name: '{name}Controller', as: 'controller',
                options: ['--resource']));
    }
}
PHP
chk "README scaffold recipe" 'art mod:document Knowledge:Document --no-interaction | grep -q "app/Modules/Knowledge/Controllers/DocumentController.php" && test -f app/Modules/Knowledge/Models/Document.php'
chk "scaffold collision choice" 'art mod:document Knowledge:Document --skip-existing --no-interaction && test -f app/Modules/Knowledge/Models/Document.php'
lay ddd
chk "autoload Domain roots" 'art mod:autoload --no-dump --no-interaction && "$PHP" -r '\''$i=json_decode(file_get_contents("composer.json"),true); exit(($i["autoload"]["psr-4"]["Domain\\"]??null)==="src/Domain/"?0:1);'\'''
lay slices
chk "slices: handler --in=feature/slice, no name" 'art mod:handler --in=Knowledge/IndexDocument | grep -q "app/Knowledge/IndexDocument/Handler.php"'
chk "slices: command fallback" 'art mod:command PruneDocuments | grep -q "app/Console/Commands/PruneDocuments.php"'
lay features
chk "features: command fallback" 'art mod:command PruneDocuments | grep -q "app/Console/Commands/PruneDocuments.php"'
lay type-first
chk "type-first: optional feature" 'art mod:model Document | grep -q "app/Models/Document.php"'
lay ddd
chk "ddd: nested domain" 'art mod:model Report --domain=Knowledge.Search | grep -q "src/Domain/Knowledge/Search/Models/Report.php"'
chk "ddd: base in src/Domain/Shared" 'art mod:dto Knowledge:DocumentData | grep -q "src/Domain/Shared/Data/DataTransferObject.php"'
chk "ddd: all four starters" 'art mod:view-model Knowledge:ShowDocument | grep -q ViewModels && art mod:value-object Knowledge:ContentHash | grep -q ValueObjects && art mod:action Knowledge:IndexDocument | grep -q Actions && art mod:data Knowledge:ChunkData | grep -q Data/ChunkData'
lay laravel
chk "laravel: places like make:*" 'art mod:model Document | grep -q "app/Models/Document.php"'
git reset -q --hard; git clean -fdq
done
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]

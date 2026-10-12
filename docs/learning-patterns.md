# Learning an app's patterns

Install mod's Boost resources with `php artisan boost:install` and select `tey/mod`. Load `mod-learning-patterns` when asking an agent to turn this app's existing conventions into a layout and scaffold recipes. The skill surveys with `mod-survey`, confirms recurring sets by reading source and history, proposes code, checks it with `mod-fit`, and iterates. It never adopts the proposal automatically. If the fit tool is not installed, the proposal must say that fit remains unverified.

## Names carried in page folders

`mod-survey` keeps its basename `naming_patterns` and adds `page_patterns` for
paths below each concrete pages `root`. Repeated constant basenames across at
least two first-level folders can reveal `{name}/{action}/Page.vue` and
`{name}/Layout.vue`. Repeated child-folder names with varying filenames can
also reveal `{name}/**/Partials/*`. Each row includes the observed `files`.
The placeholders describe varying segments, not an inferred business purpose:
confirm the name and action roles in source before proposing a recipe.

`page_entries` lists each observed root's entry `stems` and `source`: `declared`
for an explicit inclusion policy, or `inferred` from literal `Inertia::render()`
references to existing files. Inferred stems are evidence, never an automatically
adopted policy; an empty inferred list means no literal evidence, not that all
files are non-entries. Mounted owners retain their own declarations. Unreferenced
entries, computed render names and frontend imports cannot establish stems.

For example, a root can report `{"root":"resources/js/pages","source":"declared","stems":["Modal","Page"]}`
alongside a pattern `{"root":"resources/js/pages","pattern":"{name}/{action}/Page.vue","files":["resources/js/pages/Gadget/Show/Page.vue","resources/js/pages/Widget/Edit/Page.vue"]}`.
Pass a proposed `pageEntries` and folder member to [mod-fit](fit.md#folder-members-and-page-entries)
to review excluded render identities without installing the proposal. Component
and layout files remain owned files even when they are not entries.

## Worked example: the survey fixture

`tests/Feature/Boost/SurveyFixture.php` supplies a small Laravel-shaped app. Its survey is pinned in `tests/Fixtures/Boost/survey-laravel.json`. Run the executable acceptance example from the package root:

```bash
vendor/bin/pest tests/Feature/BoostResourcesTest.php --filter='generates the documented survey-derived recipe'
```

The test calls the survey tool, checks its evidence, then registers the exact PHP proposal below only in an owned, disposable test app. It verifies `mod:list --json`, a read-only preview and real scaffold generation. It does not require the fit tool or claim a fit result.

### Survey and confirm

There is no Git repository in this disposable fixture, so branch, commit and historical adoption are unknown. In a real app, first compare local branch activity and state the chosen ref, commit and dirty state. Do not assume the default branch is the development line.

| Observation | Source check | Decision |
| --- | --- | --- |
| Post and Comment have model-centred sets spanning controllers, requests, policies and pages. | Both controllers import their model and store request; `index` renders a plural page and `store` takes the request. | Propose a model/controller/store-request core, not a keyword-derived business feature. |
| Models are in `app/Models`, HTTP classes in `app/Http`, policies in `app/Policies`, factories in `database/factories`. | Namespaces agree with the fixture's Composer roots. | Extend `laravel` without moving files or adding a group dimension. |
| Requests are `StorePostRequest` and `StoreCommentRequest`. | These are form requests, but they have no application validation or authorization implementation. | Preserve `Store{name}Request`; leave validation and authorization for explicit review. |
| Controllers have registered routes. | GET posts calls `PostController::index`; POST comments calls `CommentController::store`. Comment has an explicit Gate policy; Post's policy link is conventional. Policies have no methods. | Placement and entrypoints are demonstrated, not a working authorized endpoint. Do not template these incomplete bodies. |
| Factories repeat by model name. | Both extend Laravel's factory; both models use `HasFactory`. Factory definitions are incomplete. | Leave factories out of the minimal core; review their data definitions and model linkage separately. |
| Base controller and `routes/web.php` occur in both sets. | They are shared, not owned by one model. | Do not regenerate either per feature. |
| Both features have Index/Show Vue pages; only Post has a migration. | The pages are placeholders; `Markdown` uses vendor classes unrelated to the model sets. | Leave pages, route wiring, migrations and the vendor service out of this minimal PHP recipe. Record them as separate work. |

This fixture is intentionally incomplete. Checking that a feature is alive prevents copying its missing business behaviour; it does not prevent demonstrating supported placement and naming with Laravel's own generators.

### Propose code for review

The proposed layout is `learned`, a copy of `laravel` with no placement changes. An app could keep `laravel` instead; the named copy makes this example's candidate visible in the inventory. The recipe only needs a name. No package installation, custom template or Composer change is required.

Proposed registration in an app provider's `boot()` method, with these imports at the top of the provider:

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;

Mod::layout('learned')->extends('laravel');

Mod::scaffold('record', fn (Scaffold $s) => $s
    ->makes('model', as: 'model')
    ->makes('request', name: 'Store{name}Request', as: 'storeRequest')
    ->makes('policy', name: '{name}Policy', as: 'policy')
    ->makes('controller', name: '{name}Controller', as: 'controller'));
```

Proposed `config/mod.php` selection: `'layout' => 'learned'`. The controller and policy start with Laravel's ordinary empty shapes, not a promise to implement CRUD. Connecting the controller to the store request, routes and pages, and implementing policy methods and authorization, remains manual work. Sibling aliases can fill templates, but do not assume flat scaffold generator options interpolate them; verify the installed version's output.

### Check, generate in the fixture, and iterate

In the app, ask `mod-fit` to check the candidate using its installed contract, then reconcile discrepancies with the source evidence. No fit schema is assumed here. In this example that check is pending; generator validation is independent.

After registering the proposal only in the disposable test app:

```bash
php artisan mod:list --json
php artisan mod:record Article --dry-run --json --no-interaction
php artisan mod:record Article --no-interaction
```

The inventory must report layout `learned`, parent `laravel`, and the `record` scaffold. The preview must have `would_write: true`, no warnings, and exactly these files; generation must create the same files with their matching namespaces:

| File | Namespace |
| --- | --- |
| `app/Models/Article.php` | `App\Models` |
| `app/Http/Requests/StoreArticleRequest.php` | `App\Http\Requests` |
| `app/Policies/ArticlePolicy.php` | `App\Policies` |
| `app/Http/Controllers/ArticleController.php` | `App\Http\Controllers` |

Generated files must name Article, not copy Post or Comment identities. Existing fixture files must remain byte-identical. If a later code or fit check shows a different request prefix, namespace or shared member, revise the candidate and repeat the inventory, preview and generation checks. Return the proposal, evidence and pending runtime/fit work for human review; do not apply it to the live app.

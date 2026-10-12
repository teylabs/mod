<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\IndexPage;

const REQUEST_WITHOUT_FILTER = <<<'PHP'
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceIndexRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    public const SORTS = [
        'number',
        'customer_name',
    ];

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'sort' => ['nullable', 'in:'.implode(',', self::SORTS)],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }
}

PHP;

const PAGE_WITH_FILTER = <<<'VUE'
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';

defineProps<{
    rows: Array<Record<string, string>>;
    statuses: string[];
}>();

function sort(key: string) {
    router.get('?sort=' + key);
}
</script>

<template>
    <Head title="Invoice" />

    <select @change="router.reload({ data: { status: $event.target.value } })">
        <option v-for="status in statuses" :key="status">{{ status }}</option>
    </select>
    <table>
        <thead>
            <tr>
                <th>
                    <button type="button" @click="sort('number')">Number</button>
                </th>
                <th>
                    <button type="button" @click="sort('customer_name')">Customer</button>
                </th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="row in rows" :key="row.id">
                <td>{{ row.number }}</td>
                <td>{{ row.customer_name }}</td>
                <td>{{ row.notes }}</td>
            </tr>
        </tbody>
    </table>
</template>

VUE;

/** @param array<string, mixed> $flags */
function generateIndex(Workspace $w, array $flags = []): void
{
    IndexPage::setup($w);
    $w->artisan('mod:acme/index', ['name' => 'Invoice', '--columns' => IndexPage::COLUMNS, ...$flags])->assertSuccessful();
}

it('repeats columns and drops the status filter when it is off', function () {
    Workspace::run(null, function (Workspace $w) {
        generateIndex($w, ['--no-status_filter' => true]);

        $page = $w->read(IndexPage::PAGE);
        expect($w->read(IndexPage::REQUEST))->toBe(REQUEST_WITHOUT_FILTER)
            ->and($page)->toContain("<th>Notes</th>\n            </tr>")
            ->and(str_contains($page, 'statuses') || str_contains($page, '<select'))->toBeFalse();
    });
});

it('keeps the status filter in the request and the page when it is on', function () {
    Workspace::run(null, function (Workspace $w) {
        generateIndex($w, ['--status_filter' => true]);

        expect($w->read(IndexPage::REQUEST))->toBe(str_replace("            'direction' => ['nullable', 'in:asc,desc'],\n", "            'direction' => ['nullable', 'in:asc,desc'],\n            'status' => ['nullable', 'string'],\n", REQUEST_WITHOUT_FILTER))
            ->and($w->read(IndexPage::PAGE))->toBe(PAGE_WITH_FILTER);
    });
});

it('uses the recipe default records and boolean without flags', function () {
    Workspace::run(null, function (Workspace $w) {
        IndexPage::setup($w);
        $w->artisan('mod:acme/index', ['name' => 'Invoice'])->assertSuccessful();

        $request = $w->read(IndexPage::REQUEST);
        expect($request)->toContain("        'number',\n    ];")
            ->and(str_contains($request, 'customer_name') || str_contains($request, "'status'"))->toBeFalse();
    });
});

it('renders nothing for an empty list of columns', function () {
    Workspace::run(null, function (Workspace $w) {
        generateIndex($w, ['--columns' => '[]']);

        $page = $w->read(IndexPage::PAGE);
        expect($w->read(IndexPage::REQUEST))->toContain("SORTS = [\n    ];")
            ->and($page)->toContain("<tr>\n            </tr>")
            ->and(str_contains($page, '<th>'))->toBeFalse();
    });
});

it('regenerates byte for byte', function (bool $filter) {
    Workspace::run(null, function (Workspace $w) use ($filter) {
        generateIndex($w, [$filter ? '--status_filter' : '--no-status_filter' => true]);
        $first = [$w->read(IndexPage::REQUEST), $w->read(IndexPage::PAGE)];
        $w->remove([IndexPage::REQUEST, IndexPage::PAGE]);
        $w->artisan('mod:acme/index', ['name' => 'Invoice', '--columns' => IndexPage::COLUMNS, $filter ? '--status_filter' : '--no-status_filter' => true])->assertSuccessful();
        $second = [$w->read(IndexPage::REQUEST), $w->read(IndexPage::PAGE)];
        $w->artisan('mod:acme/index', ['name' => 'Invoice', '--columns' => IndexPage::COLUMNS, $filter ? '--status_filter' : '--no-status_filter' => true, '--force' => true])->assertSuccessful();

        expect($second)->toBe($first)->and([$w->read(IndexPage::REQUEST), $w->read(IndexPage::PAGE)])->toBe($first);
    });
})->with([true, false]);

it('passes Pint with and without the status filter', function (bool $filter) {
    Workspace::run(null, function (Workspace $w) use ($filter) {
        generateIndex($w, [$filter ? '--status_filter' : '--no-status_filter' => true]);
        IndexPage::pint($w, IndexPage::REQUEST);
    });
})->with([true, false]);

it('passes Prettier with and without the status filter', function (bool $filter) {
    Workspace::run(null, function (Workspace $w) use ($filter) {
        generateIndex($w, [$filter ? '--status_filter' : '--no-status_filter' => true]);
        IndexPage::prettier($w, ['singleQuote' => true, 'tabWidth' => 4, 'printWidth' => 100], IndexPage::PAGE);
    });
})->with([true, false])->skip(getenv('MOD_FRONTEND_BUILD') !== '1', 'Set MOD_FRONTEND_BUILD=1 after npm ci in tests/Fixtures/frontend-build.');

it('passes Pint and keeps the optional parts apart', function (bool $filter, bool $modal, bool $authorize) {
    Workspace::run(null, function (Workspace $w) use ($filter, $modal, $authorize) {
        generateIndex($w, [
            $filter ? '--status_filter' : '--no-status_filter' => true,
            $modal ? '--modal' : '--no-modal' => true,
            $authorize ? '--authorize' : '--no-authorize' => true,
        ]);
        $files = [IndexPage::REQUEST, ...($authorize ? [IndexPage::POLICY] : [])];
        IndexPage::pint($w, ...$files);

        expect($w->exists(IndexPage::MODAL))->toBe($modal)
            ->and($w->exists(IndexPage::POLICY))->toBe($authorize)
            ->and(str_contains($w->read(IndexPage::PAGE), "import Modal from './Modal.vue';"))->toBe($modal)
            ->and(str_contains($w->read(IndexPage::REQUEST), "'status'"))->toBe($filter);
    });
})->with([true, false])->with([true, false])->with([true, false]);

it('passes Prettier for every page with and without its optional parts', function (bool $filter, bool $modal) {
    Workspace::run(null, function (Workspace $w) use ($filter, $modal) {
        generateIndex($w, [
            $filter ? '--status_filter' : '--no-status_filter' => true,
            $modal ? '--modal' : '--no-modal' => true,
        ]);
        IndexPage::prettier($w, ['singleQuote' => true, 'tabWidth' => 4, 'printWidth' => 100], IndexPage::PAGE, ...($modal ? [IndexPage::MODAL] : []));
    });
})->with([true, false])->with([true, false])->skip(getenv('MOD_FRONTEND_BUILD') !== '1', 'Set MOD_FRONTEND_BUILD=1 after npm ci in tests/Fixtures/frontend-build.');

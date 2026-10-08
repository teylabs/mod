<?php

namespace Tey\Mod\Generation;

/**
 * Starter stubs for the file types Laravel has no generator for: plain
 * classes to get going, which use spatie/laravel-data,
 * spatie/laravel-view-models or lorisleiva/laravel-actions when installed.
 *
 * A kind with a matching id gets its starter in any layout, unless the
 * layout gives the kind its own stub:. Another kind opts in with
 * `stub: Starters::dto()`.
 */
final class Starters
{
    private const STUBS = __DIR__.'/stubs/starters';

    /**
     * The kind ids each starter applies to.
     */
    public const KINDS = [
        'dto' => ['dto', 'data', 'data-transfer-object'],
        'view-model' => ['view-model', 'viewmodel'],
        'value-object' => ['value-object', 'value'],
        'action' => ['action'],
    ];

    /**
     * A data transfer object extending spatie/laravel-data's Data, else a
     * generated DataTransferObject base with fromArray() and toArray().
     *
     * @param  ?string  $baseIn  the base's folder below the kind's root ("Shared/Data"), instead of the bases folder
     */
    public static function dto(?string $baseIn = null): Stub
    {
        return Stub::file(self::STUBS.'/dto.stub')
            ->label('DTO')
            ->whenInstalled('spatie/laravel-data', base: 'Spatie\\LaravelData\\Data')
            ->generatesBase(self::base('DataTransferObject', 'Data', 'data-transfer-object', $baseIn));
    }

    /**
     * A view model extending spatie/laravel-view-models' ViewModel, else a generated ViewModel base.
     *
     * @param  ?string  $baseIn  the base's folder below the kind's root ("Shared/ViewModels"), instead of the bases folder
     */
    public static function viewModel(?string $baseIn = null): Stub
    {
        return Stub::file(self::STUBS.'/view-model.stub')
            ->label('View model')
            ->whenInstalled('spatie/laravel-view-models', base: 'Spatie\\ViewModels\\ViewModel')
            ->generatesBase(self::base('ViewModel', 'ViewModels', 'view-model', $baseIn));
    }

    /**
     * A value object: a plain class with a constructor.
     */
    public static function valueObject(): Stub
    {
        return Stub::file(self::STUBS.'/value-object.stub')->label('Value object');
    }

    /**
     * An action with handle(), or lorisleiva/laravel-actions' AsAction when installed.
     */
    public static function action(): Stub
    {
        return Stub::file(self::STUBS.'/action.stub')
            ->label('Action')
            ->whenInstalled('lorisleiva/laravel-actions', stub: self::STUBS.'/action.laravel-actions.stub');
    }

    /**
     * Register every starter under each kind id it applies to.
     */
    public static function register(StubRegistry $registry): StubRegistry
    {
        $stubs = ['dto' => self::dto(), 'view-model' => self::viewModel(), 'value-object' => self::valueObject(), 'action' => self::action()];

        foreach (self::KINDS as $type => $kinds) {
            foreach ($kinds as $kind) {
                $registry->starter($kind, $stubs[$type]);
            }
        }

        return $registry;
    }

    private static function base(string $name, string $in, string $stub, ?string $baseIn): GeneratedBase
    {
        $base = GeneratedBase::named($name, in: $baseIn ?? $in, stub: self::STUBS."/bases/{$stub}.stub");

        return $baseIn === null ? $base : $base->inKindRoot();
    }
}

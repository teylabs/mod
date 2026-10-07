<?php

namespace Tey\Mod\Preset;

/**
 * @internal issue codes of the validator; surfaces through InvalidLayout / InvalidPreset messages.
 */
enum PresetIssueCode: string
{
    case InvalidShape = 'invalid-shape';
    case DuplicateKind = 'duplicate-kind';
    case DuplicateCommandName = 'duplicate-command-name';
    case InvalidKind = 'invalid-kind';
    case UnknownRoot = 'unknown-root';
    case InvalidRoot = 'invalid-root';
    case DuplicateRoot = 'duplicate-root';
    case InvalidDimension = 'invalid-dimension';
    case UnknownDimension = 'unknown-dimension';
    case DuplicatePlacementPattern = 'duplicate-placement-pattern';
    case UnknownRelationTarget = 'unknown-relation-target';
    case InvalidRelation = 'invalid-relation';
}

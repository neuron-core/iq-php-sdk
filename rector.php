<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\For_\RemoveDeadLoopRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddReturnTypeDeclarationRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php81: true)
    ->withPreparedSets(
        codeQuality: true,
        deadCode: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withRules([
        AddReturnTypeDeclarationRector::class,
    ])
    ->withSkip([
        // It types `fn () => throw ...` as `never`, which arrow functions accept only from PHP 8.2.
        AddArrowFunctionReturnTypeRector::class,
        // It removes an empty foreach that drains a generator before getReturn() is called.
        RemoveDeadLoopRector::class,
    ])
    // Renaming would change constructor parameter names, which are public API for named arguments.
    ->withConfiguredRule(ClassPropertyAssignToConstructorPromotionRector::class, [
        ClassPropertyAssignToConstructorPromotionRector::RENAME_PROPERTY => false,
    ]);

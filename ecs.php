<?php

declare(strict_types=1);

use craft\ecs\SetList;
use PhpCsFixer\Fixer\Operator\TernaryOperatorSpacesFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->parallel();
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __FILE__,
    ]);

    $ecsConfig->sets([SetList::CRAFT_CMS_4]);

    // This ECS ships a PHP-CS-Fixer older than enums, and reads the colon in
    // `enum Skill: int` as a ternary, rewriting it to `enum Skill : int`.
    // Scoped to the one directory holding enums rather than turned off, so the
    // fixer still runs everywhere it understands.
    $ecsConfig->skip([
        TernaryOperatorSpacesFixer::class => [__DIR__ . '/src/enums'],
    ]);
};

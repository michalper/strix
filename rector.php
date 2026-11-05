<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

// Dry-run only in this project (see .github/workflows/ci.yml and AGENTS.md): CI runs
// `rector process --dry-run` and fails on any diff, but rewrites are reviewed and
// applied by hand, never auto-committed.
//
// Note: this Rector version does not expose a public Symfony-specific rule set (the
// Symfony rules bundled under vendor/rector/rector/vendor/rector/rector-symfony are an
// internal implementation detail, not part of SetList/LevelSetList) - so "Symfony-version
// rulesets" here means the PHP-version upgrade set, which is what actually governs
// language-level modernization for our PHP 8.5 target.
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_85,
        SetList::DEAD_CODE,
        SetList::CODE_QUALITY,
    ])
    ->withSkip([
        // Reviewed and deliberately rejected: forcing `!$x instanceof Foo` instead of
        // `null === $x` for a simple nullable-return check reads worse, not better, for
        // this codebase's straightforward ?Entity returns.
        ExplicitNullableObjectInstanceRector::class,
    ])
;

<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Console;

use Atrium\Search\Indexer;
use Atrium\Search\Searchables;
use Illuminate\Console\Command;

/**
 * Fills the index anew: after installing the module, after changing what a
 * model is found by, or after records were changed bypassing the models
 * (an import with plain queries).
 */
final class RebuildCommand extends Command
{
    protected $signature = 'atrium:search:rebuild {--model= : Only this model class}';

    protected $description = 'Rebuild the site search index';

    public function handle(Indexer $indexer, Searchables $searchables): int
    {
        $only = $this->option('model');
        $only = is_string($only) && $only !== '' ? ltrim($only, '\\') : null;

        if ($only !== null && ! $searchables->has($only)) {
            $this->error("{$only} is not searched: it has no public pages, or config('atrium.search') leaves it out.");

            return self::FAILURE;
        }

        $counts = $indexer->rebuild($only, fn (string $model, int $count) => $this->line("{$model}: {$count}"));

        $this->info('Indexed '.array_sum($counts).' records.');

        return self::SUCCESS;
    }
}

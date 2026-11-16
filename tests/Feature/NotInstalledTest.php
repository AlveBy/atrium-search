<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Feature;

use Atrium\Search\Models\SearchEntry;
use Atrium\Search\Tests\TestCase;

/**
 * Required by Composer but not installed: no page, no box, no indexing.
 */
final class NotInstalledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('atrium.modules', []);
    }

    public function test_nothing_happens(): void
    {
        $this->product('Garden lamp');

        $this->assertSame(0, SearchEntry::query()->count());
        $this->get('/search?q=lamp')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('data-search-box', false);
    }
}

<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Feature;

use Atrium\Search\Tests\TestCase;

/**
 * An application with a search page of its own keeps it: the module's
 * /search is not added.
 */
final class OwnRouteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['router']->get('find', fn () => 'Our own search')->name('atrium.search');
    }

    public function test_the_applications_own_search_route_wins(): void
    {
        $this->get('/find')->assertOk()->assertSee('Our own search');
        $this->get('/search?q=kettle')->assertNotFound();
    }
}

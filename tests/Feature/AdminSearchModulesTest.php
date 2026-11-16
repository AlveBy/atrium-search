<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Feature;

use Atrium\Search\Tests\Fixtures\Ghost;
use Atrium\Search\Tests\Fixtures\Memo;
use Atrium\Search\Tests\Fixtures\Post;
use Atrium\Search\Tests\Fixtures\Product;
use Atrium\Search\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;

/**
 * The admin box with modules that cannot be searched by text, or whose
 * list fails, and with queries too short to search.
 */
final class AdminSearchModulesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('atrium.resources', [Ghost::class, Post::class, Memo::class, Product::class]);
    }

    public function test_a_query_of_one_character_is_not_searched_two_are(): void
    {
        $this->product('Ox tail soup');

        $this->actingAs($this->admin(), 'admin')->getJson('/admin/search/records?q=o')->assertExactJson(['groups' => []]);
        $this->actingAs($this->admin(), 'admin')->getJson('/admin/search/records?q=+o+')->assertExactJson(['groups' => []]);
        $this->withoutMiddleware(TrimStrings::class)->actingAs($this->admin(), 'admin')->getJson('/admin/search/records?q=+o+')->assertExactJson(['groups' => []]);
        $this->actingAs($this->admin(), 'admin')->getJson('/admin/search/records?q=ox')->assertJsonPath('groups.0.items.0.label', 'Ox tail soup');
    }

    public function test_one_letter_counts_as_one_character(): void
    {
        $this->product('Ёжик');

        $this->actingAs($this->admin(), 'admin')->getJson('/admin/search/records?q=ж')->assertExactJson(['groups' => []]);
        $this->actingAs($this->admin(), 'admin')->getJson('/admin/search/records?q=жи')->assertJsonPath('groups.0.items.0.label', 'Ёжик');
    }

    public function test_a_module_without_searchable_columns_is_found_by_id_only(): void
    {
        $memo = Memo::query()->forceCreate(['id' => 12, 'name' => 'Memo', 'code' => 'memo']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->getJson('/admin/search/records?q=memo')->assertJsonMissing(['module' => 'memos']);
        $this->actingAs($admin, 'admin')->getJson('/admin/search/records?q='.$memo->id)->assertJsonFragment(['module' => 'memos']);
    }

    public function test_a_module_whose_list_fails_is_reported_and_the_others_still_answer(): void
    {
        Exceptions::fake();
        $this->product('Teapot');

        $this->actingAs($this->admin(), 'admin')
            ->getJson('/admin/search/records?q=teapot')
            ->assertOk()
            ->assertJsonPath('groups.0.module', 'products')
            ->assertJsonCount(1, 'groups');

        Exceptions::assertReported(RuntimeException::class);
    }
}

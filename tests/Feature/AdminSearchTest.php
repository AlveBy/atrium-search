<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Feature;

use Atrium\Admin\Models\AdminRole;
use Atrium\Search\Tests\TestCase;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia;

/**
 * The editors' box in the admin header: every module the editor may see,
 * inactive records included, with links to their forms.
 */
final class AdminSearchTest extends TestCase
{
    public function test_editors_find_records_of_every_module(): void
    {
        $hidden = $this->product('Hidden teapot', '', ['active' => false]);
        $post = $this->makePost(['en' => 'Teapot workshop']);

        $this->actingAs($this->admin(), 'admin')
            ->getJson('/admin/search/records?q=teapot')
            ->assertOk()
            ->assertJsonPath('groups.0.module', 'posts')
            ->assertJsonPath('groups.0.label', 'News')
            ->assertJsonPath('groups.0.items.0.label', 'Teapot workshop')
            ->assertJsonPath('groups.0.items.0.url', "http://localhost/admin/posts/{$post->id}/edit")
            ->assertJsonPath('groups.1.module', 'products')
            ->assertJsonPath('groups.1.items.0.id', $hidden->id);
    }

    public function test_nothing_is_reported_when_every_module_answers(): void
    {
        Exceptions::fake();

        $this->actingAs($this->admin(), 'admin')->getJson('/admin/search/records?q=teapot')->assertExactJson(['groups' => []]);

        Exceptions::assertNothingReported();
    }

    public function test_modules_the_editor_may_not_see_are_left_out(): void
    {
        $this->product('Teapot');

        $this->actingAs($this->admin(superadmin: false), 'admin')
            ->getJson('/admin/search/records?q=teapot')
            ->assertOk()
            ->assertExactJson(['groups' => []]);
    }

    public function test_guests_get_nothing(): void
    {
        $this->getJson('/admin/search/records?q=teapot')->assertUnauthorized();
    }

    public function test_admin_pages_get_the_address_of_the_box(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('adminSearch.url', '/admin/search/records'));
    }

    public function test_an_editor_finds_only_in_the_modules_his_role_shows(): void
    {
        $this->product('Teapot');
        $this->makePost(['en' => 'Teapot workshop']);
        $editor = $this->admin(superadmin: false);
        $editor->roles()->attach(AdminRole::query()->create(['name' => 'Shop', 'permissions' => ['products.view']]));

        $this->actingAs($editor, 'admin')
            ->getJson('/admin/search/records?q=teapot')
            ->assertJsonCount(1, 'groups')
            ->assertJsonPath('groups.0.module', 'products');
    }

    public function test_a_record_is_found_by_its_id_with_a_link_and_a_hint(): void
    {
        $this->product('Kettle');
        $teapot = $this->product('Teapot', '', ['id' => 23]);

        $this->actingAs($this->admin(), 'admin')
            ->getJson('/admin/search/records?q='.$teapot->id)
            ->assertExactJson(['groups' => [[
                'module' => 'products',
                'label' => 'Products',
                'items' => [[
                    'id' => $teapot->id,
                    'label' => 'Teapot',
                    'url' => "http://localhost/admin/products/{$teapot->id}/edit",
                    'hint' => '#'.$teapot->id,
                ]],
            ]]]);
    }
}

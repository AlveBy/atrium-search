<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Feature;

use Atrium\Admin\Models\AdminRole;
use Atrium\Admin\Models\Language;
use Atrium\Search\Mcp\SearchSite;
use Atrium\Search\Search;
use Atrium\Search\Searchables;
use Atrium\Search\Tests\Fixtures\Event;
use Atrium\Search\Tests\TestCase;

/**
 * Programs and AI assistants search the site through the API, within what
 * their token may read; the MCP tool search_site goes through the same.
 */
final class ApiTest extends TestCase
{
    public function test_the_api_finds_only_in_modules_the_token_may_read(): void
    {
        $this->product('Travel kettle', '<p>Small and <b>light</b>.</p>');
        $this->makePost(['en' => 'Kettle recall']);
        $token = $this->admin()->createToken('agent', ['products:read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/site-search?q=kettle')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.module', 'products')
            ->assertJsonPath('data.0.title', 'Travel kettle')
            ->assertJsonPath('data.0.url', 'http://localhost/shop/travel-kettle')
            ->assertJsonPath('data.0.snippet', 'Small and light.')
            ->assertJsonPath('counts', ['products' => 1])
            ->assertJsonPath('meta.total', 1);

        $this->withToken($token)->getJson('/api/v1/site-search?q=kettle&module=posts')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($token)->getJson('/api/v1/site-search')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/site-search?q=kettle&locale=xx')->assertUnprocessable();
    }

    public function test_the_api_searches_in_the_language_asked(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        $this->makePost(['en' => 'Kettle recall', 'de' => 'Wasserkocher Rückruf']);
        $token = $this->admin()->createToken('agent', ['posts:read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/site-search?q=wasserkocher')->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/site-search?q=wasserkocher&locale=de')
            ->assertJsonPath('data.0.url', 'http://localhost/de/news/wasserkocher-ruckruf');
    }

    public function test_the_api_needs_a_token(): void
    {
        $this->getJson('/api/v1/site-search?q=kettle')->assertUnauthorized();
    }

    public function test_the_mcp_tool_is_offered_with_its_arguments(): void
    {
        $this->assertContains(SearchSite::class, config('atrium.mcp.tools'));

        $token = $this->admin()->createToken('agent', ['products:read'])->plainTextToken;
        $tools = collect($this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk()->json('result.tools'));
        $tool = $tools->firstWhere('name', 'search_site');

        $this->assertSame(['query', 'module', 'locale', 'site', 'page', 'per_page'], array_keys($tool['inputSchema']['properties']));
        $this->assertSame(['query'], $tool['inputSchema']['required']);
        $this->assertSame('integer', $tool['inputSchema']['properties']['per_page']['type']);
        $this->assertSame('string', $tool['inputSchema']['properties']['locale']['type']);
        $this->assertTrue($tool['annotations']['readOnlyHint']);
    }

    public function test_the_mcp_tool_searches_like_the_api(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        $this->product('Travel kettle');
        $this->product('Tea kettle');
        $this->makePost(['en' => 'Kettle recall', 'de' => 'Wasserkocher Rückruf']);
        $token = $this->admin()->createToken('agent', ['products:read', 'posts:read'])->plainTextToken;

        $all = $this->callTool($token, ['query' => 'kettle', 'module' => '', 'site' => '']);
        $this->assertSame(['posts' => 1, 'products' => 2], $all['counts']);
        $this->assertCount(3, $all['data']);

        $page = $this->callTool($token, ['query' => 'kettle', 'module' => 'products', 'page' => 2, 'per_page' => 1]);
        $this->assertSame(['products'], array_unique(array_column($page['data'], 'module')));
        $this->assertSame([2, 1, 2], [$page['meta']['page'], $page['meta']['per_page'], $page['meta']['total']]);

        $german = $this->callTool($token, ['query' => 'wasserkocher', 'locale' => 'de']);
        $this->assertSame('http://localhost/de/news/wasserkocher-ruckruf', $german['data'][0]['url']);

        $this->assertSame([], $this->callTool($token, ['query' => 'kettle', 'site' => 'missing'], error: true));
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<mixed>
     */
    private function callTool(string $token, array $arguments, bool $error = false): array
    {
        $this->app['auth']->forgetGuards();
        $result = $this->withToken($token)
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'search_site', 'arguments' => $arguments]])
            ->assertOk()
            ->json('result');

        $this->assertSame($error, $result['isError'], (string) ($result['content'][0]['text'] ?? ''));

        return $error ? [] : json_decode($result['content'][0]['text'], true);
    }

    public function test_empty_optional_parameters_are_left_unset(): void
    {
        $this->product('Travel kettle');
        $token = $this->admin()->createToken('agent', ['products:read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/site-search?q=kettle&module=&page=&per_page=&locale=')
            ->assertOk()
            ->assertJsonPath('counts', ['products' => 1])
            ->assertJsonPath('meta', ['page' => 1, 'per_page' => 10, 'total' => 1, 'last_page' => 1]);
    }

    public function test_a_token_that_may_read_nothing_found_gets_an_empty_page(): void
    {
        $this->makePost(['en' => 'Kettle recall']);
        $token = $this->admin()->createToken('agent', ['products:read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/site-search?q=kettle')
            ->assertExactJson([
                'data' => [],
                'counts' => [],
                'meta' => ['page' => 1, 'per_page' => 10, 'total' => 0, 'last_page' => 1],
            ]);
        $this->withToken($token)->getJson('/api/v1/site-search?q=kettle&per_page=3')
            ->assertJsonPath('meta', ['page' => 1, 'per_page' => 3, 'total' => 0, 'last_page' => 1]);
    }

    public function test_pages_are_counted_from_the_number_per_page(): void
    {
        $this->product('Travel kettle');
        $this->product('Tea kettle');
        $this->product('Kettle descaler');
        $token = $this->admin()->createToken('agent', ['products:read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/site-search?q=kettle&per_page=2')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta', ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
        $this->withToken($token)->getJson('/api/v1/site-search?q=kettle&per_page=2&page=2')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta', ['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
    }

    public function test_records_of_a_module_without_an_api_are_not_given_out(): void
    {
        config(['atrium.public_urls' => [...config('atrium.public_urls'), Event::class => ['prefix' => 'events']]]);
        app(Searchables::class)->flush();
        Event::query()->create(['name' => 'Kettle fair', 'url' => '/events/kettle-fair']);
        $this->product('Travel kettle');
        $token = $this->admin()->createToken('agent', ['*'])->plainTextToken;

        $this->assertSame(1, app(Search::class)->counts('kettle')[Event::class]);
        $this->withToken($token)->getJson('/api/v1/site-search?q=kettle')
            ->assertJsonPath('counts', ['products' => 1])
            ->assertJsonCount(1, 'data');
    }

    public function test_an_editor_without_the_right_to_see_a_module_finds_nothing_in_it(): void
    {
        $this->product('Travel kettle');
        $this->makePost(['en' => 'Kettle recall']);
        $editor = $this->admin(superadmin: false);
        $editor->roles()->attach(AdminRole::query()->create(['name' => 'News', 'permissions' => ['posts.view']]));
        $token = $editor->createToken('agent', ['products:read', 'posts:read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/site-search?q=kettle')
            ->assertJsonPath('counts', ['posts' => 1])
            ->assertJsonPath('data.0.module', 'posts');
    }
}

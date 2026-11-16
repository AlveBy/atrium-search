<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests;

use Atrium\Admin\AtriumServiceProvider;
use Atrium\Admin\Models\AdminUser;
use Atrium\Admin\Support\PublicUrl;
use Atrium\Search\SearchServiceProvider;
use Atrium\Search\Tests\Fixtures\Post;
use Atrium\Search\Tests\Fixtures\Product;
use Atrium\Theme\ThemeServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Sanctum\Sanctum;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use Tighten\Ziggy\ZiggyServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase {
        beginDatabaseTransaction as beginRefreshTransaction;
    }

    /**
     * Tables the tests write to, cleaned by hand on MySQL (see below):
     * table => whether rows that were there before stay.
     */
    private const TABLES = [
        'search_index' => false,
        'search_test_posts' => false,
        'search_test_products' => false,
        'search_test_events' => false,
        'personal_access_tokens' => false,
        'admin_users' => true,
        'admin_roles' => true,
        'admin_sites' => true,
        'admin_languages' => true,
    ];

    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            ZiggyServiceProvider::class,
            McpServiceProvider::class,
            AtriumServiceProvider::class,
            ThemeServiceProvider::class,
            SearchServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('app.name', 'Shop');
        $target = env('DB_CONNECTION');
        $config->set('database.default', in_array($target, ['mysql', 'pgsql'], true) ? $target : 'testing');
        $config->set('cache.default', 'array');
        $config->set('atrium.demo', false);
        $config->set('atrium.modules', ['alveby/atrium-search']);
        $config->set('atrium.resources', [Post::class, Product::class]);
        $config->set('atrium.public_urls', [
            Post::class => ['prefix' => 'news'],
            Product::class => ['prefix' => 'shop'],
        ]);
        $config->set('atrium.auth.two_factor.enabled', false);
        $config->set('atrium.dashboard.timings.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        PublicUrl::forgetPaths();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadMigrationsFrom(dirname((string) (new ReflectionClass(Sanctum::class))->getFileName(), 2).'/database/migrations');
    }

    /**
     * MySQL's full-text index sees only committed rows, so on MySQL a test
     * does not run inside a transaction: it commits, and what it wrote is
     * deleted before and after.
     */
    public function beginDatabaseTransaction(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->beginRefreshTransaction();

            return;
        }

        $kept = [];

        foreach (self::TABLES as $table => $keep) {
            if (Schema::hasTable($table)) {
                $kept[$table] = $keep ? (int) DB::table($table)->max('id') : 0;
                DB::table($table)->where('id', '>', $kept[$table])->delete();
            }
        }

        $this->beforeApplicationDestroyed(function () use ($kept): void {
            foreach ($kept as $table => $max) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where('id', '>', $max)->delete();
                }
            }
        });
    }

    protected function admin(bool $superadmin = true): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin'.AdminUser::query()->count().'@example.com',
            'password' => 'secret-password',
            'is_active' => true,
        ]);

        $admin->forceFill(['is_superadmin' => $superadmin])->save();

        return $admin;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    protected function product(string $name, string $description = '', array $attributes = []): Product
    {
        return Product::query()->create([
            'name' => $name,
            'code' => str($name)->slug()->toString(),
            'description' => $description,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string,string>  $names  language => name
     * @param  array<string,mixed>  $attributes
     */
    protected function makePost(array $names, array $attributes = []): Post
    {
        return Post::query()->create([
            'name' => $names,
            'code' => array_map(static fn (string $name): string => str($name)->slug()->toString(), $names),
            ...$attributes,
        ]);
    }
}

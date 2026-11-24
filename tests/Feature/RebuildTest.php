<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Feature;

use Atrium\Admin\Models\Language;
use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\RecordDeleter;
use Atrium\Search\Indexer;
use Atrium\Search\Models\SearchEntry;
use Atrium\Search\Tests\Fixtures\Post;
use Atrium\Search\Tests\Fixtures\Product;
use Atrium\Search\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The rebuild of the index: of every model or one, with the count of rows
 * per model, reading records and writing rows in batches.
 */
final class RebuildTest extends TestCase
{
    /**
     * @return array<int,string>
     */
    private function queries(string $verb, string $table): array
    {
        return array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $sql): bool => str_starts_with($sql, $verb.' ') && str_contains($sql, $table),
        ));
    }

    public function test_the_command_tells_the_rows_of_each_model_and_in_all(): void
    {
        $this->product('Kettle');
        $this->product('Teapot');
        $this->makePost(['en' => 'Kettle news']);

        $this->artisan('atrium:search:rebuild')
            ->expectsOutput(Post::class.': 1')
            ->expectsOutput(Product::class.': 2')
            ->expectsOutput('Indexed 3 records.')
            ->assertSuccessful();
    }

    public function test_the_command_names_a_model_that_is_not_searched(): void
    {
        $this->artisan('atrium:search:rebuild', ['--model' => '\\Acme\\Nope'])
            ->expectsOutput("Acme\\Nope is not searched: it has no public pages, or config('atrium.search') leaves it out.")
            ->assertFailed();
    }

    public function test_one_model_is_rebuilt_by_its_class_with_or_without_the_leading_backslash(): void
    {
        $this->product('Kettle');
        $this->product('Teapot');
        $this->makePost(['en' => 'Kettle news']);
        SearchEntry::query()->delete();

        $this->assertSame([Product::class => 2], app(Indexer::class)->rebuild('\\'.Product::class));
        $this->assertSame([Post::class => 1], app(Indexer::class)->rebuild(Post::class));
        $this->assertSame(3, SearchEntry::query()->count());
    }

    /**
     * @return array<string,array{int,int}>
     */
    public static function records(): array
    {
        return [
            'one short of a batch' => [199, 1],
            'a whole batch' => [200, 2],
        ];
    }

    #[DataProvider('records')]
    public function test_records_are_read_two_hundred_at_a_time(int $records, int $reads): void
    {
        DB::table('search_test_products')->insert(array_map(static fn (int $i): array => ['name' => "Kettle {$i}", 'code' => "kettle-{$i}"], range(1, $records)));
        DB::enableQueryLog();

        $this->assertSame([Product::class => $records], app(Indexer::class)->rebuild(Product::class));
        $this->assertCount($reads, $this->queries('select', 'search_test_products'));
    }

    public function test_the_rows_of_a_record_are_written_fifty_at_a_time(): void
    {
        $names = ['en' => 'Kettle'];

        foreach (range(1, 50) as $i) {
            $code = sprintf('l%02d', $i);
            Language::query()->create(['code' => $code, 'name' => "Language {$i}"]);
            $names[$code] = "Kettle {$i}";
        }

        $this->makePost($names);
        $this->makePost(array_slice($names, 0, 50, true));
        DB::enableQueryLog();

        $this->assertSame([Post::class => 101], app(Indexer::class)->rebuild(Post::class));
        $this->assertCount(3, $this->queries('insert', 'search_index'));
    }

    public function test_the_rebuild_takes_the_records_of_every_site_and_leaves_the_trash_out(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        $this->makePost(['en' => 'Here']);
        $this->makePost(['en' => 'There'], ['site_id' => $second->id]);
        app(RecordDeleter::class)->delete($this->makePost(['en' => 'Binned']));
        SearchEntry::query()->delete();

        $this->assertSame([Post::class => 2], app(Indexer::class)->rebuild(Post::class));
        $this->assertSame(['Here', 'There'], SearchEntry::query()->orderBy('title')->pluck('title')->all());
    }
}

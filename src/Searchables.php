<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search;

use Atrium\Admin\Contracts\Administrable;
use Atrium\Admin\Facades\Hook;
use Atrium\Admin\Modules\Modules;
use Atrium\Admin\Support\ContentLanguages;
use Atrium\Admin\Support\RecordUrls;
use Illuminate\Database\Eloquent\Model;

/**
 * Which models the search covers and what it reads in them.
 *
 * Every model with public pages, unless config('atrium.search') says
 * otherwise. A module declares its rule in composer.json:
 *
 *     "extra": { "atrium": { "search": {
 *         "Acme\\Blog\\Models\\Post": { "title": "name", "content": ["preview_text", "detail_text"] },
 *         "Acme\\Blog\\Models\\Draft": false
 *     } } }
 *
 * and a model that wants full control has a method of its own:
 *
 *     public function toSearchDocument(string $language): ?array
 *     {
 *         return ['title' => …, 'content' => …];   // null: not found in this language
 *     }
 *
 * The list passes through the atrium.search.sources filter last, so a
 * package can add a model or take one out:
 *
 *     Hook::filter('atrium.search.sources', fn (array $models) => [
 *         ...$models, Event::class => ['title' => 'name', 'content' => ['text']],
 *     ]);
 */
final class Searchables
{
    private const CONTENT_FIELDS = ['content', 'textarea', 'wysiwyg'];

    /** @var array<class-string<Model>,array{title:string,content:array<int,string>}>|null */
    private ?array $models = null;

    public function __construct(
        private readonly Modules $modules,
        private readonly RecordUrls $urls,
        private readonly ContentLanguages $languages,
    ) {}

    /**
     * @return array<class-string<Model>,array{title:string,content:array<int,string>}>
     */
    public function all(): array
    {
        if ($this->models !== null) {
            return $this->models;
        }

        $rules = [];

        foreach ((array) config('atrium.search', []) as $class => $rule) {
            $rules[ltrim((string) $class, '\\')] = $rule;
        }

        $classes = [...array_keys((array) config('atrium.public_urls', [])), ...array_keys($rules)];
        $models = [];

        foreach ($classes as $class) {
            $rule = $rules[$class] ?? [];

            if ($rule === false || ! is_subclass_of($class, Model::class) || ! $this->modules->available($class) || ! $this->urls->hasPages($class)) {
                continue;
            }

            $rule = is_array($rule) ? $rule : [];
            $models[$class] = [
                'title' => (string) ($rule['title'] ?? 'name'),
                'content' => array_values(array_map('strval', (array) ($rule['content'] ?? $this->contentFields($class)))),
            ];
        }

        return $this->models = Hook::apply('atrium.search.sources', $models);
    }

    public function has(Model|string $model): bool
    {
        return isset($this->all()[is_string($model) ? ltrim($model, '\\') : $model::class]);
    }

    public function flush(): void
    {
        $this->models = null;
    }

    /**
     * What a record says in each language: language (null: every language)
     * => title and plain text. Languages it is not written in are left out.
     *
     * @return array<string,array{language:?string,title:string,content:string}>
     */
    public function documents(Model $record): array
    {
        $rule = $this->all()[$record::class] ?? null;

        if ($rule === null) {
            return [];
        }

        $translated = $this->translated($record, [$rule['title'], ...$rule['content']]);
        $languages = method_exists($record, 'toSearchDocument') || $translated !== [] ? $this->languages->codes() : [null];
        $documents = [];

        foreach ($languages as $language) {
            $document = method_exists($record, 'toSearchDocument')
                ? $record->toSearchDocument($language ?? $this->languages->default())
                : [
                    'title' => $this->text($record, $rule['title'], $language, $translated),
                    'content' => implode("\n", array_map(fn (string $column): string => $this->text($record, $column, $language, $translated), $rule['content'])),
                ];

            if (! is_array($document)) {
                continue;
            }

            $title = self::plain((string) ($document['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $documents[$language ?? ''] = [
                'language' => $language,
                'title' => $title,
                'content' => self::plain((string) ($document['content'] ?? '')),
            ];
        }

        return $documents;
    }

    /**
     * Text without tags, entities and runs of spaces.
     */
    public static function plain(string $html): string
    {
        $html = preg_replace('#<(script|style|template)\b.*?</\1\s*>#is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', '$0 ', $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The columns the record holds one value per language in.
     *
     * @param  array<int,string>  $columns
     * @return array<int,string>
     */
    private function translated(Model $record, array $columns): array
    {
        if (! method_exists($record, 'isTranslatableAttribute')) {
            return [];
        }

        return array_filter($columns, fn (string $column): bool => $record->isTranslatableAttribute($column));
    }

    /**
     * @param  array<int,string>  $translated  columns read in $language, never null for them
     */
    private function text(Model $record, string $column, ?string $language, array $translated): string
    {
        $value = in_array($column, $translated, true) && method_exists($record, 'getTranslation')
            ? $record->getTranslation($column, $language, false)
            : $record->getAttribute($column);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The text fields of the model's admin form.
     *
     * @param  class-string<Model>  $class
     * @return array<int,string>
     */
    private function contentFields(string $class): array
    {
        if (! is_subclass_of($class, Administrable::class)) {
            return [];
        }

        $columns = [];

        foreach ($class::getAdministrationFields() as $field) {
            if (in_array($field->getType(), self::CONTENT_FIELDS, true)) {
                $columns[] = $field->getColumn();
            }
        }

        return $columns;
    }
}

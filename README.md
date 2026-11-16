# Atrium Search

Site search for [Atrium](https://github.com/AlveBy/atrium): one search across every installed module, for the current site and language, with the words marked in the results. The index is a table of the site's own database, with full-text search on MySQL, MariaDB and PostgreSQL and `LIKE` on SQLite; no outside service.

## Install

```bash
composer require alveby/atrium-search
php artisan atrium:module:install alveby/atrium-search
php artisan atrium:search:rebuild
```

## What you get

- `/search` (route `atrium.search`) and a search box in the header of the public theme (`alveby/atrium-theme`): results grouped by module, a page per module, snippets with `<mark>`.
- A box in the admin header that finds records of every module the editor may see.
- `GET /api/v1/site-search?q=…` for API tokens and the MCP tool `search_site`.
- `php artisan atrium:search:rebuild` to fill the index anew.

Every model with public pages is searched by its name and its text fields; a module or the app says otherwise in `extra.atrium.search` or `config('atrium.search')`.

The guide: [docs/search.md](https://github.com/AlveBy/atrium/blob/main/docs/search.md) ([по-русски](https://github.com/AlveBy/atrium/blob/main/docs/search.ru.md)).

## Hooks

- `atrium.search.sources` (filter): the searched models, `class => ['title' => …, 'content' => [...]]`; add or remove one. See [docs/search.md](https://github.com/AlveBy/atrium/blob/main/docs/search.md#what-is-searched).

## Tests

From the repository root:

```bash
vendor/bin/phpunit packages/search/tests
```

The tests cover indexing and the rebuild, languages, sites, dates of publication, the results page, the admin box and the API, on SQLite, MySQL and PostgreSQL.

## Contributing

This repository is a read-only copy of `packages/search` in [AlveBy/atrium](https://github.com/AlveBy/atrium). Issues and pull requests go there.

## License

MIT

<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Mcp;

use Atrium\Admin\Mcp\ApiGateway;
use Atrium\Admin\Mcp\Tools\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_site')]
#[Description('Search the public site the way a visitor does: every module at once, only active records of this site, with a snippet and the page address of each. Use get_record with the module and id for the full record.')]
#[IsReadOnly]
class SearchSite extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The words to find.')->required(),
            'module' => $schema->string()->description('Only this module, e.g. "news".'),
            'locale' => $schema->string()->description('Language code to search in; the site\'s default language when omitted.'),
            'site' => $schema->string()->description('Site code, when it is another site than this address.'),
            'page' => $schema->integer()->description('Page number, from 1.'),
            'per_page' => $schema->integer()->description('Results per page, 1 to 50. Default: 10.'),
        ];
    }

    public function handle(Request $request, ApiGateway $api): Response
    {
        $query = array_filter([
            'q' => $request->get('query'),
            'module' => $request->get('module'),
            'locale' => $request->get('locale'),
            'page' => $request->get('page'),
            'per_page' => $request->get('per_page'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return $api->call('GET', 'site-search', $query + $this->siteQuery($request));
    }
}

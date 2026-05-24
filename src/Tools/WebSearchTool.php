<?php

namespace Tivins\LlmBasic\Tools;

use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;

class WebSearchTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            new ToolSchema(
                'web_search',
                'Search the web via DuckDuckGo HTML and return real result entries (title, url, snippet). Use fetch_web_page to read the full body of a returned URL.',
                [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query.',
                        ],
                        'max_results' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of results to return (default 8, max 20).',
                            'default' => 8,
                        ],
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ),
            function (string $argumentsJson): string {
            }
        );
    }
}
<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Tools;

use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;
use Tivins\LlmBasic\Workspace;
use Tivins\LlmBasic\WorkspaceException;

class GrepTool extends Tool
{
    private const int DEFAULT_MAX_MATCHES = 500;

    public function __construct(
        private readonly Workspace $workspace,
    ) {
        parent::__construct(
            new ToolSchema(
                'grep',
                'Search for a regex pattern in a workspace file or directory.',
                [
                    'type' => 'object',
                    'properties' => [
                        'pattern' => [
                            'type' => 'string',
                            'description' => 'Regular expression to search for in each line.',
                        ],
                        'path' => [
                            'type' => 'string',
                            'description' => 'File or directory relative to the workspace root. Empty or "." searches from the root.',
                        ],
                        'glob' => [
                            'type' => 'string',
                            'description' => 'When searching a directory, only include files matching this glob (e.g. "*.md").',
                        ],
                        'case_insensitive' => [
                            'type' => 'boolean',
                            'description' => 'When true, match case-insensitively.',
                        ],
                        'max_matches' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of matching lines to return.',
                        ],
                    ],
                    'required' => ['pattern'],
                ],
            ),
            function (string $argumentsJson): string {
                $args = json_decode($argumentsJson, true) ?? [];
                $pattern = isset($args['pattern']) ? (string) $args['pattern'] : '';
                $path = isset($args['path']) ? (string) $args['path'] : '';
                $glob = isset($args['glob']) ? (string) $args['glob'] : null;
                if ($glob === '') {
                    $glob = null;
                }
                $caseInsensitive = (bool) ($args['case_insensitive'] ?? false);
                $maxMatches = isset($args['max_matches']) ? (int) $args['max_matches'] : self::DEFAULT_MAX_MATCHES;

                if ($pattern === '') {
                    return json_encode(['error' => 'pattern is required.'], JSON_UNESCAPED_UNICODE);
                }

                if ($maxMatches < 1) {
                    return json_encode(['error' => 'max_matches must be at least 1.'], JSON_UNESCAPED_UNICODE);
                }

                try {
                    return json_encode(
                        $this->workspace->grep($pattern, $path, $caseInsensitive, $glob, $maxMatches),
                        JSON_UNESCAPED_UNICODE,
                    );
                } catch (WorkspaceException $e) {
                    return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }
            },
        );
    }
}

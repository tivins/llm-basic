<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Tools;

use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;
use Tivins\LlmBasic\Workspace;
use Tivins\LlmBasic\WorkspaceException;

class ListDirTool extends Tool
{
    private const int DEFAULT_MAX_ENTRIES = 500;

    public function __construct(
        private readonly Workspace $workspace,
    ) {
        parent::__construct(
            new ToolSchema(
                'list_dir',
                'List files and directories in a workspace path.',
                [
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => 'Directory relative to the workspace root. Empty or "." lists the root.',
                        ],
                        'recursive' => [
                            'type' => 'boolean',
                            'description' => 'When true, include entries from subdirectories.',
                        ],
                        'max_entries' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of entries to return.',
                        ],
                    ],
                ],
            ),
            function (string $argumentsJson): string {
                $args = json_decode($argumentsJson, true) ?? [];
                $path = isset($args['path']) ? (string) $args['path'] : '';
                $recursive = (bool) ($args['recursive'] ?? false);
                $maxEntries = isset($args['max_entries']) ? (int) $args['max_entries'] : self::DEFAULT_MAX_ENTRIES;

                if ($maxEntries < 1) {
                    return json_encode(['error' => 'max_entries must be at least 1.'], JSON_UNESCAPED_UNICODE);
                }

                try {
                    return json_encode(
                        $this->workspace->listDir($path, $recursive, $maxEntries),
                        JSON_UNESCAPED_UNICODE,
                    );
                } catch (WorkspaceException $e) {
                    return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }
            },
        );
    }
}

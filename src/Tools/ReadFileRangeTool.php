<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Tools;

use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;
use Tivins\LlmBasic\Workspace;
use Tivins\LlmBasic\WorkspaceException;

class ReadFileRangeTool extends Tool
{
    private const int DEFAULT_LIMIT = 200;

    public function __construct(
        private readonly Workspace $workspace,
    ) {
        parent::__construct(
            new ToolSchema(
                'read_file_range',
                'Read a line range from a file in the workspace. Omit offset to read the last lines (tail mode).',
                [
                    'type' => 'object',
                    'properties' => [
                        'file' => [
                            'type' => 'string',
                            'description' => 'Path relative to the workspace root.',
                        ],
                        'offset' => [
                            'type' => 'integer',
                            'description' => '1-based starting line number. When omitted, returns the last `limit` lines (tail mode).',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of lines to return. Defaults to 200.',
                        ],
                    ],
                    'required' => ['file'],
                ],
            ),
            function (string $argumentsJson): string {
                $args = json_decode($argumentsJson, true) ?? [];
                $file = isset($args['file']) ? (string) $args['file'] : '';
                $offset = array_key_exists('offset', $args) ? (int) $args['offset'] : null;
                $limit = isset($args['limit']) ? (int) $args['limit'] : self::DEFAULT_LIMIT;

                if ($file === '') {
                    return json_encode(['error' => 'File path is required.'], JSON_UNESCAPED_UNICODE);
                }

                try {
                    return json_encode(
                        $this->workspace->readRange($file, $offset, $limit),
                        JSON_UNESCAPED_UNICODE,
                    );
                } catch (WorkspaceException $e) {
                    return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }
            },
        );
    }
}

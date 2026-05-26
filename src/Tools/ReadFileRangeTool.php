<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Tools;

use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;
use Tivins\LlmBasic\Workspace;
use Tivins\LlmBasic\WorkspaceException;

class ReadFileRangeTool extends Tool
{
    private const int DEFAULT_LIMIT = 60;
    private const int MAX_LIMIT = 200;
    private const array KNOWN_KEYS = ['file', 'offset', 'limit'];

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
                            'description' => 'Maximum number of lines to return. Defaults to 60.',
                        ],
                    ],
                    'required' => ['file'],
                ],
            ),
            function (string $argumentsJson): string {
                $args = json_decode($argumentsJson, true) ?? [];

                $unknownKeys = array_diff(array_keys($args), self::KNOWN_KEYS);
                if ($unknownKeys !== []) {
                    $bad = implode('", "', $unknownKeys);
                    return json_encode([
                        'error' => "Unknown parameter key(s): \"{$bad}\". Use only separate keys: " . implode(', ', self::KNOWN_KEYS) . '.',
                    ], JSON_UNESCAPED_UNICODE);
                }

                $file = isset($args['file']) ? (string) $args['file'] : '';
                $offset = array_key_exists('offset', $args) ? (int) $args['offset'] : null;
                $limit = min(isset($args['limit']) ? (int) $args['limit'] : self::DEFAULT_LIMIT, self::MAX_LIMIT);

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

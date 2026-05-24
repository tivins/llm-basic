<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Tools;

use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;
use Tivins\LlmBasic\Workspace;
use Tivins\LlmBasic\WorkspaceException;

class ReadFileTool extends Tool
{
    public function __construct(
        private readonly Workspace $workspace,
    ) {
        parent::__construct(
            new ToolSchema(
                'read_file',
                'Read a file from the workspace.',
                [
                    'type' => 'object',
                    'properties' => [
                        'file' => [
                            'type' => 'string',
                            'description' => 'Path relative to the workspace root.',
                        ],
                    ],
                    'required' => ['file'],
                ],
            ),
            function (string $argumentsJson): string {
                $args = json_decode($argumentsJson, true) ?? [];
                $file = $args['file'] ?? '';

                try {
                    return json_encode([
                        'file' => $file,
                        'content' => $this->workspace->read($file),
                    ], JSON_UNESCAPED_UNICODE);
                } catch (WorkspaceException $e) {
                    return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }
            },
        );
    }
}

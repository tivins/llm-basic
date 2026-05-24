<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Tools;

use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;
use Tivins\LlmBasic\Workspace;
use Tivins\LlmBasic\WorkspaceException;

class WriteFileTool extends Tool
{
    public function __construct(
        private readonly Workspace $workspace,
    ) {
        parent::__construct(
            new ToolSchema(
                'write_file',
                'Write a text file in the workspace (UTF-8). Replaces the full file contents.',
                [
                    'type' => 'object',
                    'properties' => [
                        'file' => [
                            'type' => 'string',
                            'description' => 'Path relative to the workspace root.',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Full UTF-8 file contents.',
                        ],
                        'create_if_missing' => [
                            'type' => 'boolean',
                            'description' => 'When false, fail if the file does not exist.',
                        ],
                        'overwrite' => [
                            'type' => 'boolean',
                            'description' => 'When false, fail if the file already exists.',
                        ],
                    ],
                    'required' => ['file', 'content'],
                ],
            ),
            function (string $argumentsJson): string {
                $args = json_decode($argumentsJson, true) ?? [];
                $file = isset($args['file']) ? (string) $args['file'] : '';
                $content = isset($args['content']) ? (string) $args['content'] : '';
                $createIfMissing = (bool) ($args['create_if_missing'] ?? true);
                $overwrite = (bool) ($args['overwrite'] ?? true);

                if ($file === '') {
                    return json_encode(['error' => 'File path is required.'], JSON_UNESCAPED_UNICODE);
                }

                try {
                    return json_encode(
                        $this->workspace->write($file, $content, $createIfMissing, $overwrite),
                        JSON_UNESCAPED_UNICODE,
                    );
                } catch (WorkspaceException $e) {
                    return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }
            },
        );
    }
}

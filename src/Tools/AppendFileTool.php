<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Tools;

use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;
use Tivins\LlmBasic\Workspace;
use Tivins\LlmBasic\WorkspaceException;

class AppendFileTool extends Tool
{
    public function __construct(
        private readonly Workspace $workspace,
    ) {
        parent::__construct(
            new ToolSchema(
                'append_file',
                'Append UTF-8 text to the end of a file in the workspace. Use this to extend an existing file without rewriting it.',
                [
                    'type' => 'object',
                    'properties' => [
                        'file' => [
                            'type' => 'string',
                            'description' => 'Path relative to the workspace root.',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'UTF-8 text to append at the end of the file.',
                        ],
                        'create_if_missing' => [
                            'type' => 'boolean',
                            'description' => 'When false, fail if the file does not exist.',
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

                if ($file === '') {
                    return json_encode(['error' => 'File path is required.'], JSON_UNESCAPED_UNICODE);
                }

                try {
                    return json_encode(
                        $this->workspace->append($file, $content, $createIfMissing),
                        JSON_UNESCAPED_UNICODE,
                    );
                } catch (WorkspaceException $e) {
                    return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }
            },
        );
    }
}

<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Tools;

use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;
use Tivins\LlmBasic\Workspace;
use Tivins\LlmBasic\WorkspaceException;

class ApplyPatchTool extends Tool
{
    public function __construct(
        private readonly Workspace $workspace,
    ) {
        parent::__construct(
            new ToolSchema(
                'apply_patch',
                'Apply a search-and-replace patch to a text file in the workspace.',
                [
                    'type' => 'object',
                    'properties' => [
                        'file' => [
                            'type' => 'string',
                            'description' => 'Path relative to the workspace root.',
                        ],
                        'old_string' => [
                            'type' => 'string',
                            'description' => 'Exact text to replace. Must be unique unless replace_all is true.',
                        ],
                        'new_string' => [
                            'type' => 'string',
                            'description' => 'Replacement text.',
                        ],
                        'replace_all' => [
                            'type' => 'boolean',
                            'description' => 'When true, replace every occurrence of old_string.',
                        ],
                        'create_if_missing' => [
                            'type' => 'boolean',
                            'description' => 'When old_string is empty, allow creating a new file with new_string.',
                        ],
                    ],
                    'required' => ['file', 'old_string', 'new_string'],
                ],
            ),
            function (string $argumentsJson): string {
                $args = json_decode($argumentsJson, true) ?? [];
                $file = isset($args['file']) ? (string) $args['file'] : '';
                $oldString = isset($args['old_string']) ? (string) $args['old_string'] : '';
                $newString = isset($args['new_string']) ? (string) $args['new_string'] : '';
                $replaceAll = (bool) ($args['replace_all'] ?? false);
                $createIfMissing = (bool) ($args['create_if_missing'] ?? false);

                if ($file === '') {
                    return json_encode(['error' => 'File path is required.'], JSON_UNESCAPED_UNICODE);
                }

                try {
                    return json_encode(
                        $this->workspace->applySearchReplace(
                            $file,
                            $oldString,
                            $newString,
                            $replaceAll,
                            $createIfMissing,
                        ),
                        JSON_UNESCAPED_UNICODE,
                    );
                } catch (WorkspaceException $e) {
                    return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }
            },
        );
    }
}

<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Tools;

use Tivins\LlmBasic\FileLinter;
use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;
use Tivins\LlmBasic\Workspace;
use Tivins\LlmBasic\WorkspaceException;

class LintFileTool extends Tool
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly FileLinter $linter = new FileLinter(),
    ) {
        $supported = implode(', ', $this->linter->supportedLanguages());

        parent::__construct(
            new ToolSchema(
                'lint_file',
                'Check syntax of a source file (parse errors only, not types or style).',
                [
                    'type' => 'object',
                    'properties' => [
                        'file' => [
                            'type' => 'string',
                            'description' => 'Path relative to the workspace root.',
                        ],
                        'language' => [
                            'type' => 'string',
                            'description' => 'Language id (e.g. php). Inferred from file extension when omitted. Supported: '
                                . $supported . '.',
                        ],
                    ],
                    'required' => ['file'],
                ],
            ),
            function (string $argumentsJson): string {
                $args = json_decode($argumentsJson, true) ?? [];
                $file = isset($args['file']) ? (string) $args['file'] : '';
                $language = isset($args['language']) ? (string) $args['language'] : '';

                if ($file === '') {
                    return json_encode(['error' => 'File path is required.'], JSON_UNESCAPED_UNICODE);
                }

                try {
                    return json_encode(
                        $this->workspace->lintFile($file, $language),
                        JSON_UNESCAPED_UNICODE,
                    );
                } catch (WorkspaceException $e) {
                    return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }
            },
        );
    }
}

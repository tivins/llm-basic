<?php

declare(strict_types=1);

use Tivins\LlmBasic\Agent;
use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\LLM;
use Tivins\LlmBasic\Logger;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\Role;
use Tivins\LlmBasic\Skills\WriterSkill;
use Tivins\LlmBasic\ToolRegistry;
use Tivins\LlmBasic\Tools\AppendFileTool;
use Tivins\LlmBasic\Tools\ReadFileRangeTool;
use Tivins\LlmBasic\Tools\ReadFileTool;
use Tivins\LlmBasic\Tools\WriteFileTool;
use Tivins\LlmBasic\Workspace;

require __DIR__ . '/../vendor/autoload.php';

$topic = $argv[1] ?? 'LLM inference';
$continuations = (int) ($argv[2] ?? 3);
if ($topic === '') {
    echo "Usage: php 04_writer.php <topic> [continuations]\n";
    exit(1);
}
if ($continuations < 0) {
    fwrite(STDERR, "continuations must be >= 0\n");
    exit(1);
}

/**
 * @param array<string, mixed> $params
 */
function runStep(
    WriterSkill $skill,
    LLM $llm,
    ToolRegistry $tools,
    Workspace $workspace,
    float $temperature,
    array $params,
    string $label,
    int $maxToolRounds,
): void {
    echo "--- {$label} ---\n";

    $logger = new Logger($workspace->root() . '/logs/writer-' . date('Y-m-d-H-i-s') . '-' . $label . '.json');
    $agent = new Agent($llm, $tools, maxToolRounds: $maxToolRounds, workspace: $workspace);
    $options = new ChatCompletionOptions(temperature: $temperature);
    $conversation = new Conversation(logger: $logger);
    $conversation->addMessage(new Message(Role::System, $skill->prompt));
    $conversation->addMessage(new Message(Role::User, $skill->formatQuery($params)));

    $result = $agent->runTurn($conversation, $options);
    if (!$result->success) {
        throw new RuntimeException($result->error ?? 'Agent turn failed.');
    }

    echo ($result->message?->content ?? '') . "\n";
}

try {
    date_default_timezone_set('Europe/Paris');

    $workspaceDir = __DIR__ . '/tmp/writer';
    if (!is_dir($workspaceDir)) {
        mkdir($workspaceDir, 0755, true);
    }

    $workspace = new Workspace($workspaceDir);
    $tools = new ToolRegistry(
        new ReadFileTool($workspace),
        new ReadFileRangeTool($workspace),
        new WriteFileTool($workspace),
        new AppendFileTool($workspace),
    );

    $llm = new LLM('http://127.0.0.1:8080', timeoutSeconds: 600);
    $skill = new WriterSkill();

    $planFile = 'inference_plan.md';
    $articleFile = 'inference_article.md';
    $baseParams = [
        'plan_file' => $planFile,
        'article_file' => $articleFile,
    ];

    runStep($skill, $llm, $tools, $workspace, $skill->temperature, ['step' => 'plan', 'topic' => $topic] + $baseParams, 'plan', maxToolRounds: 2);
    runStep($skill, $llm, $tools, $workspace, 0.5, ['step' => 'start'] + $baseParams, 'start', maxToolRounds: 6);

    for ($i = 0; $i < $continuations; $i++) {
        runStep(
            $skill,
            $llm,
            $tools,
            $workspace,
            0.5,
            ['step' => 'continue', 'iteration' => $i + 1] + $baseParams,
            'continue-' . ($i + 1),
            maxToolRounds: 8,
        );
    }

    echo "Output directory: {$workspaceDir}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
exit(0);

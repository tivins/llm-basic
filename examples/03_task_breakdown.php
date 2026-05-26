<?php

declare(strict_types=1);


use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\LLM;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\Role;
use Tivins\LlmBasic\Logger;
use Tivins\LlmBasic\Skills\TaskBreakdownSkill;

require __DIR__ . '/../vendor/autoload.php';

$context = 'Existing PHP codebase, no tests yet, JWT preferred';
$granularity = 'medium';
$maxSteps = 8;
$task = $argv[1] ?? 'Create API endpoints for user authentication and authorization';
if ($task === '') {
    echo "Usage: php 03_task_breakdown.php <task>\n";
    exit(1);
}
try {
    date_default_timezone_set('Europe/Paris');
    $logger = new Logger(__dir__ . '/../var/logs/chat-' . date('Y-m-d-H-i-s-Z') . '.json');
    $llm = new LLM("http://127.0.0.1:8080", timeoutSeconds: 600);
    $taskBreakdownSkill = new TaskBreakdownSkill();
    $options = new ChatCompletionOptions(temperature: $taskBreakdownSkill->temperature);
    $conversation = new Conversation(logger: $logger);
    $conversation->addMessage(new Message(Role::System, $taskBreakdownSkill->prompt));
    $conversation->addMessage(new Message(Role::User, $taskBreakdownSkill->formatQuery([
        'task' => $task,
        'context' => $context,
        'granularity' => $granularity,
        'max_steps' => $maxSteps,
    ])));
    $response = $llm->chatCompletion($conversation, $options);
    $stored = $response->toStoredMessage($options, $response->duration ?? 0.0);
    if ($stored !== null) {
        $conversation->addMessage($stored);
    }
    echo $response->firstChoice()->message->content . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
exit(0);

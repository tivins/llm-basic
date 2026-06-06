<?php

declare(strict_types=1);

use Tivins\LlmBasic\Agent;
use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\ConversationalSession;
use Tivins\LlmBasic\FileMessageStore;
use Tivins\LlmBasic\LLM;
use Tivins\LlmBasic\MemoryCompactor;
use Tivins\LlmBasic\ToolRegistry;

require __DIR__ . '/../vendor/autoload.php';

$systemPrompt = <<<TXT
You help the user describe themselves for a personal profile (work, interests, goals).
Ask at most one clarifying question per turn when something important is unclear.
Do not drill into every new topic immediately; note ambiguous points and move on when appropriate.
Use any long-term memory section in this system message when you already know facts about the user.
Reply in the same language as the user.
TXT;

try {
    $sessionDir = $argv[1] ?? (__DIR__ . '/tmp/profile_' . date('YmdHis'));
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0755, true);
    }

    $conversationId = $argv[2] ?? null;
    $store = new FileMessageStore($sessionDir, $conversationId !== '' ? $conversationId : null);
    $compactor = MemoryCompactor::fromEnv($store);
    $session = new ConversationalSession($store, $compactor, $systemPrompt);

    $llm = new LLM('http://127.0.0.1:8080', timeoutSeconds: 600);
    $agent = new Agent($llm, new ToolRegistry());
    $options = new ChatCompletionOptions(temperature: 0.6);

    echo "Profile conversation (session: {$store->dataDir()})\n";
    echo "Type a message and press Enter. Empty line or Ctrl-D to exit.\n\n";

    while (($line = readline('You> ')) !== false) {
        $line = trim($line);
        if ($line === '') {
            break;
        }

        $result = $session->runUserTurn($line, $agent, $options);
        if (!$result->success) {
            throw new RuntimeException($result->error ?? 'Agent turn failed.');
        }

        echo "\nAssistant> " . ($result->message?->content ?? '') . "\n\n";

        $progress = $session->contextProgress();
        echo sprintf(
            "[context %d%% · %d messages · compact %s]\n\n",
            $progress['percent'],
            $progress['message_count'],
            $progress['ready_to_compact'] ? 'ready' : 'ok',
        );
    }

    echo "Session data: {$store->dataDir()}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
exit(0);

<?php

declare(strict_types=1);


use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\LLM;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\Role;

require __DIR__ . '/../vendor/autoload.php';

try {
    $prompt = $argv[1] ?? "What is the capital of France?";
    if ($prompt === '') {
        echo "Usage: php 01_minimal.php <prompt>\n";
        exit(1);
    }
    $llm = new LLM("http://127.0.0.1:8080", timeoutSeconds: 600);
    $options = new ChatCompletionOptions();
    $conversation = new Conversation();
    $conversation->addMessage(new Message(Role::System, "You are a helpful assistant."));
    $conversation->addMessage(new Message(Role::User, $prompt));
    $response = $llm->chatCompletion($conversation, $options);
    echo $response->firstChoice()->message->content ."\n";
}
catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
exit(0);
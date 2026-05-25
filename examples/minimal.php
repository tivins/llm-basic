<?php

declare(strict_types=1);


use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\LLM;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\Role;

require __DIR__ . '/../vendor/autoload.php';

try {
    $llm = new Llm("http://127.0.0.1:8080", timeoutSeconds: 600);
    $options = new ChatCompletionOptions();
    $conversation = new Conversation();
    $conversation->addMessage(new Message(Role::System, "You are a helpful assistant."));
    $conversation->addMessage(new Message(Role::User, "What is the capital of France?"));
    $response = $llm->chatCompletion($conversation, $options);
    echo $response->firstChoice()->message->content ."\n";
}
catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
exit(0);
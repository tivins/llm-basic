<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\LLM;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\Role;

$llm = new LLM('http://127.0.0.1:8080');
$conversation = new Conversation([
    new Message(Role::System, 'You are a helpful assistant.'),
    new Message(Role::User, 'What is the capital of France?'),
]);
$options = new ChatCompletionOptions(n: 1);
$response = $llm->chatCompletion($conversation, $options);

if ($response->finishReason() === 'stop') {
    $assistant = $response->assistantMessage();
    $conversation->addMessage(new Message(
        Role::Assistant,
        $assistant->content,
        $assistant->reasoningContent,
    ));
    $conversation->addMessage(new Message(Role::User, 'About which country is this capital?'));
    $response = $llm->chatCompletion($conversation, $options);
    if ($response->finishReason() === 'stop') {
        $assistant = $response->assistantMessage();
        $conversation->addMessage(new Message(
            Role::Assistant,
            $assistant->content,
            $assistant->reasoningContent,
        ));
        $response = $llm->chatCompletion($conversation, $options);
    }
}

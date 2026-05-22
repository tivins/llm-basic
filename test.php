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
    Message::withCreatedAt(Role::System, 'You are a helpful assistant.'),
    Message::withCreatedAt(Role::User, 'What is the capital of France?'),
]);
$options = new ChatCompletionOptions(n: 1);

$response = $llm->chatCompletion($conversation, $options);

if ($response->finishReason() === 'stop') {
    $stored = $response->toStoredMessage($options, $response->duration);
    if ($stored !== null) {
        $conversation->addMessage($stored);
    }
    $conversation->addMessage(Message::withCreatedAt(Role::User, 'About which country is this capital?'));

    $response = $llm->chatCompletion($conversation, $options);

    if ($response->finishReason() === 'stop') {
        $stored = $response->toStoredMessage($options, $response->duration);
        if ($stored !== null) {
            $conversation->addMessage($stored);
        }
        $response = $llm->chatCompletion($conversation, $options);
    }
}
echo json_encode($conversation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

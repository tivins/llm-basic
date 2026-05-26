<?php

declare(strict_types=1);


use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\LLM;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\Role;
use Tivins\LlmBasic\Logger;
use Tivins\LlmBasic\Skills\TranslatorSkill;

require __DIR__ . '/../vendor/autoload.php';

$source_language = 'English';
$target_language = 'Japanese (rōmaji)';
$prompt = $argv[1] ?? "What is the capital of **Japan**?";
if ($prompt === '') {
    echo "Usage: php 02_translator.php <prompt>\n";
    exit(1);
}
try {
    date_default_timezone_set('Europe/Paris');
    $logger = new Logger(__dir__ . '/../var/logs/chat-' . date('Y-m-d-H-i-s-Z') . '.json');
    $llm = new LLM("http://127.0.0.1:8080", timeoutSeconds: 600);
    $translatorSkill = new TranslatorSkill();
    $options = new ChatCompletionOptions(temperature: $translatorSkill->temperature);
    $conversation = new Conversation(logger: $logger);
    $conversation->addMessage(new Message(Role::System, $translatorSkill->prompt));
    $conversation->addMessage(new Message(Role::User, $translatorSkill->formatQuery(['from' => $source_language, 'target' => $target_language, 'query' => $prompt])));
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
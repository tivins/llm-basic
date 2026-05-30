<?php

declare(strict_types=1);


use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\LLM;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\Role;
use Tivins\LlmBasic\TTS;

require __DIR__ . '/../vendor/autoload.php';

try {
    $prompt = $argv[1] ?? "Décris un petit chat qui joue sur un tapis, au soleil, en 10 phrases. Pas de markdown, juste du texte brut";
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
    $response_content = $response->firstChoice()->message->content;
    echo $response_content ."\n";
    $tts = new TTS("/data/projects/tts/.venv/bin/python /data/projects/tts/tts.py", forceCPU: true);
    $tts->toAudio($response_content, "fr", output_file:  __dir__ . "/out_1.wav");
}
catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
exit(0);
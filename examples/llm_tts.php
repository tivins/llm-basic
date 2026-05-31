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
    $prompt = $argv[1] ?? "Describe a small cat playing on a carpet, in the sun, in 10 sentences. No markdown, just plain text.";
    if ($prompt === '') {
        echo "Usage: php llm_tts.php <prompt>\n";
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
    $tts = new TTS("/data/projects/tts/.venv/bin/python /data/projects/tts/tts.py", forceCPU: true, maxChars: 250);
    $tts->toAudio($response_content, "en", output_file:  __dir__ . "/out_1.wav");
}
catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
exit(0);
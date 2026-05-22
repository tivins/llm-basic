<?php

namespace Tivins\LlmBasic;

class Conversation
{
    public function __construct(
        public array $messages,
    ) {}

    public function addMessage(Message $message): void
    {
        $this->messages[] = $message;
    }

    public function toChatCompletionArray(): array
    {
        return array_map(function (Message $message) {
            $payload = [
                'role' => $message->role->value,
                'content' => $message->content,
            ];
            if ($message->reasoningContent !== null) {
                $payload['reasoning_content'] = $message->reasoningContent;
            }

            return $payload;
        }, $this->messages);
    }
}

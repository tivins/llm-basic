<?php

namespace Tivins\LlmBasic;

class Conversation {
    public function __construct(
        public array $messages,
    ) {}
    public function addMessage(Message $message): void
    {
        $this->messages[] = $message;
    }
    public function toChatCompletionArray(): array
    {
        return array_map(fn (Message $message) => [
            'role' => $message->role->value,
            'content' => $message->content,
        ], $this->messages);
    }
}
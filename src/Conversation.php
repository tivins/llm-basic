<?php

namespace Tivins\LlmBasic;

class Conversation implements \JsonSerializable
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
        return array_map(
            fn (Message $message) => $message->toChatCompletionArray(),
            $this->messages,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'messages' => array_map(
                fn (Message $message) => $message->toArray(),
                $this->messages,
            ),
        ];
    }
}

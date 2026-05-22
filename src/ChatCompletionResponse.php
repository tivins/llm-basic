<?php

namespace Tivins\LlmBasic;

class ChatCompletionResponse
{
    /**
     * @param Choice[] $choices
     */
    public function __construct(
        public string $model,
        public Usage $usage,
        public array $choices,
        private ?array $raw = null,
    ) {}

    public function raw(): ?array
    {
        return $this->raw;
    }

    public function firstChoice(): ?Choice
    {
        return $this->choices[0] ?? null;
    }

    public function finishReason(): ?string
    {
        return $this->firstChoice()?->finishReason;
    }

    public function assistantMessage(): ?Message
    {
        $message = $this->firstChoice()?->message;
        if ($message?->role === Role::Assistant) {
            return $message;
        }

        return null;
    }
}

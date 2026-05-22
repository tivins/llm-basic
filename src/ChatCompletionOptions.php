<?php

namespace Tivins\LlmBasic;


class ChatCompletionOptions {
    public function __construct(
        public ?float $temperature = null,
        public ?float $topP = null,
        public int $n = 1,
        public array $tools = [],
        public string $toolChoice = 'auto',
        public string $responseFormat = 'json_object',
    ) {}
}
<?php

namespace Tivins\LlmBasic;

class ChatCompletionOptions
{
    public function __construct(
        public ?string $model = null,
        public float $temperature = 0.7,
        public float $topP = 1.0,
        public int $n = 1,
        public ?array $tools = null,
        public ?string $toolChoice = null,
        public ?string $responseFormat = null,
    ) {}

    public function toRequestArray(?string $defaultModel): array
    {
        $body = [
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'n' => $this->n,
        ];

        $model = $this->model ?? $defaultModel;
        if ($model !== null) {
            $body['model'] = $model;
        }

        if ($this->tools !== null && $this->tools !== []) {
            $body['tools'] = $this->tools;
            if ($this->toolChoice !== null) {
                $body['tool_choice'] = $this->toolChoice;
            }
        }

        if ($this->responseFormat !== null) {
            $body['response_format'] = ['type' => $this->responseFormat];
        }

        return $body;
    }
}

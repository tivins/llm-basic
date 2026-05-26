<?php
declare(strict_types=1);

namespace Tivins\LlmBasic;

use Exception;

class LLM
{
    public function __construct(
        public string $endpoint,
        public ?string $apiKey = null,
        public ?string $defaultModel = null,
        public int $timeoutSeconds = 120,
    ) {}

    /**
     * @throws Exception
     */
    public function chatCompletion(Conversation $conversation, ChatCompletionOptions $options): ChatCompletionResponse
    {
        $start = hrtime(true);
        $url = $this->endpoint . '/v1/chat/completions';
        $headers = ['Content-Type: application/json'];
        if ($this->apiKey !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $body = json_encode(array_merge(
            ['messages' => $conversation->toChatCompletionArray()],
            $options->toRequestArray($this->defaultModel),
        ));

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeoutSeconds));
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        $response = curl_exec($curl);
        if ($response === false) {
            throw new Exception(curl_error($curl));
        }
        curl_close($curl);

        $data = json_decode($response, true);
        $usage = new Usage(
            $data['usage']['prompt_tokens'] ?? 0,
            $data['usage']['completion_tokens'] ?? 0,
            $data['usage']['total_tokens'] ?? 0,
        );

        $choices = [];
        foreach ($data['choices'] as $choice) {
            $toolCalls = null;
            if (isset($choice['message']['tool_calls'])) {
                $toolCalls = array_map(
                    ToolCall::fromArray(...),
                    $choice['message']['tool_calls'],
                );
            }
            $choices[] = new Choice(
                $choice['index'],
                new Message(
                    Role::tryFrom($choice['message']['role']) ?? Role::Unknown,
                    $choice['message']['content'] ?? '',
                    $choice['message']['reasoning_content'] ?? null,
                    toolCalls: $toolCalls,
                    toolCallId: $choice['message']['tool_call_id'] ?? null,
                ),
                $choice['finish_reason'],
            );
        }
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        return new ChatCompletionResponse(
            $data['model'],
            $usage,
            $choices,
            $data,
            $elapsedMs,
        );
    }

    // Get models list : GET /v1/models
    // public function listModels(): array

    // Load model : POST /models/load
    // public function loadModel(string $modelName): void

    // Unload model : POST /models/unload
    // public function unloadModel(string $modelName): void

    
}

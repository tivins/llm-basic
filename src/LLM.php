<?php

namespace Tivins\LlmBasic;

class LLM
{
    public function __construct(
        public string $endpoint,
        public ?string $apiKey = null,
        public ?string $defaultModel = null,
    ) {}

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
        $response = curl_exec($curl);
        curl_close($curl);

        $data = json_decode($response, true);
        $usage = new Usage(
            $data['usage']['prompt_tokens'],
            $data['usage']['completion_tokens'],
            $data['usage']['total_tokens'],
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
}

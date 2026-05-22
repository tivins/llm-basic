<?php

namespace Tivins\LlmBasic;


class LLM
{
    public function __construct(
        public string  $endpoint,
        public ?string $apiKey = null,
    )
    {
    }

    public function chatCompletion(Conversation $conversation, ChatCompletionOptions $options): Output
    {
        $url = $this->endpoint . '/v1/chat/completions';
        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        $body = json_encode([
            'messages' => $conversation->toChatCompletionArray(),
            'temperature' => $options->temperature ?? 0.7,
            'top_p' => $options->topP ?? 1,
            'n' => $options->n ?? 1,
            'tools' => $options->tools,
            'tool_choice' => $options->toolChoice,
            'response_format' => $options->responseFormat,
        ]);

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
            $choices[] = new Choice(
                $choice['index'],
                new Message(
                    Role::tryFrom($choice['message']['role']) ?? 'User',
                    $choice['message']['content'],
                    $choice['message']['reasoning_content'] ?? null),
                $choice['finish_reason'],
            );
        }

        return new Output($data, $usage, $choices, $data['model']);
    }
}
<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\LLM;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\Role;
use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolRegistry;

$getCityPopulation = new Tool(
    'get_city_population',
    'Get the population of a city.',
    [
        'type' => 'object',
        'properties' => [
            'city' => [
                'type' => 'string',
                'description' => 'City name, e.g. Paris',
            ],
        ],
        'required' => ['city'],
    ],
);

$tools = new ToolRegistry(Tool::getWeather());
$tools->register($getCityPopulation, function (string $argumentsJson): string {
    $args = json_decode($argumentsJson, true) ?? [];
    $city = $args['city'] ?? 'unknown';

    return json_encode([
        'city' => $city,
        'population' => match (strtolower($city)) {
            'paris' => 2_161_000,
            'lyon' => 516_000,
            default => 100_000,
        },
        'source' => 'fake',
    ], JSON_UNESCAPED_UNICODE);
});

$llm = new LLM('http://127.0.0.1:8080');
$conversation = new Conversation([
    Message::withCreatedAt(Role::System, 'You are a helpful assistant. Use tools when needed.'),
    Message::withCreatedAt(Role::User, 'What is the population of Paris?'),
]);
$options = new ChatCompletionOptions(n: 1, tools: $tools);

$response = $llm->chatCompletion($conversation, $options);

if ($response->finishReason() === 'stop') {
    $stored = $response->toStoredMessage($options, $response->duration);
    if ($stored !== null) {
        $conversation->addMessage($stored);
    }
} elseif ($response->hasToolCalls()) {
    $assistant = $response->assistantMessage();
    if ($assistant !== null) {
        $conversation->addMessage($response->toStoredMessage($options, $response->duration) ?? $assistant);
        foreach ($tools->executeAll($assistant->toolCalls ?? []) as $toolMessage) {
            $conversation->addMessage($toolMessage);
        }
        $response = $llm->chatCompletion($conversation, $options);
        if ($response->finishReason() === 'stop') {
            $stored = $response->toStoredMessage($options, $response->duration);
            if ($stored !== null) {
                $conversation->addMessage($stored);
            }
        }
    }
}
echo json_encode($conversation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

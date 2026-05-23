<?php

namespace Tivins\LlmBasic;

class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function __construct(Tool ...$tools)
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(Tool $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    /** @return Tool[] */
    public function all(): array
    {
        return array_values($this->tools);
    }

    public function toRequestArray(): array
    {
        return array_map(fn (Tool $tool) => $tool->toArray(), $this->all());
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function execute(ToolCall $call): Message
    {
        $content = match ($call->name) {
            'get_weather' => $this->fakeGetWeather($call->arguments),
            default => json_encode(['error' => "Unknown tool: {$call->name}"]),
        };

        return new Message(Role::Tool, $content, toolCallId: $call->id);
    }

    /**
     * @param ToolCall[] $calls
     * @return Message[]
     */
    public function executeAll(array $calls): array
    {
        return array_map(fn (ToolCall $call) => $this->execute($call), $calls);
    }

    private function fakeGetWeather(string $argumentsJson): string
    {
        $args = json_decode($argumentsJson, true) ?? [];
        $location = $args['location'] ?? 'unknown';

        return json_encode([
            'location' => $location,
            'temperature' => 22,
            'unit' => 'celsius',
            'condition' => 'sunny',
        ], JSON_UNESCAPED_UNICODE);
    }
}

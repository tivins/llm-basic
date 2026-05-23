<?php

namespace Tivins\LlmBasic;

class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /** @var array<string, callable(string): string> */
    private array $handlers = [];

    public function __construct(Tool ...$tools)
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    /**
     * @param callable(string): string|null $handler Receives JSON arguments, returns JSON content.
     */
    public function register(Tool $tool, ?callable $handler = null): void
    {
        $this->tools[$tool->name] = $tool;
        if ($handler !== null) {
            $this->handlers[$tool->name] = $handler;
        } elseif ($tool->name === 'get_weather' && !isset($this->handlers[$tool->name])) {
            $this->handlers[$tool->name] = fn (string $argumentsJson) => $this->fakeGetWeather($argumentsJson);
        }
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
        $handler = $this->handlers[$call->name] ?? null;
        $content = $handler !== null
            ? $handler($call->arguments)
            : json_encode(['error' => "No handler for tool: {$call->name}"]);

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

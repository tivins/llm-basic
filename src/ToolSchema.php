<?php

namespace Tivins\LlmBasic;

class ToolSchema
{
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->parameters,
            ],
        ];
    }

    public static function getWeather(): self
    {
        return new self(
            'get_weather',
            'Get the current weather in a given location.',
            [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'City name, e.g. Paris',
                    ],
                ],
                'required' => ['location'],
            ],
        );
    }
}

<?php

namespace Tivins\LlmBasic;

use Exception;

abstract readonly class Skill
{
    public function __construct(
        public string $name,
        public string $prompt,
        public float $temperature,
    )
    {
    }

    public function __toString(): string
    {
        return $this->prompt;
    }

    /**
     * @throws Exception
     */
    abstract public function formatQuery(array $parameters): string;
}
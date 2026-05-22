<?php

namespace Tivins\LlmBasic;

class Message implements \JsonSerializable
{
    public function __construct(
        public Role $role,
        public string $content,
        public ?string $reasoningContent = null,
        public array $meta = [],
    ) {}

    public static function withCreatedAt(
        Role $role,
        string $content,
        ?string $reasoningContent = null,
        ?\DateTimeImmutable $at = null,
    ): self {
        $at ??= new \DateTimeImmutable();

        return new self($role, $content, $reasoningContent, [
            'created_at' => $at->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function toArray(): array
    {
        $payload = [
            'role' => $this->role->value,
            'content' => $this->content,
        ];
        if ($this->reasoningContent !== null) {
            $payload['reasoning_content'] = $this->reasoningContent;
        }
        if ($this->meta !== []) {
            $payload['meta'] = $this->meta;
        }

        return $payload;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

<?php

namespace Tivins\LlmBasic;
class Message {
    public function __construct(
        public Role $role,
        public string $content,
        public ?string $reasoning = null,
        public ?Usage $usage = null,
    ) {}
}
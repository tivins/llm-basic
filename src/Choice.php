<?php

namespace Tivins\LlmBasic;


class Choice {
    public function __construct(
        public int $index,
        public Message $message,
        public string $finishReason,
    ) {}
}
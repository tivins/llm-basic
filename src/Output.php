<?php

namespace Tivins\LlmBasic;

class Output {
    public function __construct(
        public mixed $data,
        public Usage $usage,
        public array $choices,
        public string $model,
    ) {}
}
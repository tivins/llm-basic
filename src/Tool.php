<?php

namespace Tivins\LlmBasic;

class Tool
{
    public $handler;
    public function __construct(
        public ToolSchema $schema,
        callable          $handler)
    {
        $this->handler = $handler;
    }
}
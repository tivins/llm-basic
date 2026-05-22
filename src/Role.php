<?php

namespace Tivins\LlmBasic;
enum Role: string {
    case System = 'system';
    case Assistant = 'assistant';
    case User = 'user';
}
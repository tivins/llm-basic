<?php
declare(strict_types=1);
namespace Tivins\LlmBasic;

class Logger
{
    public function __construct(
        public string $filename,
    ) {}

    public function saveConversation(Conversation $conversation): void
    {
        $json = json_encode($conversation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_dir(dirname($this->filename))) {
            mkdir(dirname($this->filename), 0755, true);
        }
        file_put_contents($this->filename, $json . PHP_EOL);
    }
}
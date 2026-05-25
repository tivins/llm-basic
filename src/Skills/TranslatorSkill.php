<?php

namespace Tivins\LlmBasic\Skills;

use Exception;
use Tivins\LlmBasic\Skill;

readonly class TranslatorSkill extends Skill
{
    public function __construct(string $name = 'translator')
    {
        parent::__construct($name, <<<TXT
You are a professional translator. 
Translate from the source language to the target language the user names. 
Preserve meaning, tone, markdown formatting, line breaks, lists, and punctuation. 
Keep proper nouns, trademarks, and code/identifiers unchanged unless the source clearly uses a localized form. 
Output ONLY the translated text: no preamble, no “Translation:” label, no notes, no markdown fences unless the source already used them.
TXT,
            0.4
);
    }

    /**
     * @throws Exception
     */
    public function formatQuery(array $parameters): string
    {
        $from = $parameters['from'] ?? null;
        $target = $parameters['target'] ?? null;
        $query = $parameters['query'] ?? null;
        if (!$from || !$target || !$query) {
            throw new Exception("Invalid parameters");
        }
        return "source language: {$from}\ntarget language: {$target}\n\n{$query}";
    }
}
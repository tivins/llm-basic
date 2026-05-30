<?php

declare(strict_types=1);

use Tivins\LlmBasic\TTS;

require __DIR__ . '/../vendor/autoload.php';

try {
    $txt = <<<TXT
In the world of object-oriented programming (OOP), writing code that "just works" is only the first step. 
As applications grow in complexity, the real challenge becomes maintaining that code, making it easy to extend, and ensuring that a change in one part of the system doesn't cause a cascade of bugs elsewhere. 
This is where the **SOLID principles** come into play.
TXT;

    $tts = new TTS("/data/projects/tts/.venv/bin/python /data/projects/tts/tts.py");
    $tts->toAudio($txt, output_file:  __dir__ . "/out_1.wav");

} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
exit(0);
<?php

namespace Tivins\LlmBasic;

class TTS
{
    public function __construct(
        private readonly string $programLocation,
        private string          $speakerName = "Gitta Nikolina",
        private bool            $forceCPU = false
    )
    {
    }

    public function getSpeakers(): array
    {
        $result = shell_exec($this->programLocation . " --list-speakers");
        return array_filter(explode("\n", $result));
    }

    public function setSpeaker(string $speakerName): void
    {
        $this->speakerName = $speakerName;
    }
    public function setForceCPU(bool $forceCPU): void
    {
        $this->forceCPU = $forceCPU;
    }

    public function toAudio(string $text_or_file, string $lang = "en", ?string $output_file = null): string
    {
        $file_wav = $output_file ?? tempnam(sys_get_temp_dir(), "tts");
        $file_txt = is_file($text_or_file) ? $text_or_file : tempnam(sys_get_temp_dir(), "tts");
        if (!is_file($text_or_file)) file_put_contents($file_txt, $text_or_file);

        $output = shell_exec($this->programLocation
            . " -l " . escapeshellarg($lang)
            . " -s " . escapeshellarg($this->speakerName)
            . " -f " . escapeshellarg($file_txt)
            . " -o " . escapeshellarg($file_wav)
            . ($this->forceCPU ? " --cpu" : "")
        );
        var_dump($output);
        unlink($file_txt);
        return $file_wav;
    }
}
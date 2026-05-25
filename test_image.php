<?php
declare(strict_types=1);

/**
 * Smoke test for Invoke (Community Edition) text-to-image API.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Tivins\LlmBasic\Invoke;

$prompt = '(character concept art)++, stylized painterly digital painting of a medieval knight, (painterly, impasto. Dry brush.)++';
$negative_prompt = '';

$steps = 30;
$width = 768;
$height = 1024;

try {
    $invoke = new Invoke();

    /*
    var_dump($invoke->listModels());
    exit;
    */

    $result = $invoke->textToImage($prompt, $negative_prompt, $steps, $width, $height);
    $image = $result['image'];

    echo "batch_id: {$result['batch_id']}\n";
    echo "image_name: {$image['image_name']}\n";
    echo "image_url: {$invoke->imageUrl($image)}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

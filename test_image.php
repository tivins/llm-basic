<?php
declare(strict_types=1);

/**
 * Smoke test for Invoke (Community Edition) text-to-image API.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Tivins\LlmBasic\Invoke;

$model_name = getenv('LLMBASIC_MODEL') ?: null;
$prompt = '(character concept art)++, stylized painterly digital painting of a medieval knight, (painterly, impasto. Dry brush.)++';
$negative_prompt = 'blurry, photo, painting, color. messy, dirty. unfinished. frame, borders.';
$loras = [
    ['name' => 'HandFineTuning_XL', 'weight' => 0.75],
];

$steps = 30;
$width = 768;
$height = 1024;
$cfg_scale = 5;
$scheduler = 'dpmpp_2m_sde_k';//dpmpp_2m_sde_k
$seed = 42;

try {
    $invoke = new Invoke();

    # var_dump($invoke->listModels('lora'));exit;
    # var_dump($invoke->listModels('main'));exit;
    # var_dump($invoke->listSchedulers());exit;
    
    $vae = $invoke->listModels('vae', 'sdxl-vae-fp16-fix')[0] ?? null;

    $result = $invoke->textToImage($prompt, $negative_prompt, $steps, $width, $height, $model_name, $cfg_scale, $scheduler, $seed, $vae, $loras);
    $image = $result['image'];

    echo "batch_id: {$result['batch_id']}\n";
    echo "image_name: {$image['image_name']}\n";
    echo "image_url: {$invoke->imageUrl($image)}\n";

    file_put_contents(__DIR__ . '/image.png', file_get_contents($invoke->imageUrl($image)));
    $invoke->deleteImage($image['image_name']);

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

<?php
declare(strict_types=1);

/**
 * Smoke test for Invoke (Community Edition) text-to-image API.
 * Not related to llm-basic — calls http://127.0.0.1:9090 directly.
 */

const INVOKE_BASE_URL = 'http://127.0.0.1:9090';
const QUEUE_ID = 'default';

$prompt = '(character concept art)++, stylized painterly digital painting of a medieval knight, (painterly, impasto. Dry brush.)++';
$negative_prompt = '';


$steps = 30;
$width = 768;
$height = 1024;

try {
    /*
    $response = invokeRequest('GET', '/api/v2/models/?model_type=main');
    var_dump($response);
    exit;
    */
    $modelName = null;
    $model = fetchDefaultModel($modelName);
    /*
    var_dump($model);
    exit;
    */
    $batchId = enqueueTextToImage($model, $prompt, $negative_prompt, $steps, $width, $height);
    $image = waitForBatchImage($batchId);

    echo "batch_id: {$batchId}\n";
    echo "image_name: {$image['image_name']}\n";
    echo "image_url: " . INVOKE_BASE_URL . '/' . ltrim((string) $image['image_url'], '/') . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

function invokeRequest(string $method, string $path, ?array $body = null): array
{
    $url = rtrim(INVOKE_BASE_URL, '/') . $path;
    $curl = curl_init($url);
    $headers = ['Accept: application/json'];

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }

    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 600,
    ]);

    $response = curl_exec($curl);
    if ($response === false) {
        throw new RuntimeException('Invoke request failed: ' . curl_error($curl));
    }

    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $decoded = json_decode($response, true);
    if ($status >= 400) {
        $detail = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : $response;
        throw new RuntimeException("Invoke HTTP {$status} on {$path}: {$detail}");
    }

    if (!is_array($decoded)) {
        throw new RuntimeException("Unexpected Invoke response on {$path}: {$response}");
    }

    return $decoded;
}

function fetchDefaultModel(?string $modelName = null): array
{
    $query = 'model_type=main';
    if ($modelName !== null && $modelName !== '') {
        $query .= '&model_name=' . rawurlencode($modelName);
    } else {
        $query .= '&limit=1';
    }

    $response = invokeRequest('GET', '/api/v2/models/?' . $query);
    $models = $response['models'] ?? [];
    if ($models === []) {
        if ($modelName !== null && $modelName !== '') {
            throw new RuntimeException("Model \"{$modelName}\" not found in Invoke.");
        }

        throw new RuntimeException('No main model found in Invoke. Install one in Model Manager first.');
    }

    return $models[0];
}

function enqueueTextToImage(array $model, string $prompt, string $negative_prompt, int $steps, int $width, int $height): string
{
    $isSdxl = ($model['base'] ?? '') === 'sdxl';
    $modelRef = [
        'key' => $model['key'],
        'hash' => $model['hash'],
        'name' => $model['name'],
        'base' => $model['base'],
        'type' => $model['type'],
    ];

    $nodes = [
        'model_loader' => [
            'type' => $isSdxl ? 'sdxl_model_loader' : 'main_model_loader',
            'id' => 'model_loader',
            'model' => $modelRef,
        ],
        'positive_prompt' => [
            'type' => $isSdxl ? 'sdxl_compel_prompt' : 'compel',
            'id' => 'positive_prompt',
            'prompt' => $prompt,
            ...($isSdxl ? ['style' => $prompt] : []),
        ],
        'negative_prompt' => [
            'type' => $isSdxl ? 'sdxl_compel_prompt' : 'compel',
            'id' => 'negative_prompt',
            'prompt' => $negative_prompt,
            ...($isSdxl ? ['style' => ''] : []),
        ],
        'noise' => [
            'type' => 'noise',
            'id' => 'noise',
            'seed' => random_int(0, 4294967295),
            'width' => $width,
            'height' => $height,
            'use_cpu' => false,
        ],
        'denoise' => [
            'type' => 'denoise_latents',
            'id' => 'denoise',
            'steps' => $steps,
            'cfg_scale' => 7.5,
            'scheduler' => 'euler',
            'denoising_start' => 0,
            'denoising_end' => 1,
        ],
        'latents_to_image' => [
            'type' => 'l2i',
            'id' => 'latents_to_image',
        ],
        'save_image' => [
            'type' => 'save_image',
            'id' => 'save_image',
            'is_intermediate' => false,
        ],
    ];

    $edges = [
        edge('model_loader', 'unet', 'denoise', 'unet'),
        edge('model_loader', 'clip', 'positive_prompt', 'clip'),
        edge('model_loader', 'clip', 'negative_prompt', 'clip'),
        edge('positive_prompt', 'conditioning', 'denoise', 'positive_conditioning'),
        edge('negative_prompt', 'conditioning', 'denoise', 'negative_conditioning'),
        edge('noise', 'noise', 'denoise', 'noise'),
        edge('denoise', 'latents', 'latents_to_image', 'latents'),
        edge('model_loader', 'vae', 'latents_to_image', 'vae'),
        edge('latents_to_image', 'image', 'save_image', 'image'),
    ];

    if ($isSdxl) {
        $edges[] = edge('model_loader', 'clip2', 'positive_prompt', 'clip2');
        $edges[] = edge('model_loader', 'clip2', 'negative_prompt', 'clip2');
    }

    $response = invokeRequest('POST', '/api/v1/queue/' . QUEUE_ID . '/enqueue_batch', [
        'batch' => [
            'graph' => [
                'id' => 'php_test_graph',
                'nodes' => $nodes,
                'edges' => $edges,
            ],
            'runs' => 1,
            'data' => null,
        ],
    ]);

    $batchId = $response['batch']['batch_id'] ?? null;
    if (!is_string($batchId) || $batchId === '') {
        throw new RuntimeException('Invoke did not return a batch_id: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
    }

    return $batchId;
}

function waitForBatchImage(string $batchId, int $timeoutSeconds = 600): array
{
    $deadline = time() + $timeoutSeconds;

    while (time() < $deadline) {
        $status = invokeRequest('GET', '/api/v1/queue/' . QUEUE_ID . '/b/' . rawurlencode($batchId) . '/status');

        if (($status['failed'] ?? 0) > 0) {
            throw new RuntimeException('Invoke batch failed: ' . json_encode($status, JSON_UNESCAPED_UNICODE));
        }

        $completed = (int) ($status['completed'] ?? 0);
        $total = (int) ($status['total'] ?? 0);
        if ($total > 0 && $completed === $total) {
            $images = invokeRequest('GET', '/api/v1/images/?is_intermediate=false&limit=1');
            $items = $images['items'] ?? [];
            if ($items === []) {
                throw new RuntimeException('Batch completed but no image was returned.');
            }

            return $items[0];
        }

        sleep(2);
    }

    throw new RuntimeException("Timed out waiting for batch {$batchId}.");
}

function edge(string $sourceNode, string $sourceField, string $destinationNode, string $destinationField): array
{
    return [
        'source' => ['node_id' => $sourceNode, 'field' => $sourceField],
        'destination' => ['node_id' => $destinationNode, 'field' => $destinationField],
    ];
}

<?php
declare(strict_types=1);

namespace Tivins\LlmBasic;

use Exception;

class Invoke
{
    public function __construct(
        public string $baseUrl = 'http://127.0.0.1:9090',
        public string $queueId = 'default',
        public int $timeoutSeconds = 600,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws Exception
     */
    public function listModels(string $modelType = 'main', ?string $modelName = null, ?int $limit = null): array
    {
        $query = 'model_type=' . rawurlencode($modelType);
        if ($modelName !== null && $modelName !== '') {
            $query .= '&model_name=' . rawurlencode($modelName);
        }
        if ($limit !== null) {
            $query .= '&limit=' . $limit;
        }

        $response = $this->request('GET', '/api/v2/models/?' . $query);

        return $response['models'] ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function fetchModel(?string $modelName = null): array
    {
        $models = $this->listModels(
            modelName: $modelName,
            limit: ($modelName === null || $modelName === '') ? 1 : null,
        );
        if ($models === []) {
            if ($modelName !== null && $modelName !== '') {
                throw new Exception("Model \"{$modelName}\" not found in Invoke.");
            }

            throw new Exception('No main model found in Invoke. Install one in Model Manager first.');
        }

        return $models[0];
    }

    /**
     * @param array<string, mixed> $model
     *
     * @throws Exception
     */
    public function enqueueTextToImage(
        array $model,
        string $prompt,
        string $negativePrompt = '',
        int $steps = 30,
        int $width = 768,
        int $height = 1024,
    ): string {
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
                'prompt' => $negativePrompt,
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
            $this->edge('model_loader', 'unet', 'denoise', 'unet'),
            $this->edge('model_loader', 'clip', 'positive_prompt', 'clip'),
            $this->edge('model_loader', 'clip', 'negative_prompt', 'clip'),
            $this->edge('positive_prompt', 'conditioning', 'denoise', 'positive_conditioning'),
            $this->edge('negative_prompt', 'conditioning', 'denoise', 'negative_conditioning'),
            $this->edge('noise', 'noise', 'denoise', 'noise'),
            $this->edge('denoise', 'latents', 'latents_to_image', 'latents'),
            $this->edge('model_loader', 'vae', 'latents_to_image', 'vae'),
            $this->edge('latents_to_image', 'image', 'save_image', 'image'),
        ];

        if ($isSdxl) {
            $edges[] = $this->edge('model_loader', 'clip2', 'positive_prompt', 'clip2');
            $edges[] = $this->edge('model_loader', 'clip2', 'negative_prompt', 'clip2');
        }

        $response = $this->request('POST', '/api/v1/queue/' . $this->queueId . '/enqueue_batch', [
            'batch' => [
                'graph' => [
                    'id' => 'text_to_image_graph',
                    'nodes' => $nodes,
                    'edges' => $edges,
                ],
                'runs' => 1,
                'data' => null,
            ],
        ]);

        $batchId = $response['batch']['batch_id'] ?? null;
        if (!is_string($batchId) || $batchId === '') {
            throw new Exception('Invoke did not return a batch_id: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
        }

        return $batchId;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function waitForBatchImage(string $batchId, ?int $timeoutSeconds = null): array
    {
        $timeoutSeconds ??= $this->timeoutSeconds;
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $status = $this->request(
                'GET',
                '/api/v1/queue/' . $this->queueId . '/b/' . rawurlencode($batchId) . '/status',
            );

            if (($status['failed'] ?? 0) > 0) {
                throw new Exception('Invoke batch failed: ' . json_encode($status, JSON_UNESCAPED_UNICODE));
            }

            $completed = (int) ($status['completed'] ?? 0);
            $total = (int) ($status['total'] ?? 0);
            if ($total > 0 && $completed === $total) {
                $images = $this->request('GET', '/api/v1/images/?is_intermediate=false&limit=1');
                $items = $images['items'] ?? [];
                if ($items === []) {
                    throw new Exception('Batch completed but no image was returned.');
                }

                return $items[0];
            }

            sleep(2);
        }

        throw new Exception("Timed out waiting for batch {$batchId}.");
    }

    /**
     * @return array{batch_id: string, image: array<string, mixed>}
     *
     * @throws Exception
     */
    public function textToImage(
        string $prompt,
        string $negativePrompt = '',
        int $steps = 30,
        int $width = 768,
        int $height = 1024,
        ?string $modelName = null,
    ): array {
        $model = $this->fetchModel($modelName);
        $batchId = $this->enqueueTextToImage($model, $prompt, $negativePrompt, $steps, $width, $height);

        return [
            'batch_id' => $batchId,
            'image' => $this->waitForBatchImage($batchId),
        ];
    }

    /**
     * @param array<string, mixed> $image
     */
    public function imageUrl(array $image): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim((string) ($image['image_url'] ?? ''), '/');
    }

    /**
     * @throws Exception
     */
    public function deleteImage(string $imageName): void
    {
        if ($imageName === '') {
            throw new Exception('Image name is required to delete.');
        }

        $this->request('DELETE', '/api/v1/images/i/' . rawurlencode($imageName));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
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
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $response = curl_exec($curl);
        if ($response === false) {
            throw new Exception('Invoke request failed: ' . curl_error($curl));
        }

        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $decoded = json_decode($response, true);
        if ($status >= 400) {
            $detail = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : $response;
            throw new Exception("Invoke HTTP {$status} on {$path}: {$detail}");
        }

        if (!is_array($decoded)) {
            if ($response === '' || $response === false) {
                return [];
            }

            throw new Exception("Unexpected Invoke response on {$path}: {$response}");
        }

        return $decoded;
    }

    /**
     * @return array{source: array{node_id: string, field: string}, destination: array{node_id: string, field: string}}
     */
    private function edge(string $sourceNode, string $sourceField, string $destinationNode, string $destinationField): array
    {
        return [
            'source' => ['node_id' => $sourceNode, 'field' => $sourceField],
            'destination' => ['node_id' => $destinationNode, 'field' => $destinationField],
        ];
    }
}

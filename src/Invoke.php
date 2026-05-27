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
     * @return list<string>
     *
     * @throws Exception
     */
    public function listSchedulers(): array
    {
        $schema = $this->request('GET', '/openapi.json');
        $schedulers = $schema['components']['schemas']['DenoiseLatentsInvocation']['properties']['scheduler']['enum'] ?? null;
        if (!is_array($schedulers) || $schedulers === []) {
            throw new Exception('Could not read schedulers from Invoke OpenAPI schema.');
        }

        return array_values(array_filter($schedulers, 'is_string'));
    }

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
     * @param array<string, mixed>      $model
     * @param array<string, mixed>|null $vaeModel Optional VAE model ref (key/hash/name/base/type).
     *                                            When provided for SDXL, a dedicated vae_loader node
     *                                            is used instead of the checkpoint's built-in VAE.
     *                                            Recommended: sdxl-vae-fp16-fix for better colour fidelity.
     * @param list<array<string, mixed>> $loras   Resolved LoRA model refs (key/hash/name/base/type) each
     *                                            augmented with a `weight` float key. Pass the output of
     *                                            listModels('lora') entries with a `weight` key added, or
     *                                            use textToImage() which resolves names automatically.
     *
     * @return array{batch_id: string, item_id: int}
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
        float $cfgScale = 7.5,
        string $scheduler = 'euler',
        ?int $seed = null,
        ?array $vaeModel = null,
        array $loras = [],
    ): array {
        $isSdxl = ($model['base'] ?? '') === 'sdxl';

        // Use SDXL-tuned defaults when the caller did not override them explicitly.
        if ($isSdxl) {
            if ($scheduler === 'euler') {
                $scheduler = 'dpmpp_2m_sde_k';
            }
            if ($cfgScale === 7.5) {
                $cfgScale = 5.0;
            }
        }

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
                ...($isSdxl ? ['style' => $negativePrompt] : []),
            ],
            'noise' => [
                'type' => 'noise',
                'id' => 'noise',
                'seed' => $seed ?? random_int(0, 4294967295),
                'width' => $width,
                'height' => $height,
                'use_cpu' => false,
            ],
            'denoise' => [
                'type' => 'denoise_latents',
                'id' => 'denoise',
                'steps' => $steps,
                'cfg_scale' => $cfgScale,
                'scheduler' => $scheduler,
                'denoising_start' => 0,
                'denoising_end' => 1,
            ],
            'latents_to_image' => [
                'type' => 'l2i',
                'id' => 'latents_to_image',
                'fp32' => true,
            ],
        ];

        $useExternalVae = $isSdxl && $vaeModel !== null;
        if ($useExternalVae) {
            $nodes['vae_loader'] = [
                'type' => 'vae_loader',
                'id' => 'vae_loader',
                'vae_model' => $vaeModel,
            ];
        }

        // LoRA pattern (mirrors Invoke UI):
        //   lora_selector_N  ──lora──►  lora_collector  ──collection──►  lora_collection_loader
        //   model_loader  ──unet/clip[/clip2]──►  lora_collection_loader
        //   lora_collection_loader  ──unet/clip[/clip2]──►  denoise / prompts
        // When $loras is empty we skip these nodes and wire model_loader directly.
        $loraEdges = [];
        if ($loras !== []) {
            $nodes['lora_collector'] = ['type' => 'collect', 'id' => 'lora_collector'];
            $nodes['lora_collection_loader'] = [
                'type' => $isSdxl ? 'sdxl_lora_collection_loader' : 'lora_collection_loader',
                'id'   => 'lora_collection_loader',
            ];

            foreach ($loras as $i => $lora) {
                $selectorId = 'lora_selector_' . $i;
                $nodes[$selectorId] = [
                    'type'   => 'lora_selector',
                    'id'     => $selectorId,
                    'lora'   => [
                        'key'  => $lora['key']  ?? '',
                        'hash' => $lora['hash'] ?? '',
                        'name' => $lora['name'],
                        'base' => $lora['base'] ?? $model['base'],
                        'type' => $lora['type'] ?? 'lora',
                    ],
                    'weight' => (float) ($lora['weight'] ?? 1.0),
                ];
                $loraEdges[] = $this->edge($selectorId, 'lora', 'lora_collector', 'item');
            }

            $loraEdges[] = $this->edge('lora_collector', 'collection', 'lora_collection_loader', 'loras');
            $loraEdges[] = $this->edge('model_loader', 'unet', 'lora_collection_loader', 'unet');
            $loraEdges[] = $this->edge('model_loader', 'clip', 'lora_collection_loader', 'clip');
            if ($isSdxl) {
                $loraEdges[] = $this->edge('model_loader', 'clip2', 'lora_collection_loader', 'clip2');
            }
        }

        // Source node for unet/clip connections to denoise and prompt nodes.
        $unetClipSource = $loras !== [] ? 'lora_collection_loader' : 'model_loader';

        $edges = array_merge($loraEdges, [
            $this->edge($unetClipSource, 'unet', 'denoise', 'unet'),
            $this->edge($unetClipSource, 'clip', 'positive_prompt', 'clip'),
            $this->edge($unetClipSource, 'clip', 'negative_prompt', 'clip'),
            $this->edge('positive_prompt', 'conditioning', 'denoise', 'positive_conditioning'),
            $this->edge('negative_prompt', 'conditioning', 'denoise', 'negative_conditioning'),
            $this->edge('noise', 'noise', 'denoise', 'noise'),
            $this->edge('denoise', 'latents', 'latents_to_image', 'latents'),
            $useExternalVae
                ? $this->edge('vae_loader', 'vae', 'latents_to_image', 'vae')
                : $this->edge('model_loader', 'vae', 'latents_to_image', 'vae'),
        ]);

        if ($isSdxl) {
            $edges[] = $this->edge($unetClipSource, 'clip2', 'positive_prompt', 'clip2');
            $edges[] = $this->edge($unetClipSource, 'clip2', 'negative_prompt', 'clip2');
        }

        $graph = [
            'id' => 'text_to_image_graph',
            'nodes' => $nodes,
            'edges' => $edges,
        ];
        $batch = [
            'batch' => [
                'graph' => $graph,
                'runs' => 1,
                'data' => null,
            ],
        ];

        // echo json_encode($batch, JSON_UNESCAPED_UNICODE) . PHP_EOL . PHP_EOL;
        $response = $this->request('POST', '/api/v1/queue/' . $this->queueId . '/enqueue_batch', $batch);

        $batchId = $response['batch']['batch_id'] ?? null;
        if (!is_string($batchId) || $batchId === '') {
            throw new Exception('Invoke did not return a batch_id: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
        }

        $itemIds = $response['item_ids'] ?? [];
        $itemId = is_array($itemIds) ? ($itemIds[0] ?? null) : null;
        if (!is_int($itemId)) {
            throw new Exception('Invoke did not return a queue item_id: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
        }

        return [
            'batch_id' => $batchId,
            'item_id' => $itemId,
            'batch' => $batch,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function waitForBatchImage(string $batchId, int $itemId, ?int $timeoutSeconds = null): array
    {
        $timeoutSeconds ??= $this->timeoutSeconds;
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $status = $this->request(
                'GET',
                '/api/v1/queue/' . $this->queueId . '/b/' . rawurlencode($batchId) . '/status',
            );

            if (($status['failed'] ?? 0) > 0) {
                $detail = $this->fetchQueueItemError($itemId);
                throw new Exception('Invoke batch failed: ' . $detail);
            }

            $completed = (int) ($status['completed'] ?? 0);
            $total = (int) ($status['total'] ?? 0);
            if ($total > 0 && $completed === $total) {
                $queueItem = $this->request('GET', '/api/v1/queue/' . $this->queueId . '/i/' . $itemId);
                $sessionId = $queueItem['session_id'] ?? null;
                if (!is_string($sessionId) || $sessionId === '') {
                    throw new Exception('Batch completed but queue item has no session_id.');
                }

                return $this->findSessionImage($sessionId);
            }

            sleep(2);
        }

        throw new Exception("Timed out waiting for batch {$batchId}.");
    }

    /**
     * @param array<string, mixed>|null  $vaeModel Optional VAE model ref forwarded to enqueueTextToImage().
     * @param list<array{name: string, weight: float}> $loras LoRA descriptors with `name` and `weight`.
     *                                                         Each name is resolved via listModels('lora').
     *
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
        float $cfgScale = 7.5,
        string $scheduler = 'euler',
        ?int $seed = null,
        ?array $vaeModel = null,
        array $loras = [],
    ): array {
        $model = $this->fetchModel($modelName);

        $resolvedLoras = [];
        foreach ($loras as $lora) {
            $name = $lora['name'] ?? '';
            $matches = $this->listModels('lora', $name);
            if ($matches === []) {
                throw new Exception("LoRA model \"{$name}\" not found in Invoke.");
            }
            $resolvedLoras[] = array_merge($matches[0], ['weight' => (float) ($lora['weight'] ?? 1.0)]);
        }

        $enqueued = $this->enqueueTextToImage($model, $prompt, $negativePrompt, $steps, $width, $height, $cfgScale, $scheduler, $seed, $vaeModel, $resolvedLoras);

        return [
            'batch_id' => $enqueued['batch_id'],
            'image' => $this->waitForBatchImage($enqueued['batch_id'], $enqueued['item_id']),
            'batch' => $enqueued['batch']
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
     * Fetch the queue item and extract the most useful error detail available.
     * Falls back to the raw JSON when no structured error is present.
     */
    private function fetchQueueItemError(int $itemId): string
    {
        try {
            $item = $this->request('GET', '/api/v1/queue/' . $this->queueId . '/i/' . $itemId);
        } catch (Exception) {
            return "item_id={$itemId} (could not fetch queue item)";
        }

        // Invoke stores execution errors under session.execution_graph.nodes.<id>.error
        $errors = [];
        $nodes = $item['session']['execution_graph']['nodes'] ?? [];
        foreach ($nodes as $nodeId => $node) {
            if (isset($node['error'])) {
                $errors[] = "[{$nodeId}] " . (is_string($node['error']) ? $node['error'] : json_encode($node['error'], JSON_UNESCAPED_UNICODE));
            }
        }
        if ($errors !== []) {
            return implode(' | ', $errors);
        }

        // Fallback: return the trimmed raw item JSON (capped to avoid wall of text)
        $raw = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';

        return strlen($raw) > 2000 ? substr($raw, 0, 2000) . '…' : $raw;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function findSessionImage(string $sessionId): array
    {
        $images = $this->request('GET', '/api/v1/images/?is_intermediate=false&limit=50');
        $items = $images['items'] ?? [];
        foreach ($items as $item) {
            if (($item['session_id'] ?? null) === $sessionId) {
                return $item;
            }
        }

        throw new Exception("Batch completed but no image was found for session {$sessionId}.");
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

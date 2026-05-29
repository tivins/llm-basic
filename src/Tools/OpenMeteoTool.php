<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Tools;

use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;

/**
 * @see https://open-meteo.com/en/docs
 */
class OpenMeteoTool extends Tool
{
    private const string BASE_URL = 'https://api.open-meteo.com/v1/forecast';

    public function __construct()
    {
        parent::__construct(
            new ToolSchema(
                'open_meteo_forecast',
                'Fetch weather forecast data from the free Open-Meteo API (no API key). '
                . 'Requires latitude and longitude. Request at least one of current, hourly, or daily variables '
                . '(comma-separated Open-Meteo variable names); if all three are omitted, sensible defaults are used. '
                . 'Docs: https://open-meteo.com/en/docs',
                [
                    'type' => 'object',
                    'properties' => [
                        'latitude' => [
                            'type' => 'number',
                            'description' => 'WGS84 latitude (-90 to 90).',
                        ],
                        'longitude' => [
                            'type' => 'number',
                            'description' => 'WGS84 longitude (-180 to 180).',
                        ],
                        'current' => [
                            'type' => 'string',
                            'description' => 'Comma-separated current weather variables (e.g. temperature_2m,cloud_cover,wind_speed_10m).',
                        ],
                        'hourly' => [
                            'type' => 'string',
                            'description' => 'Comma-separated hourly variables (e.g. temperature_2m,precipitation).',
                        ],
                        'daily' => [
                            'type' => 'string',
                            'description' => 'Comma-separated daily variables (e.g. sunrise,sunset,temperature_2m_max).',
                        ],
                        'timezone' => [
                            'type' => 'string',
                            'description' => 'IANA timezone for timestamps (e.g. Europe/Berlin). Defaults to GMT.',
                        ],
                        'forecast_days' => [
                            'type' => 'integer',
                            'description' => 'Forecast length in days (1–16, default 7).',
                            'default' => 7,
                        ],
                        'past_days' => [
                            'type' => 'integer',
                            'description' => 'Archived forecast days to include (0–92, default 0).',
                            'default' => 0,
                        ],
                        'models' => [
                            'type' => 'string',
                            'description' => 'Optional weather model override (e.g. meteofrance_seamless, best_match).',
                        ],
                    ],
                    'required' => ['latitude', 'longitude'],
                    'additionalProperties' => false,
                ],
            ),
            function (string $argumentsJson): string {
                $args = json_decode($argumentsJson, true) ?? [];

                if (!array_key_exists('latitude', $args) || !is_numeric($args['latitude'])) {
                    return self::formatError('latitude must be a number between -90 and 90');
                }
                if (!array_key_exists('longitude', $args) || !is_numeric($args['longitude'])) {
                    return self::formatError('longitude must be a number between -180 and 180');
                }

                $latitude = (float) $args['latitude'];
                $longitude = (float) $args['longitude'];
                if ($latitude < -90.0 || $latitude > 90.0) {
                    return self::formatError('latitude must be between -90 and 90');
                }
                if ($longitude < -180.0 || $longitude > 180.0) {
                    return self::formatError('longitude must be between -180 and 180');
                }

                $current = self::optionalCommaList($args['current'] ?? null);
                $hourly = self::optionalCommaList($args['hourly'] ?? null);
                $daily = self::optionalCommaList($args['daily'] ?? null);
                if ($current === null && $hourly === null && $daily === null) {
                    $current = 'temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m,is_day';
                    $daily = 'temperature_2m_max,temperature_2m_min,sunrise,sunset';
                }

                $forecastDays = isset($args['forecast_days']) ? (int) $args['forecast_days'] : 7;
                $forecastDays = max(1, min(16, $forecastDays));

                $pastDays = isset($args['past_days']) ? (int) $args['past_days'] : 0;
                $pastDays = max(0, min(92, $pastDays));

                $query = [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'forecast_days' => $forecastDays,
                    'past_days' => $pastDays,
                ];

                if ($current !== null) {
                    $query['current'] = $current;
                }
                if ($hourly !== null) {
                    $query['hourly'] = $hourly;
                }
                if ($daily !== null) {
                    $query['daily'] = $daily;
                }

                $timezone = $args['timezone'] ?? null;
                if (is_string($timezone) && $timezone !== '') {
                    $query['timezone'] = $timezone;
                }

                $models = $args['models'] ?? null;
                if (is_string($models) && $models !== '') {
                    $query['models'] = $models;
                }

                $url = self::BASE_URL . '?' . http_build_query($query);

                $ch = curl_init($url);
                if ($ch === false) {
                    return self::formatError('could not initialize HTTP client');
                }

                curl_setopt_array($ch, [
                    CURLOPT_HTTPGET => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_USERAGENT => 'tivins/llm-basic (OpenMeteoTool; +https://github.com/tivins/llm-basic)',
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);

                $body = curl_exec($ch);
                $err = curl_error($ch);
                $errno = curl_errno($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);

                if ($body === false || $body === '') {
                    return self::formatError([
                        'error' => $errno !== 0 ? $err : 'empty response from Open-Meteo API',
                        'http_status' => $code,
                        'curl_errno' => $errno,
                    ]);
                }

                $decoded = json_decode((string) $body, true);
                if (!is_array($decoded)) {
                    return self::formatError([
                        'error' => 'invalid JSON response from Open-Meteo API',
                        'http_status' => $code,
                    ]);
                }

                if (isset($decoded['error']) && is_bool($decoded['error']) && $decoded['error'] === true) {
                    $reason = is_string($decoded['reason'] ?? null) ? $decoded['reason'] : 'Open-Meteo API error';

                    return self::formatError([
                        'error' => $reason,
                        'http_status' => $code,
                    ]);
                }

                if ($code < 200 || $code >= 300) {
                    return self::formatError([
                        'error' => 'Open-Meteo API returned HTTP ' . $code,
                        'http_status' => $code,
                    ]);
                }

                $decoded['provider'] = 'open-meteo';
                $decoded['http_status'] = $code;
                $decoded['request_url'] = $url;

                return json_encode($decoded, JSON_UNESCAPED_UNICODE);
            },
        );
    }

    private static function optionalCommaList(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    private static function formatError(string|array $error): string
    {
        return json_encode(is_array($error) ? $error : ['error' => $error], JSON_UNESCAPED_UNICODE);
    }
}

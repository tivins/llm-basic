<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Tools;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;

class GetDateTimeTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            new ToolSchema(
                'get_date_time',
                'Return the current date and time from the host system clock. '
                . 'Use when the model needs today\'s date, day of week, or local time (scheduling, deadlines, "today", etc.). '
                . 'Optional IANA timezone; otherwise uses the PHP default timezone.',
                [
                    'type' => 'object',
                    'properties' => [
                        'timezone' => [
                            'type' => 'string',
                            'description' => 'IANA timezone (e.g. Europe/Paris, UTC). Defaults to the PHP default timezone.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
            ),
            function (string $argumentsJson): string {
                $args = json_decode($argumentsJson, true) ?? [];

                $timezoneName = date_default_timezone_get();
                if (isset($args['timezone']) && is_string($args['timezone']) && $args['timezone'] !== '') {
                    $timezoneName = $args['timezone'];
                }

                try {
                    $timezone = new DateTimeZone($timezoneName);
                } catch (Exception) {
                    return json_encode(
                        ['error' => 'Unknown timezone: ' . $timezoneName],
                        JSON_UNESCAPED_UNICODE,
                    );
                }

                $now = new DateTimeImmutable('now', $timezone);

                return json_encode([
                    'datetime' => $now->format(DateTimeInterface::ATOM),
                    'timezone' => $timezoneName,
                    'unix_timestamp' => $now->getTimestamp(),
                    'date' => $now->format('Y-m-d'),
                    'time' => $now->format('H:i:s'),
                    'day_of_week' => $now->format('l'),
                    'utc_offset' => $now->format('P'),
                ], JSON_UNESCAPED_UNICODE);
            },
        );
    }
}

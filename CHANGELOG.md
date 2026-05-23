# Changelog

## 0.4.1 — 2026-05-23

### Changed

- `ToolRegistry::register()` accepts an optional handler `callable(string): string` for custom tool execution.

## 0.4.0 — 2026-05-23

### Added

- `Tool`, `ToolCall`, and `ToolRegistry` for OpenAI-style function tools.
- `Tool::getWeather()` as a placeholder tool definition.
- `ToolRegistry::execute()` / `executeAll()` with a fake `get_weather` implementation.
- `Role::Tool`, `Message::$toolCalls`, `Message::$toolCallId`, and `Message::toChatCompletionArray()`.
- `ChatCompletionResponse::hasToolCalls()` to detect `tool_calls` finish flows.
- `ChatCompletionOptions::$tools` now accepts a `ToolRegistry` instead of a raw array.

## 0.3.0 — 2026-05-23

### Added

- `Message::$meta` for per-message metadata (usage, timing, model, etc.).
- `Message::withCreatedAt()` helper and `Message::toArray()` / `JsonSerializable`.
- `Conversation` implements `JsonSerializable` for stable export JSON.
- `Usage::toArray()` and `ChatCompletionResponse::toStoredMessage()` to archive assistant turns with completion metadata.

## 0.2.0 — 2026-05-23

### Changed

- Renamed `Output` to `ChatCompletionResponse`; raw JSON is available via `raw()` only.
- Removed `Usage` from `Message`; usage stays on the completion response.
- Renamed `Message::$reasoning` to `reasoningContent`.
- Centralized request defaults and optional fields in `ChatCompletionOptions::toRequestArray()`.
- Added `LLM::$defaultModel` and `ChatCompletionOptions::$model` for the request body.
- Added helpers on `ChatCompletionResponse`: `firstChoice()`, `finishReason()`, `assistantMessage()`.

### Fixed

- `Role::tryFrom()` fallback now returns `Role::User` instead of the string `'User'`.

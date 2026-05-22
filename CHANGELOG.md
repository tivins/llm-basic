# Changelog

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

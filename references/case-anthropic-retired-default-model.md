# Anthropic Retired Default Model

## Symptom

Static inspection found that the optional AI helper defaulted to
`claude-3-5-haiku-20241022`. A newly configured Claude connection would fail
before any suggestion could be generated because that model was no longer
available.

## Captured Evidence

- Anthropic's official deprecation page lists Claude Haiku 3.5 as retired on
  2026-02-19 and recommends Claude Haiku 4.5.
- Anthropic's model documentation identifies the pinned Haiku 4.5 API model as
  `claude-haiku-4-5-20251001`.
- The repository still contained the retired ID as `CLAUDE_DEFAULT_MODEL`; no
  live credential was used during local verification.

Official references:

- https://platform.claude.com/docs/en/docs/about-claude/model-deprecations
- https://platform.claude.com/docs/en/about-claude/models/model-ids-and-versions
- https://platform.claude.com/docs/en/build-with-claude/structured-outputs

## Root Cause

The provider model was pinned in source but was not exposed in the settings UI
and had no retirement review path. The AI feature also had no usable admin
configuration, so the stale default was hidden until runtime.

## Repair Decision

- Change the default to `claude-haiku-4-5-20251001` while keeping the model
  field editable for future provider changes.
- Use the current Messages structured-output contract through
  `output_config.format`.
- Treat `refusal` and `max_tokens` stop reasons as explicit failures even when
  the HTTP request itself succeeds.
- Cover the provider response envelope with dependency-free contract tests.

## Lesson

Provider model IDs are runtime dependencies even when no SDK is installed.
Keep defaults visible and editable, verify them against official provider
documentation during releases, and separate successful HTTP transport from a
usable model response.

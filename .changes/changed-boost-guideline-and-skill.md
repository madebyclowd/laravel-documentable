---
bump: patch
type: Changed
---

The Laravel Boost distribution is refreshed. The guideline is renamed from `core.blade.php` to `laravel-documentable.blade.php` and rewritten as a short orientation (conventions, commands, pitfalls) that points at the skill, matching `laravel-auto-sequence`. The skill gains trigger-style `description` wording, `license` and `metadata.author`, and its stale statements are corrected: a rejected presigned finalize no longer deletes the object, a body `user_id` is no longer the multipart owner fallback, the authorizer and `null` owner advice is fixed, and event timing, `purge()` failures, scanner errors, `throw => true` and the unsafe `use_native_checksum` are covered. Run `php artisan boost:update` in each consuming app to pick it up.

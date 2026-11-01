---
bump: patch
type: Changed
---

Inline URLs now declare the stored content type, so an image or PDF previews in the browser instead of downloading. Only types in `UrlOptions::INLINE_MIMES` are declared; an explicit `contentType` or a `url.modifier` still wins.

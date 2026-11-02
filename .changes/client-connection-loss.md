---
bump: minor
type: Added
---

The TypeScript client keeps a multipart session after a lost connection and tells the caller its id. `onSession(id)` fires once the session exists, and `error.sessionId` is set when the session was kept, so an app can save the id and pass it back as `resumeSessionId`. Signing part URLs and reading session status and parts are now retried with the configured backoff, and a failed request waits for the browser's `online` event while it reports offline. `initiate` and `complete` are not retried. A refusal or the caller's own abort still aborts the session.

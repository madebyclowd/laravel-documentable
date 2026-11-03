---
bump: minor
type: Added
---

The TypeScript client tells the caller where a large upload is. `onStage` reports `hashing`, `uploading`, `finalizing` and `processing`, so a UI no longer sits at 100% while the server verifies the object. `onHashProgress` reports the bytes read while hashing, and `ClientOptions.hash` swaps in the app's own hasher (`hash-wasm`, a Web Worker). Without `pollIntervalMs` the wait for a `processing` document polls after 1 s, 2 s, then every 5 s, instead of every 2 s. The published file now starts with a header saying how to tell whether it is stale. Docs: the Wayfinder `partUrl` import in the upgrade detect step, a "Coming from a 3.0 alpha" section, and the Vite `**/vendor/**` watch trap and `--force` overwrite next to the publish command.

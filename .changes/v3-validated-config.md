---
bump: major
type: Added
---

The package now validates `config/documentable.php` when the app boots. A bad value (an unknown disk, a class that does not implement its contract, a scheduler frequency that does not exist, an out-of-range number) throws `InvalidDocumentableConfig` naming the key and the fix, instead of failing at the first upload. `php artisan about` shows the resolved disk, ETag strategy and reaper frequency.

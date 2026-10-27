---
bump: major
type: Changed
---

The database now enforces one latest document per owner for single-slot document types. `latest_marker` holds the
document type id for those types (multi-slot types keep the group id), so two concurrent first uploads can no
longer both succeed. The loser retries once as the next version of the winner, and a second collision throws
`SlotConflict`. The new migration stops with the commands to run (`documents:upgrade-check`,
`documents:repair`) when an owner already has two latest documents.

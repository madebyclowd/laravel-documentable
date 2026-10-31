---
bump: minor
type: Added
---

Public seams and testing tools:
- Read API: `DocumentService::readStream()` returning PHP stream resources with `DocumentNotReady` / `LogicException` guards and access logging, and `DocumentService::readContents()` with memory limit guard and `DocumentTooLargeToRead` exception.
- Public seams: `DocumentService::attachStorageFile()` seam for attaching existing storage files using `AttachOptions` as the supported replacement for subclassing.
- Model factories: `DocumentFactory`, `DocumentTypeFactory`, `StorageFileFactory`, and `UploadSessionFactory` with strict-mode compatibility and lifecycle states.
- Testing tools: `Documentable::fake()` faking storage disks, the multipart upload driver, and package domain events, returning `DocumentableFake` with observable assertions (`assertUploaded`, `assertMultipartInitiated`, `assertAttached`, `assertPurged`, `assertNothingUploaded`).
- Moved `FakeMultipartUploadDriver` to public testing surface (`MadeByClowd\Documentable\Testing\FakeMultipartUploadDriver`).

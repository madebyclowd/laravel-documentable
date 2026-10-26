---
bump: major
type: Changed
---

`DocumentService` is now a facade over focused internal classes (`DirectUploadService`, `MultipartUploadService`, `ObjectVerifier`, `DocumentAttacher`, `UrlSigner`, `DocumentPurger`, `AccessLogger`). Its public methods keep their names and signatures. Its protected members and constructor are gone, so a subclass that overrode or called them must switch to the public methods, the package events, or the contracts. Resolve the service from the container instead of constructing it.

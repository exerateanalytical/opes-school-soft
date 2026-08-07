# OPES SCHOOL

A school management platform for Cameroon. Laravel 13 + MySQL 8, API-first,
domain-driven, with a responsive Livewire frontend in the same codebase.

- **Specification suite:** [`docs/specs/README.md`](docs/specs/README.md)
- **Implementation plans:** [`docs/superpowers/plans/`](docs/superpowers/plans/)
- **Development setup:** [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md)

## Status

Phase 0A — foundation and kernel. Complete.
Phase 0B — identity, audit, settings and i18n. Complete.

Delivered: modular skeleton, `opes:preflight`, the `Money` / `Rate` / `Score` /
`BusinessDate` value objects, users with 20 seeded roles and granular
permissions, a hash-chained and anchored audit log with nightly verification,
a break-glass recovery credential, a typed settings registry with lockable
engine-behaviour settings, and bilingual EN/FR strings.

Next: Phase 0C — installer, TLS, backup and verified restore drill, health
page, log rotation.

# OPES SCHOOL

A school management platform for Cameroon. Laravel 13 + MySQL 8, API-first,
domain-driven, with a responsive Livewire frontend in the same codebase.

- **Specification suite:** [`docs/specs/README.md`](docs/specs/README.md)
- **Implementation plans:** [`docs/superpowers/plans/`](docs/superpowers/plans/)
- **Development setup:** [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md)

## Status

Phase 0A — foundation and kernel. Complete.

Delivered: modular skeleton, `opes:preflight` (rejects MariaDB and unsupported
PHP), the `Money` / `Rate` / `Score` / `BusinessDate` value objects with their
architecture tests, and CI with blocking gates.

Next: Phase 0B — auth, roles, hash-chained audit, settings registry, i18n.

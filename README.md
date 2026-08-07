# OPES SCHOOL

A school management platform for Cameroon. Laravel 13 + MySQL 8, API-first,
domain-driven, with a responsive Livewire frontend in the same codebase.

- **Specification suite:** [`docs/specs/README.md`](docs/specs/README.md)
- **Implementation plans:** [`docs/superpowers/plans/`](docs/superpowers/plans/)
- **Development setup:** [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md)

## Status

Phase 0A — foundation and kernel. Complete.
Phase 0B — identity, audit, settings and i18n. Complete.
Phase 0C — backup, restore drill and health. Complete.

Delivered in 0C: `mysqldump` backups with checksums and a manifest, checksum
verification, health-first GFS pruning that never removes the last good copy,
an automated restore drill that proves a backup is restorable, eleven health
checks with plain-language remedies, `/up`, and a supervised schedule.

**Deferred to 0C-b, which needs a UI shell to exist first:** the installer,
local TLS, and the Blade health page. `08-operations` §1.2–1.5 remain
unimplemented and are required before a school can install this.

**Known gap:** backups written by this phase are **not encrypted**.
`08-operations` §3.5 requires encryption with an escrowed key, which depends on
the `APP_KEY` custody procedure that ships with the installer. Acceptable on a
development machine; **must be closed before any real school data exists.**

Next: a thin end-to-end slice — login, an application shell, and one record
created and listed — so the architecture is proven through every layer.

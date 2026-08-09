# Overnight autonomous build run — 2026-08-09

Operator mandate: build all remaining phases autonomously. After each phase: run migrations, integrate (full suite + PHPStan), merge/commit, document, push, move to next phase.

## Phase order and status
- [x] Phase 6 (Fees) — finish workflow `wf_e5140e2d-d23` (fix → integrate → verify → document)
- [ ] Phase 5 (Procurement & Tax full) — plan: `docs/plans/phase-05.md`, migrations `2026_08_09_2500xx`
- [ ] Phase 7 (Operations: rollover + licensing) — plan: `docs/plans/phase-07.md`, **migrations renumbered to `2026_08_09_2550xx`** (planner's 2500xx collides with Phase 5)
- [ ] Phase 8 (Attendance/Timetable/Discipline/Promotion) — plan: `docs/plans/phase-08.md`, migrations `2026_08_09_2600xx`
- [ ] Phase 9 (Assets/Inventory/Library) — plan pending, use series `2026_08_09_2700xx`
- [ ] Phase 10 (Welfare) — plan pending, series `2026_08_09_2800xx`
- [ ] Phase 11 (HR/Payroll CNPS/IRPP) — plan pending, series `2026_08_09_2900xx`
- [ ] Phase 12–13 (Portals/API + Documents/PDFs) — plan pending, series `2026_08_09_3000xx`

## Per-phase workflow shape
5 parallel builders (per plan scopes, test DBs opeschool_test_f1..f5, exact-path git add) → integrator (solo full suite on opeschool_test + repo-wide PHPStan + dev-DB migrate + seed + SKIP_REMOTE_FONTS=1 npm run build) → auditors (boundaries/migrations/suite/phpstan) → documenter (HANDOVER.md + docs/BUILD-LOG.md) → push.

## Environment (Linux sandbox)
DB user opes/opes @127.0.0.1, MySQL 8.0. Databases: opeschool (dev), opeschool_test, opeschool_test_f1..f5. PHPStan: `php vendor/bin/phpstan analyse --memory-limit=1G`. Never two suites on one DB. Branch: `claude/handoff-document-review-7ojy23` (push here only).

## MIGRATION SERIES RENUMBERING (authoritative — planners collided on 2500xx)
Builder agents MUST use these series regardless of what the plan file says:
- Phase 5: `2026_08_09_2500xx` (as planned)
- Phase 7: `2026_08_09_2550xx` (plan says 2500xx → renumber 250001→255001 etc.)
- Phase 8: `2026_08_09_2600xx` (as planned)
- Phase 9: `2026_08_09_2700xx` (plan says 2500xx → renumber)
- Phase 10: `2026_08_09_2800xx` (plan says 2500xx → renumber)
- Phase 11: `2026_08_09_2900xx` (plan says 2500xx → renumber)
- Phase 12: `2026_08_09_3000xx` (plan says 250xxx → renumber); Phase 13: `2026_08_09_3100xx` (plan says 260xxx → renumber; do NOT collide with Phase 8's 2600xx)

# Guardian Mobile App (Expo) — Slice G

The parent-facing app for the OPES School platform. Consumes the guardian
surface documented in `docs/specs/2026-08-11-guardian-mobile-api-v1.md` and
`docs/api/openapi.yaml` — Slices A–F, which are complete and tested.

## State of this build — read this first

**This is a partial build and the parts are clearly separable.** What follows
is exact, so the next session does not have to re-derive it.

### Complete and reviewable

| Area | Files |
|---|---|
| Design tokens (the locked palette/radii/type/shadow from the 81 PNGs) | `src/theme/` |
| API client — envelope decoding, error taxonomy, read cache | `src/api/client.ts` |
| Typed wire shapes for every documented operation | `src/api/types.ts`, `src/api/endpoints.ts` |
| Token storage in the OS keystore, read cache, offline write outbox | `src/storage/` |
| Session/child selection + the capability rendering contract | `src/state/session.tsx` |
| Bilingual en/fr with money and date formatting | `src/i18n/` |
| Component kit (cards, chips, tiles, rings, rows, forms, states) | `src/components/primitives.tsx` |
| App chrome (green header + gold curve, child strip, tab strip, both bottom navs) | `src/components/chrome.tsx` |
| Loading / denied / not-found / offline handling, written once | `src/components/useScreenData.tsx` |
| Routing + `opes://` deep-link mapping | `src/navigation.ts`, `app/` |

### Screens built — 22 of 66

`SplashScreen`, `WelcomeOnboarding`, `LoginWelcomeBack`, `ForgotPasswordReset`,
`VerifyYourAccountOtp`, `ParentDashboard`, `MyChildren`, `ChildOverview`,
`ChildProfile`, `ResultsOverview`, `AcademicOverview`, `SubjectResults`,
`Attendance`, `BehaviourDiscipline`, `FeesDashboard`, `OutstandingBalance`,
`FeeStructureBreakdown`, `MakePayment`, `PaymentReceipt`,
`PaymentHistoryReceipts`, `ChildDocuments`, `HealthOverview`, `MessagesInbox`,
`MessageChatClassTeacher`, `Notifications`, `SchoolAnnouncements`,
`GlobalSearch`, `ParentProfile`.

### Screens NOT built — the remaining 44

The 81 PNGs in `mobile/` reduce to **66 distinct screens** (11 groups are
byte-identical duplicates; `md5sum *.png | sort | uniq -w32 -d` reproduces the
list). The ones above are done; the rest are not started:

`AcademicPerformanceAnalytics`, `AccountSettings`, `ActivityDetails`,
`Assignments`, `BulletinDePaiePayslip`, `BulletinScolaireReportCard` (+`-2`),
`ChildDocumentsMain`, `ChildOverview2`, `DigitalSchoolIdChildId` (+`Secure`),
`EmergencyImportantContacts` (+`-2`, `-3`), `ExcursionsTrips`, `GlobalSearch2`,
`HealthId`, `HealthOverview2`, `HelpSupport`,
`ImmunizationVaccinationRecords` (+`-2`), `LoginWelcomeBack2`,
`MedicalDocuments`, `MedicalHistory`, `NotificationPreferences`,
`OfficialFeesReceipt`, `OpesHealthId`, `ParentDashboard2`,
`PaymentMethodSelection`, `PaymentProcessing`, `ReportCardViewer`,
`SchoolActivities`, `SchoolInformation`, `Security`, `SportsEvents`,
`TeacherSchoolContact`, `TermSequenceHistory`, `TranscriptViewer`,
plus the duplicate-group re-export files.

Nothing about the foundation blocks them: each is a composition of the existing
kit against an endpoint that already exists. `FeesDashboard` and
`BehaviourDiscipline` are the best models to copy — they show, respectively, a
multi-section data screen and a screen where three capabilities have to be kept
visibly apart.

### Never run

**`npm install` has not been run and the app has not been launched, type-checked
or rendered.** There is no `node_modules`, no lockfile, and no Expo project
registration. Dependency versions in `package.json` were chosen to be mutually
consistent for Expo SDK 53 but are unverified against the registry. Treat the
first `npx expo start` as a debugging session, not a smoke test.

### Fidelity is faithful, not proven

The tokens were read off the reference PNGs by eye. **The rendered output has
never been diffed against those PNGs** — that needs a simulator and a
screenshot harness this environment does not have. The previous session's
handover raised exactly this and offered two options; this build took option
(a) (build to the token system faithfully) because the user asked for the
screens. So: "pixel-perfect" is *not* a claim this build can substantiate, and
nobody should repeat it upstream until option (b) exists.

Two specific places the PNGs imply something the tokens do not cover:

- the **crest** is rendered as a bordered box with an `H`, not the real
  laurel-and-crown artwork — there is no asset for it in `mobile/`;
- the **decorative background illustrations** on the auth screens (the campus,
  the faint subject glyphs) are absent for the same reason.

## Running it

```bash
cd mobile/app && npm install && npx expo start
```

Point the app at your API in `app.json` → `expo.extra.apiBaseUrl`. The default
`http://10.0.2.2:8000/api/v1` is the Android emulator's route to the host's
`php artisan serve`; use your LAN IP for a physical device.

## The rules this app follows

1. **The server decides; the app renders.** `capabilities` on a child is a
   rendering contract — it hides a tile — and never a permission. Every screen's
   data comes from an endpoint that re-checks, so a stale capability list costs
   a wasted request, never a leak.
2. **A 403 is an answer, not an error.** "Your school has not shared this with
   you" is a true statement about how this guardian's link is configured.
   Rendering it as a crash teaches parents to phone the office about a working
   system. `useScreenData` enforces the distinction in one place.
3. **Absent, not zero.** The dashboard omits a tile whose capability is missing.
   A zero would tell a parent their balance is nothing when the truth is that
   fees are not shared with them.
4. **Money is minor units + currency, never a float** — and XAF has no
   centimes, so `formatMoney` does not divide it by 100.
5. **Idempotency keys are stamped when a write is QUEUED, not when it is sent.**
   That is the whole reason the outbox is safe: a message that reached the
   server but whose response was lost does not double-post on retry.
6. **Nothing medical and no document bytes are cached to disk.** The server
   sends `Cache-Control: private, no-store` on those; writing them to
   AsyncStorage anyway would make the header theatre.
7. **The sign-out clears the cache.** A shared family phone is the normal case.
8. **One error message on sign-in.** The server answers every credential
   failure identically so the screen cannot be used to discover whether a
   parent has an account here; the client must not undo that by guessing a
   friendlier message.

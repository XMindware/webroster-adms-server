# webroster-adms-server: Multi-Agent Development Plan

ANov 13, 2026 · @Xavier

## Summary

webroster-adms-server works and is under active development, but it runs on an end-of-life framework and has open security gaps around biometric data. Fix those first, then refactor, redesign and document with a six-role Ruflo swarm.

The server speaks ZKTeco's ADMS PUSH protocol to biometric time clocks. It stores attendance punches plus fingerprint and face templates, and syncs employees and punches with WebRoster per office (oficina) and company (empresa). The last merge (PR #98, face-pull follow-ups) landed today.

Three things set the order of this plan:

1. **Security hotfixes come first.** Account sign-up is reachable without logging in. The device endpoints accept any serial number. Biometric payloads are written to log files. These are quick fixes with outsized risk.
2. **The platform upgrade has a hard deadline.** The app is on Laravel 10, whose security fixes ended February 4, 2025, and on PHP 8.2, which loses security support on December 31, 2026. Target PHP 8.4 + Laravel 13.
3. **Refactor, frontend and docs follow.** Once the code is safe and supported, the swarm splits the two 700-line controllers, raises test coverage, rebuilds the admin UI and writes the docs.

Each workstream below lists its owner agent, deliverables and the gate it must pass. The repo setup section has the files and commands to start the swarm.

## Current state

The core protocol and biometric logic is solid and partly tested. Everything around it (platform, auth, UI, ops, docs) still looks like the open-source fork it started from (saifulcoder/adms-server-ZKTeco). Audited at commit `b08144e`.

| Area | What's there today |
| --- | --- |
| Stack | PHP 8.2, Laravel 10, Sanctum 3, MySQL 8 (SQLite in tests), Blade views |
| Device protocol | `/iclock/*` routes in `iclockController` (719 lines): handshake, record push, command polling, command acks, biometric queries, log upload |
| Admin UI | 23 Blade views; `DeviceController` (716 lines) handles devices, offices, attendance, fingerprints and the monitor screen |
| Domain | 14 models (Device, Attendance, Agente, Oficina, Fingerprint/Face templates, Command...), 12 services, 37 migrations |
| WebRoster integration | Outbound: punches and employee sync per office, using a per-office token. Inbound: `/api/empresa/devices`, authenticated with hashed per-empresa API keys (well built) |
| Scheduled jobs | Attendance sync and device-status check every minute; offset monitor every 5 minutes; station employee sync daily at 08:00 |
| Tests | 10 test files, about 72 tests, concentrated on fingerprint pull (35) and the biometric parser (14) |
| CI | GitHub Actions: PHPUnit on PHP 8.2 / SQLite. No linting, static analysis or dependency audit |
| Frontend | Bootstrap 5.0, jQuery 3.5.1, DataTables 1.11 and Font Awesome 5, all from public CDNs. Vite is configured but unused by the layout. English, Spanish and Indonesian strings |
| Deploy | Dockerfile + docker-compose (PHP-FPM, Nginx, MySQL, phpMyAdmin) |
| Docs | README is still the upstream fork's, plus a WebRoster API section. No architecture, protocol or ops docs. No CLAUDE.md |

**Gaps that drive this plan:**

- Laravel 10 is past end of life and PHP 8.2 is weeks from it.
- Several security issues on the paths that touch biometric data (Security workstream lists them).
- Two 700-line controllers mix protocol handling, UI and business rules, so changes are risky.
- No user roles: every account can wipe fingerprints from devices.
- `device_logs` and log files store full request payloads with no retention policy.
- The admin UI depends on outdated CDN assets and has no shared components.

## Target architecture

&#91;embedded content: target architecture · 3 entry points, 4 layers\]

Clocks, admins and WebRoster each get their own entry point with its own authentication. Heavy ingest and WebRoster sync move to a queue, so a clock's request returns quickly. The highlighted device gateway is the biggest change from today.

## Agent team and orchestration

&#91;embedded content: agent team · coordinator, 6 specialists, gates\]

You set each phase's goal; the Ruflo coordinator breaks it into tasks for the specialist agents. Every pull request must pass the quality gates, and nothing reaches `staging` or `main` without your merge. Use a hierarchical topology: Ruflo recommends it for coding work because the coordinator keeps agents from drifting off task.

## Architecture workstream

**Owner:** architect agent. **Output:** one ADR (architecture decision record) per decision in `docs/adr/`, approved by you before coding agents start on it.

| ADR | Decision to make | Proposed direction |
| --- | --- | --- |
| 001 | Upgrade path | PHP 8.2 → 8.4 and Laravel 10 → 11 → 12 → 13, one major per PR. Keep the current folder layout during the upgrade; adopting Laravel 11's slimmer structure is optional and later. |
| 002 | Device protocol isolation | Move `/iclock/*` out of `routes/web.php` into `routes/device.php` with a minimal middleware group: no session, cookies or CSRF. Add a device-auth middleware that admits only registered, enabled serial numbers, plus rate limiting. |
| 003 | Split the large controllers | `iclockController` → handshake, record-ingest, command-queue and query-data handlers. `DeviceController` → devices, offices, attendance, biometrics and monitor controllers. Protocol responses must stay byte-identical. |
| 004 | Background work | Move biometric ingest and WebRoster pushes onto a real queue (Redis or the database driver; today `QUEUE_CONNECTION=sync`) so clocks get fast responses. |
| 005 | Data retention | `device_logs`, `log_entries` and `finger_log` become prunable models with a retention window. Logs keep metadata only, never payloads. |
| 006 | Authorization | Roles (admin, operator, viewer) via Laravel policies, optionally scoped per empresa. |
| 007 | Biometric data at rest | Encrypt fingerprint and face templates and the per-office WebRoster tokens with Laravel encrypted casts. Measure the ingest cost first. |

The architect also reviews every PR that crosses a module boundary and keeps `docs/architecture.md` current.

## Security workstream

**Owner:** security-auditor agent. It verifies each finding below, writes a failing regression test, then hands the fix to a coder. Severity is my assessment from reading the code; the agent should confirm each one.

| ID | Severity | Finding | Where | Fix |
| --- | --- | --- | --- | --- |
| S1 | Critical | Anyone can create an account: `POST /registration-user` has no auth middleware, and every account is effectively an admin | `routes/web.php`, `AuthController` | Remove public sign-up; create users with an artisan command or an admin-only screen |
| S2 | High | Device endpoints accept any serial number and auto-create devices (`updateOrInsert`), so a spoofed clock can inject punches and biometric data | `iclockController` | Admit only registered, enabled serials; optional IP allowlist per office; rate limit |
| S3 | High | Full request bodies (biometric templates) and headers are written to log files and `device_logs` | `RequestLogger`, `Log::info($request->all())` calls, `device_logs.data` | Log metadata only; purge existing logs; retention policy |
| S4 | High | Laravel 10 and PHP 8.2 are end of life or nearly so, so new advisories won't be patched | `composer.json`, `Dockerfile` | Platform upgrade (ADR 001) |
| S5 | Medium | State-changing actions use GET (delete device or office, restart, populate), so a link or image tag can trigger them | `routes/web.php` | POST/DELETE with CSRF and a confirmation step |
| S6 | Medium | Stored XSS: the employee name is printed unescaped with `{!! !!}` | `devices/attendance.blade.php` | Use `{{ }}` and audit other raw output |
| S7 | Medium | No authorization: any user can wipe fingerprints or restart clocks | All admin routes | Roles and policies (ADR 006) |
| S8 | Medium | Unauthenticated extras: `/api/test` exposes the latest error, `/iclock/test` exists, and `/iclock/upload-log` takes any file with an unchecked `sn` | `routes/web.php`, `iclockController` | Remove, or require device auth plus size limits |
| S9 | Medium | Login has no rate limiting, and passwords are capped at 12 characters | `AuthController` | Throttle; allow long passwords; optional 2FA |
| S10 | Medium | docker-compose publishes MySQL and phpMyAdmin with default credentials; the image installs dev dependencies | `docker-compose.yml`, `Dockerfile` | Separate production compose; no public DB or phpMyAdmin; `composer install --no-dev` |
| S11 | Low | WebRoster per-office tokens stored in plain text | `oficinas.token` | Encrypted cast (ADR 007) |
| S12 | Low | `/api/agentes/bulk-upsert` is unauthenticated and points to a method that doesn't exist | `routes/api.php` | Remove, or implement behind `empresa.key` |
| S13 | Low | Outdated CDN scripts (jQuery 3.5.1) loaded without integrity hashes | `layouts/app.blade.php` | Bundle with Vite (Frontend workstream) |

**Add to CI:** `composer audit`, `npm audit`, secret scanning (for example gitleaks) and Larastan.

**Gate:** no Critical or High finding open before the upgrade branch merges. Any PR that touches routes, auth, `/iclock` or biometric data needs the security agent's sign-off.

## Coding workstream

**Owners:** two backend-coder agents, each working in its own git worktree on one task per branch. PRs target `staging`; you promote `staging` to `main`, the flow CI already runs on.

**Standards** (written into CLAUDE.md so every agent follows them):

- Formatting with Laravel Pint (already a dev dependency), enforced in CI.
- Static analysis with Larastan, starting at level 5 and raising it as the code is cleaned up.
- Validation in Form Request classes; thin controllers that call services.
- `declare(strict_types=1)` and typed properties in new or touched files.
- Never log request bodies, templates, tokens or keys. Log serial number, endpoint, record counts and outcome.
- Device responses (`/iclock/*`) are a contract: any change needs a contract test showing the exact bytes before and after.
- No new CDN dependencies; frontend assets go through Vite.

**Backlog, in order:**

| # | Item | Phase | Depends on |
| --- | --- | --- | --- |
| 1 | Security fixes S1, S2, S3, S5, S6, S8, S9, S12 | 1 | Security tests written first |
| 2 | Production-safe Dockerfile and compose (S10) | 1 | — |
| 3 | PHP 8.4 compatibility pass (deprecations, dependency bumps) | 2 | Characterization tests |
| 4 | Laravel 11, then 12, then 13; Sanctum and DataTables package bumps | 2 | Item 3 |
| 5 | `routes/device.php` + device-auth middleware (ADR 002) | 3 | Item 4 |
| 6 | Split `iclockController` into handlers (ADR 003) | 3 | Contract tests |
| 7 | Split `DeviceController`; `fill or delete the empty OficinasController and PushChecadasController stubs` | 3 | Item 4 |
| 8 | Queue worker for ingest and WebRoster sync (ADR 004) | 3 | Item 6 |
| 9 | Roles and policies (ADR 006, S7) | 3 | Item 7 |
| 10 | Prunable logs and encrypted columns (ADR 005, 007, S11) | 3 | Item 8 |

## Testing workstream

**Owner:** test-engineer agent. Its first job is a safety net: tests that pin down today's behavior before the upgrade and refactor touch anything.

1. **Device contract tests.** Capture real request bodies from your clocks (with templates replaced by fake data), save them as fixtures per firmware/push version, and assert exact responses for handshake, `cdata` (ATTLOG, OPLOG, biometric data), `getrequest`, `devicecmd` and `querydata`.
2. **Characterization tests** for every admin route in `DeviceController` and `AgentesController`, before they are split.
3. **Access tests** for every route: guest is redirected, viewer gets 403 where needed, admin succeeds. They become the proof that S1, S5 and S7 are fixed.
4. **Security regression tests:** one per finding, written failing first.
5. **WebRoster integration** faked with `Http::fake`, covering timeouts, 401s and partial failures.

**CI changes:**

- Add a MySQL 8 job next to SQLite. The activity chart uses MySQL's `DATE_FORMAT`, so that path can't run on SQLite.
- Run on PHP 8.2 and 8.4 while the upgrade is in progress, then 8.4 only.
- Turn on coverage (pcov) and fail a PR that lowers it.

**Proposed targets:** 80% line coverage on `app/Services` and the protocol handlers, 60% overall by the end of Phase 3.

**Before each release:** run it for a week on staging with at least one real clock of each model before promoting to `main`.

## Frontend design workstream

**Owner:** frontend-designer agent. Goal: an admin UI that shows the health of the clock fleet at a glance, built from shared components and bundled locally.

**What's there:** 23 Blade views on one layout. That layout loads Bootstrap CSS 5.0.1 but Bootstrap JS 5.0.2, plus jQuery 3.5.1, DataTables 1.11.4 and Font Awesome 5, all from public CDNs. The monitor page alone is 355 lines. Strings exist in English, Spanish and Indonesian.

**Plan:**

1. **UI audit:** screenshots of every screen with problems marked (layout, empty states, error states, mobile, contrast).
2. **Design tokens** in `resources/sass/_variables.scss`: colors, spacing, type, and status colors for online / offline / alert / pending.
3. **Vite bundle:** Bootstrap 5.3 (adds light and dark mode), DataTables 2, icons. Remove every CDN tag, which also fixes S13.
4. **Blade components:** layout, page header, card, status badge, data table, flash message, and a confirm-delete form that sends POST/DELETE (supports S5).
5. **Mockups first, then build**, in this order: device monitor (online/offline per office, last punch, pending commands), device detail, attendance with filters and the fix flow, employee sync, fingerprints and faces, offices, login. Add a user-management screen to replace public sign-up.
6. **Accessibility:** WCAG 2.2 AA contrast, keyboard navigation, form labels; usable on a tablet.

**Gate:** you approve the mockups before implementation; Lighthouse accessibility score of 90 or higher on each rebuilt screen.

## Documentation workstream

**Owner:** docs-writer agent. It drafts the set below, then checks every PR: code that changes behavior without a docs change goes back to its author. The comments in `config/adms.php` are already excellent protocol notes; harvest them.

| File | Contents | When |
| --- | --- | --- |
| `README.md` | Rewrite: what it is, quickstart with Docker, configuration, links to the rest | Phase 0 |
| `CLAUDE.md` | Project rules for agents (see Repo setup) | Phase 0 |
| `docs/security.md` | Threat model, how biometric data is handled, vulnerability reporting | Phase 1 |
| `docs/adr/*.md` | One file per architecture decision | Phases 1–3 |
| `docs/adms-protocol.md` | Endpoints, TransFlag, push versions, command queue, firmware quirks, biometric types | Phase 2 |
| `docs/webroster-integration.md` | Outbound sync with per-office tokens; inbound empresa API and key management | Phase 2 |
| `docs/api/openapi.yaml` | OpenAPI spec for `/api/empresa/*`; replaces the Postman collections | Phase 2 |
| `docs/architecture.md` | Components, data flow, the diagram from this plan | Phase 3 |
| `docs/operations.md` | Deploy, scheduler and queue worker, backups, log retention, monitoring, onboarding a new clock | Phase 3 |
| `docs/design-system.md` | Tokens, components, screen patterns | Phase 4 |
| `CHANGELOG.md`, `CONTRIBUTING.md` | Release notes per merge to `main`; branch and PR rules | Phase 0, then ongoing |

## Roadmap

&#91;embedded content: roadmap · 6 phases, Nov 2026 to Feb 2027\]

The dates are a proposal; adjust them to your availability for reviews. Each phase ends at a gate you sign off:

| Phase | Gate to pass |
| --- | --- |
| 0. Setup + baseline | CLAUDE.md, agents and plan committed; Pint, Larastan and `composer audit` running in CI; baseline tests green |
| 1. Security hotfixes | S1–S3, S5, S6, S8, S9, S12 fixed with regression tests; security agent re-audit finds no Critical or High |
| 2. PHP 8.4 + Laravel 13 | Full suite green on PHP 8.4 and MySQL; one week on staging with real clocks; deployed before Dec 31 |
| 3. Refactor + tests | ADRs 002–007 implemented; contract tests unchanged; coverage targets met |
| 4. Frontend redesign | Mockups approved; no CDN assets; accessibility score 90+ |
| 5. Docs | Every file in the docs set exists and matches the code |

## Repo setup for the swarm

Phase 0 adds the plan, the project rules and six agent definitions to the repo. After that, each phase starts with a single command.

**1. Clone and branch** (in Ubuntu):

```bash
cd ~/code && git clone git@github.com:XMindware/webroster-adms-server.git && cd webroster-adms-server
git checkout staging && git checkout -b chore/agent-setup
```

**2. PHP 8.2 and 8.4 side by side**, so agents can run tests on both during the upgrade:

```bash
sudo add-apt-repository -y ppa:ondrej/php && sudo apt update
sudo apt install -y php8.2-cli php8.4-cli php8.2-{mbstring,xml,sqlite3,mysql,curl,zip,bcmath,intl} php8.4-{mbstring,xml,sqlite3,mysql,curl,zip,bcmath,intl} composer
composer install && cp .env.example .env && php artisan key:generate
vendor/bin/phpunit   # baseline: should be green before any agent starts
```

**3. Ruflo:** `npx ruflo@latest init`, with the same choices as in ruflo-lab. Commit `.claude/`, `CLAUDE.md` and `.mcp.json`; add `.claude-flow/data/` and `.claude-flow/logs/` to `.gitignore`.

**4. Project rules.** Append to the generated `CLAUDE.md`:

```markdown
# webroster-adms-server
Laravel server for ZKTeco ADMS PUSH clocks, synced with WebRoster per oficina/empresa.
The plan is in docs/plan.md. Work only on the phase and task you are given.

## Commands
- Tests: vendor/bin/phpunit   (also run with php8.4 during the upgrade)
- Format: vendor/bin/pint   Static analysis: vendor/bin/phpstan analyse

## Rules
- /iclock/* responses are a device contract: change them only with a contract test.
- Never log request bodies, biometric templates, tokens or API keys.
- Every change needs tests; security fixes start with a failing test.
- No state-changing GET routes. No unescaped {!! !!} output of user or device data.
- Frontend assets go through Vite; no new CDN tags.
- Update the docs your change affects in the same PR.
- Branch from staging, one task per branch, PR back to staging. Never push to main.
```

**5. Agent definitions** in `.claude/agents/`, one Markdown file each:

| File | Focus | Can edit |
| --- | --- | --- |
| `architect.md` | ADRs, upgrade path, module boundaries, PR review | `docs/adr/`, `docs/architecture.md` |
| `security-auditor.md` | Findings S1–S13, threat model, sign-off | Tests and `docs/security.md` only |
| `backend-coder.md` | Implement backlog items | `app/`, `routes/`, `config/`, `database/` |
| `test-engineer.md` | Contract, characterization and access tests; CI | `tests/`, `.github/workflows/` |
| `frontend-designer.md` | Audit, tokens, components, screens | `resources/` |
| `docs-writer.md` | Docs set; docs check on every PR | `docs/`, `README.md`, `CHANGELOG.md` |

Example, `.claude/agents/security-auditor.md`:

```markdown
---
name: security-auditor
description: Reviews webroster-adms-server for security issues. Use for any change to routes, auth, /iclock endpoints or biometric data.
---
You are the security auditor for a Laravel server that stores biometric templates.
For each finding in docs/plan.md (S1-S13): confirm it in the code, write a failing
regression test, and hand the fix to backend-coder. Review PRs that touch routes,
middleware, AuthController, iclockController or biometric models, and reply
APPROVE or BLOCK with reasons. Do not edit app code yourself.
```

**6. Run a phase.** Commit this plan as `docs/plan.md`, then start the phase with a queen-led swarm:

```bash
npx ruflo@latest swarm init --topology hierarchical --max-agents 6
npx claude-flow hive-mind spawn "Phase 1 of docs/plan.md: fix S1, S2, S3, S5, S6, S8, S9, S12. Tests first, one branch per finding, security-auditor must approve each PR." --claude
```

For parallel coders, give each its own worktree: `git worktree add ../wr-s2 -b fix/s2-device-auth staging`.

**Guardrails:** keep Claude Code's sandbox on; never give agents production credentials, the production database or SSH access; agents open PRs, you merge.

## Risks and open questions

The biggest risk is breaking clocks in the field. Every change on the device path ships behind contract tests and a staged rollout.

| Risk | Mitigation |
| --- | --- |
| The upgrade or refactor changes a device response and some firmware stops syncing | Byte-exact contract tests from real payloads; roll out to a few serials first, the same way `ADMS_PUSH_PROT_VER_DEVICES` already canaries protocol changes |
| Enforcing registered serials (S2) locks out legitimate clocks | Before enforcing, export the `devices` table, confirm every live serial with you, and run in log-only mode for a week |
| Agents make plausible but wrong changes in code they don't understand | Small PRs, tests first, architect and security review, you merge |
| Secrets or biometric data reach the agents | No production `.env`, database dumps or log files in the repo or the sandbox; fixtures use fake templates |
| Plan usage limits slow the swarm | Max 6 agents; run phases one after another, not all at once |

**Open questions for you:**

- [ ] Which clock models and firmware / push versions are in the field, and is there a staging server with real clocks?
- [ ] Who uses the admin UI, and which roles do they need?
- [ ] Does anyone rely on public sign-up today?
- [ ] Is the Indonesian translation needed, or is it left over from the upstream project?
- [ ] Where does production run (Docker on a VPS, shared hosting...), and who deploys?
- [ ] What retention period and consent rules apply to biometric data for your clients? Biometrics are sensitive personal data; confirm obligations under Mexican data-protection law with counsel.

## Sources

- [XMindware/webroster-adms-server](https://github.com/XMindware/webroster-adms-server): code audited at commit b08144e
- [Laravel release notes: support policy](https://laravel.com/framework/docs/12.x/releases): Laravel 10 security fixes ended February 4, 2025
- [Laravel News: Laravel release cycle](https://laravel-news.com/laravel-release-process): Laravel 13 released March 17, 2026; requires PHP 8.3+
- [HeroDevs: PHP end-of-life dates](https://www.herodevs.com/blog-posts/php-end-of-life-dates-support-timeline-for-every-version-2026): PHP 8.2 end of life December 31, 2026
- [Zend: PHP versions](https://zend.com/resources/php-versions): PHP 8.4 supported until December 31, 2028

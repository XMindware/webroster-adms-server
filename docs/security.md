# Security

This file tracks the security state of webroster-adms-server: what Phase 1 fixed, what operators must do after deploying it, and the follow-ups that are still open. Findings S1–S13 are defined in [`plan.md`](plan.md#security-workstream).

Severities below are assessments from reading the code, not from an external audit.

## Phase 1 fixes

| ID | Finding | Fix | PR |
| --- | --- | --- | --- |
| S1 | Public sign-up created admin accounts | Sign-up routes removed; accounts are created with `php artisan user:create` | #100 |
| S2 | Device endpoints accepted any serial number | `device.auth` middleware admits only registered, enabled serials; per-serial and per-IP rate limits. **Ships in `log` mode**, see [S2 rollout](#s2-rollout) | #107 |
| S3 | Request bodies (biometric templates) and headers were logged | Device requests are logged as metadata only; `logs:scrub-payloads` cleans existing rows | #106 |
| S5 | State-changing actions used GET | POST/DELETE forms with CSRF and confirmation | #104 |
| S6 | Stored XSS through unescaped output | All `{!! !!}` output removed; `public_url` limited to `http(s)` | #103 |
| S8 | Unauthenticated debug and upload endpoints | `/iclock/test` removed; `/api/test` returns status only and is throttled; `upload-log` checks the device and caps size and lines | #105 |
| S9 | No login throttling; passwords capped at 12 characters | 5 failures per email and IP, 20 attempts per IP per minute; passwords up to 1024 characters | #101 |
| S12 | Unauthenticated route to a missing method | Route removed | #102 |
| — | Clocks got 419 on `querydata` and `upload-log` (CSRF) | Device routes exempt from CSRF, guarded by a contract test that runs with CSRF on | #108, #105 |

Every fix has a regression test in `tests/Feature/Security/` (CSRF: `tests/Feature/DeviceCsrfContractTest.php`). Each test failed before its fix.

## After deploying Phase 1

1. **Run the migration.** `php artisan migrate` adds `devices.enabled`. Existing devices default to enabled.
2. **Create admin accounts on the command line.** Public sign-up is gone: `php artisan user:create admin@example.com --name="Admin"` prompts for a password of at least 12 characters.
3. **Remove biometric payloads that were logged before the fix.**
   - Try it on a copy of the database first; it can't be undone.
   - `php artisan logs:scrub-payloads` rewrites `device_log` and `finger_log` rows to metadata only.
   - `php artisan logs:scrub-payloads --with-files` also deletes `storage/logs/request_response*.log`, `laravel*.log` and `error*.log`.
   - The command can't reach copies elsewhere: database backups, rotated or shipped log files, and anything sent to a log service. Those still hold templates and need their own clean-up or expiry.
4. **Roll out S2** as described below.

### S2 rollout

`ADMS_DEVICE_AUTH` (see `config/adms.php` and the README) controls what happens to unknown or disabled serial numbers:

| Mode | Effect |
| --- | --- |
| `log` (default) | Logged once per serial per hour as `device auth: unregistered serial number` (or `disabled`, `conflicting`) and **still served** |
| `enforce` | `403 ERROR: Device not registered`; nothing is stored |

1. Deploy with `log` and leave it for a week.
2. Compare the warned serials with the `devices` table. Register the real clocks. Delete devices that unknown callers auto-created.
3. Set `ADMS_DEVICE_AUTH=enforce` and run `php artisan config:clear`.
4. Watch for 403s from real clocks for a few days. To roll back, set `log` again.

Until step 3, S2 is only partly fixed: see F2.

## Open follow-ups

| # | Severity | Issue | Where | Proposed fix |
| --- | --- | --- | --- | --- |
| F1 | Medium | The handshake returns the full exception, stack trace included, to the caller (`return "ERROR: ".$e`) and stores it in `error_log`. Anyone who can reach `/iclock/cdata` can trigger it and read file paths, SQL and messages. | `iclockController::handshake`, `catch` block | Return a fixed `ERROR` line and keep the detail in `report()` only. This is a device response, so it needs a contract test. |
| F2 | Medium | Until `ADMS_DEVICE_AUTH=enforce`, any serial is served: unknown or disabled clocks can still push punches and templates. Even in `enforce` mode a serial number is a weak credential, because it is printed on the clock and sent in the clear. | `AuthenticateDevice`, `config/adms.php` | Finish the S2 rollout. Then add the optional per-office IP allowlist from the plan. |
| F3 | Medium | `upload-log` checks that the serial is registered but not that it is enabled. Before #107 merges it has no rate limit, so a known serial can post up to 10,000 rows per request, as often as it likes. | `iclockController::uploadLog` | Covered once #107 is merged and `enforce` is on. Optionally also check `enabled` in the controller. |
| F4 | Medium | WebRoster responses are logged with their contents. `GetStationAgentsService` logs the response body (the employee roster) at debug and error level. `UpdateChecadaService` logs the decoded response at info level. That puts personal data in log files. | `app/Services/GetStationAgentsService.php`, `app/Services/UpdateChecadaService.php` | Log status, size and a request ID only. Add a test like `PayloadLoggingTest`. |
| F5 | Low | In `log` mode, a request with a non-scalar serial (for example `SN[]=x`) is served with its serial pinned to the marker value `"\0invalid"`, which can be auto-created as a device. | `AuthenticateDevice::serialCandidates`, `pinSerial` | Reject `conflicting` and non-scalar serials even in `log` mode; no real clock sends them. |
| F6 | Low | `/api/test` is still public. It now only says whether an error was logged, and when. | `routes/web.php`, `iclockController::quickStatus` | Find out who calls it. Remove it, or move it behind `auth` or `empresa.key`. |
| F7 | Low | Nothing expires: `device_log`, `finger_log`, `error_log`, `log_entries` and the log files grow forever. Older rows may hold data that predates S3. | Database, `storage/logs` | Prunable models and log rotation (ADR 005). Retention periods are yours to set, see the open questions in the plan. |
| F8 | Low | `.env.example` doesn't list the new settings `ADMS_DEVICE_AUTH`, `ADMS_DEVICE_RATE_LIMIT` and `ADMS_DEVICE_RATE_LIMIT_PER_IP`. | `.env.example` | Add them with their defaults. |

Note for anyone adding device routes: Laravel skips CSRF checks in tests. A new POST route under `iclock/` must be added to `VerifyCsrfToken::$except`; `DeviceCsrfContractTest` fails until it is.

## Not in Phase 1

These findings from the plan are still open and scheduled for later phases: S4 (platform upgrade, ADR 001), S7 (roles and policies, ADR 006), S10 (production Docker setup), S11 (encrypted office tokens, ADR 007) and S13 (CDN scripts, replaced by the Vite bundle).

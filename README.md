# 🔐 ADMS (Attendance Device Management System)

ADMS is an attendance device management system for ZKTeco-compatible
terminals. Terminals push attendance punches, user records and fingerprint
templates to it over the iClock/ADMS protocol; the server stores them, queues
commands back to the devices, and can forward attendance on to other systems.
It is built on Laravel.

## ✨ Features

- 🕒 **Attendance ingest** — punches from every terminal, de-duplicated so a
  terminal replaying its log cannot inflate the table, plus a monitor for
  terminals whose clock has drifted.
- 👤 **Employees (agentes)** — pulled from the station API, pushed to devices,
  and purged when they leave.
- 🔒 **Fingerprint templates** — stored server-side and pulled from or pushed
  to terminals on demand.
- 📟 **Device management** — register, populate, restart, and see each
  terminal's last-seen status, model and activity log.
- 🏢 **Offices (oficinas)** — per-office timezone, station API URL and token.
- 🔔 **Webhooks** — forward each attendance batch to an external URL, signed
  with HMAC-SHA256 and logged with status code and duration.
- 🌐 **Three languages** — English, Spanish and Indonesian, switchable per
  session.
- 🧰 **Artisan commands** — sync employees, pull/push fingerprints, monitor
  clock drift, alert on offline devices, push attendance to Webroster.

## 📸 Screenshots

Device Connected
![Device connected — Biometric Devices list](docs/connected.png)
Device Status Monitor
![Device status monitor](docs/monitoring-device.png)
Attendance Recorded
![Attendance log with timestamps and discrepancies](docs/attendance.png)
Device Log
![Device request log showing getrequest/cdata calls](docs/log-perangkat.png)
Attendance Log
![Fingerprint upload log entries per device](docs/log-sidik-jari.png)
Multiple Offices
![Multiple Offices](docs/cabang.png)
Webhook Supports
![Webhook Supports](docs/webhook.png)

## 🖥️ Device Support

- Solutions X100C
- Any terminal speaking the ZKTeco iClock/ADMS push protocol. Development was
  validated against `iClock Proxy/1.09`, which speaks **HTTP/1.0** and does
  **not** follow redirects — see [Deployment notes](#-deployment-notes).

## 📦 Installation

### ⚙️ Prerequisites

Before you begin, ensure you have the following installed on your system:

-   PHP >= 8.2 (Laravel 12 requirement)
-   Composer 2.x
-   MySQL or any other supported database
-   Web server (Apache, Nginx, etc.)

> **Upgrading from Laravel 10?** The framework has been upgraded to
> **Laravel 12** (see [Framework version](#-framework-version)). The
> application skeleton is unchanged — Laravel 11/12 still support the
> classic Laravel 10 structure, so no `bootstrap/app.php` rewrite was
> needed. Only the dependency constraints and a handful of call sites
> changed. See [Bugs found during the upgrade](#-bugs-found-during-the-upgrade)
> for the pre-existing issues that surfaced.

### 📋 Steps

1. **Clone the repository**

    ```bash
    git clone https://github.com/XMindware/webroster-adms-server.git adms-server
    cd adms-server
    ```

2. **Install dependencies**

    ```bash
    composer install
    ```

3. **Copy the `.env` file**

    ```bash
    cp .env.example .env
    ```

4. **Generate application key**

    ```bash
    php artisan key:generate
    ```

5. **Configure the `.env` file**
   Open the `.env` file and set your database credentials and other environment variables:

    ```env
    DB_CONNECTION=mysql
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=adms
    DB_USERNAME=root
    DB_PASSWORD=
    ```

   Set `APP_URL` to the address the **terminals** will use. It has to be
   reachable from the device network over plain HTTP or HTTPS, and it must be
   the same host you configure on the terminal.

6. **Run the migrations**

    ```bash
    php artisan migrate
    ```

7. **Serve the application**
    ```bash
    php artisan serve
    ```

### 📡 Monitoring Device Status

You can monitor the status of devices by querying the `devices` table where the `online` field indicates the last time the device was online.

## 📟 Configuring a terminal

Point the terminal's ADMS / "server address" at the host in `APP_URL`. It will
then poll `/iclock/getrequest` and push to `/iclock/cdata` on its own; nothing
has to be configured per device on the server side beyond registering it.

The handshake response (`GET /iclock/cdata?SN=...&options=all`) tells the
terminal what to send and how often:

| Option | Value | Meaning |
| --- | --- | --- |
| `Stamp` | `9999` | Accept the terminal's whole backlog |
| `OpStamp` | unix timestamp | Must be an integer — a formatted date is rejected |
| `TransTimes` | `00:00;14:05` | Times of day to push |
| `TransInterval` | `4` | Minutes between pushes |
| `TransFlag` | `1111111000` | Which record types to upload (below) |
| `Realtime` | `1` | Push as punches happen |
| `Encrypt` | `0` | No payload encryption |

`TransFlag` is a ten-position mask: `1` AttLog, `2` OpLog, `3` AttPhoto,
`4` EnrollUser, `5` ChgUser, `6` EnrollFP, `7` ChgFP, `8` FPImage, `9` Face,
`10` UserPic. Positions 6 and 7 are what make a terminal upload a fingerprint
template as soon as it is enrolled — without them templates only arrive when
you ask for them explicitly. Positions 8-10 are off because fingerprint
images, face templates and user photos are large and would flood the server.

`TimeZone=` is deliberately **not** sent: the protocol expects an hour offset
there, not an IANA name, and the deployments this was validated against omit it
entirely.

### Application settings

Everything above is configurable without touching code — see `config/adms.php`.

| Variable | Default | Purpose |
| --- | --- | --- |
| `ADMS_TRANS_FLAG` | `1111111000` | Record types terminals upload |
| `ADMS_FINGER_IDS` | `0,1,2,3,4,5,6,7,8,9` | Finger slots queried when pulling a template |
| `ADMS_QUERY_USERINFO` | `true` | Also queue `DATA QUERY USERINFO` on a bulk pull |
| `ADMS_MAX_PULL_COMMANDS` | `2000` | Guard against queueing thousands of commands in one click |
| `ADMS_COMMANDS_PER_REQUEST` | `20` | Commands handed to a terminal per `/iclock/getrequest` |
| `ADMS_CLOCK_CORRECTION_COOLDOWN` | `30` | Minutes before another clock correction may be queued |
| `ADMS_WEBHOOK_TIMEOUT` | `5` | Seconds to wait for a webhook receiver |

## 🔔 Webhook delivery

Each device can have one webhook URL (menu **Webhook**). Every attendance batch
the server accepts is forwarded to it as `POST {"data": [ ...rows... ]}`.

Delivery never blocks the terminal. The reply to `/iclock/cdata` carries the
record count the terminal uses as its upload watermark, so holding that reply
back makes the terminal re-send the same batch. The POST therefore lives in a
queued job (`App\Jobs\SendWebhookJob`) instead of running in the request:

- With the default `QUEUE_CONNECTION=sync` there is no worker to hand the job
  to, so it is dispatched with `dispatchAfterResponse()` and runs in the
  terminate phase — after the terminal already has its reply, with no extra
  process to supervise.
- Point `QUEUE_CONNECTION` at a real driver (`database` or `redis`) and run
  `php artisan queue:work`, and the same job is pushed to the queue instead.
  That is also what makes **retries** work: a 5xx or a transport error is
  retried up to three times (after 30s, then 120s) before the job is marked
  failed. A 4xx is never retried — the receiver refused that payload and will
  refuse it again.

Every attempt writes one line to the `webhook` channel
(`storage/logs/webhook.log`) with the HTTP status code and how long the
receiver took:

```
[2026-09-23 03:10:00] webhook.INFO: webhook delivered {"url":"https://...","sn":"*************","records":2,"status":200,"duration_ms":84}
[2026-09-23 03:11:00] webhook.ERROR: webhook rejected {"url":"https://...","sn":"*************","records":2,"status":500,"duration_ms":31}
```

That log is the only place a failing receiver becomes visible: the terminal is
answered before the POST happens, so it can never report the failure itself.
A 4xx is logged as a warning, a 5xx or a transport error as an error.

### Verifying a delivery

Every webhook carries a signing secret, shown on its edit page and rotatable
there. Each request includes:

| Header | Value |
| --- | --- |
| `X-Webhook-Timestamp` | Unix seconds when the request was built |
| `X-Webhook-Signature` | `sha256=` + HMAC-SHA256 of `{timestamp}.{raw body}` keyed with the secret |

Compute the same HMAC over the **raw body** and compare with
`hash_equals()`. Reject timestamps older than a few minutes: the timestamp is
inside the signed material, so a captured request cannot be replayed later even
though its signature still verifies.

Every webhook signs from its first delivery: the model fills `secret` on
create, and the migration backfills the rows that predate the column. A
delivery goes out **unsigned** — with no `X-Webhook-Signature` header — only
if `secret` is empty, which happens when a row is inserted straight into the
table and bypasses the model.

Rotating the secret invalidates any receiver still holding the old one, so
update the receiver in the same change.

Only the attendance path forwards. User records, operation logs and fingerprint
templates go through `receiveBiometricRecords()`, which never calls the webhook.

> Deploying to a server that runs `php artisan config:cache`? Re-run
> `config:clear` + `config:cache`, otherwise the `webhook` channel is missing
> from the cached config and delivery logs fall back to the default channel.

## 🏢 Offices and timezones

Each office (**Offices** menu) carries a `timezone` that has to be a **real
city zone** — `Asia/Jakarta`, `America/Cancun`, `Europe/Madrid`.

`UTC`, `GMT`, `Etc/*` and bare offsets such as `+00:00` are all *valid* IANA
identifiers, so the form accepts them and they look fine in the table. No
physical office is actually in one: they get in when the field was defaulted
or never filled in. The server therefore treats them as "not filled in yet" —
the office list shows a **Generic timezone** badge, saving one shows a warning,
and it is not rejected, because `UTC` is occasionally the honest answer.

Leaving one in place costs two things:

- The **monitor card's** discrepancy figure is computed over the office's local
  day, so a generic zone puts it over the wrong 24 hours.
- **`getrequest()` will not build a clock correction from it.** An Indonesian
  office recorded as UTC made the server order a 7 hour shift, the terminal
  obeyed, its punches then read as skewed, and the next poll ordered another
  correction — a loop that never settles. For a generic zone the correction is
  skipped and logged instead:

```
getrequest: clock correction skipped, office has no local timezone
{"sn":"*************","idoficina":3,"timezone":"UTC","discrepancies":12}
```

Corrections that *are* queued are rate-limited by
`ADMS_CLOCK_CORRECTION_COOLDOWN` (default 30 minutes) so a terminal whose
counter stays high cannot be sent a `SET OPTIONS DateTime=` on every poll.

To audit for generic zones:

```sql
SELECT idoficina, ubicacion, timezone FROM oficinas
WHERE timezone IS NULL
   OR TRIM(timezone) = ''
   OR UPPER(TRIM(timezone)) IN ('UTC', 'GMT', 'Z')
   OR timezone LIKE 'Etc/%'
   OR timezone REGEXP '^[+-][0-9]{2}:?[0-9]{2}$';
```

> The discrepancy figure is a **count of records**, not a duration — the field
> label says "time discrepancies today" but the number is how many rows differ
> from their punch time by more than 20 minutes. Punches whose `timestamp` is
> more than a day away from `created_at` are excluded, so a terminal replaying
> an old backlog does not saturate it.

## 🧰 Artisan commands

| Command | Purpose |
| --- | --- |
| `employees:sync-stations` | Pull employees from stations; queue only new ones for their devices |
| `fingerprints:pull` | Queue commands asking terminals to upload their templates (`--device`, `--office`, `--all`, `--pin`, `--roster`, `--fingers`) |
| `fingerprints:push` | Send stored templates to a terminal (`--device`, `--office`, `--pins`, `--dry-run`) |
| `monitor:desfases` | Report attendance whose punch time is far from when it was received (`--threshold`, minutes) |
| `devices:check-status` | Alert when devices go offline too long, or come back online |
| `api:sincronizeAttendance` | Push attendance to the Webroster APIs |

Four of them are scheduled in `app/Console/Kernel.php` and need a cron entry:

```cron
* * * * * cd /path/to/adms-server && php artisan schedule:run >> /dev/null 2>&1
```

| Command | Schedule |
| --- | --- |
| `api:sincronizeAttendance` | every minute |
| `devices:check-status` | every minute |
| `monitor:desfases` | every five minutes |
| `employees:sync-stations` | daily at 08:00, without overlapping |

## 🌐 Languages

English, Spanish and Indonesian ship in `resources/lang/{en,es,id}` and are
listed in `config/app.php` under `available_locales`. The locale is resolved
from `?lang=`, then the session, then `Accept-Language`, then `app.locale`, and
is switched by the `language.switch` route.

Two rules when adding UI text: everything user-visible goes through `__()`, and
a key added to one language must be added to all three — a key that exists in
only one locale renders as the raw string. `tests/Feature/LocalizationTest.php`
guards both.

## 🚀 Deployment notes

### The `/iclock/*` endpoints must not be redirected

A terminal speaks **HTTP/1.0** and does **not** follow redirects. If a
reverse proxy answers `/iclock/cdata` with a 301 to HTTPS, the terminal
silently stops sending data and the device shows as offline — which looks
exactly like a network or firewall problem.

On aaPanel/BT Panel this is caused by the **"Force HTTPS"** toggle, which
writes a server-level rewrite into
`/www/server/panel/vhost/nginx/<domain>.conf`. A `location ^~ /iclock/` block
does **not** help: the server-context `if` runs in the rewrite phase, before
location matching.

- `scripts/diagnose-iclock.sh` — run **as root** (otherwise
  `/www/server/panel/vhost/nginx/` is unreadable because it is mode 0700).
- `scripts/fix-iclock-redirect.sh` — applies the fix atomically, backs up the
  vhost and validates with `nginx -t`.

Do not hand-edit `/www/server/panel/vhost/rewrite/<domain>.conf`; that file
holds rewrite rules, not the Force HTTPS block. Prefer the panel toggle, since
aaPanel regenerates `vhost/nginx/<domain>.conf` whenever SSL settings change.

### Other things that bite in production

- **Use the domain on the terminal, not the server IP.** If two vhosts claim
  the same IP, nginx serves the first one and requests to the IP get a 404.
- **`APP_ENV=production` and `APP_DEBUG=false`.** With debug on, any error
  returns a stack trace to an unauthenticated endpoint.
- **`config:cache`** must be followed by `config:clear` + `config:cache` after
  changing anything in `config/`, `config/logging.php` included.
- **404s are logged** to the `404_errors` channel by `app/Exceptions/Handler.php`,
  which is a quick way to tell whether a request reached PHP at all.

## 🧱 Framework version

| Component          | Version    |
| ------------------ | ---------- |
| Laravel Framework  | `^12.0`    |
| PHP                | `^8.2`     |
| Laravel Sanctum    | `^4.0`     |
| PHPUnit            | `^11.0`    |
| Carbon (via Laravel) | `^3.0`   |

Dependency resolution is pinned to PHP 8.2 via `config.platform.php` in
`composer.json`, so `composer update` always produces a tree that runs on
the same PHP version used by CI and production. This matters because
newer Symfony releases (8.x) require PHP >= 8.4.1 and would otherwise be
pulled in automatically.

## 🐞 Bugs found during the upgrade

Two **pre-existing** issues were uncovered while upgrading from Laravel 10.
Neither is caused by the upgrade itself, but both had to be handled.

### 1. SQLite foreign-key mismatch on `attendances`

The schema declares `attendances.idoficina -> oficinas.idoficina`, but
`oficinas.idoficina` is only **indexed, not unique**. MySQL accepts a
foreign key that points at a non-unique indexed column; SQLite does not
and fails on insert with:

```
SQLSTATE[HY000]: General error: 1 foreign key mismatch - "attendances" referencing "oficinas"
```

Laravel 12 now actually applies that constraint on SQLite, which broke
`PullFingerprintsTest::test_attendance_uploads_are_unaffected`.

**Resolution:** the production database is MySQL, so the schema is left
untouched. Instead `phpunit.xml` sets:

```xml
<env name="DB_FOREIGN_KEYS" value="false"/>
```

> ⚠️ Do not "fix" this by adding a unique index to `oficinas.idoficina`
> without checking production data first. `storeOficina()` performs no
> uniqueness validation, so duplicate `idoficina` values may already
> exist in the live database.

### 2. Carbon 3 `diffIn*()` now returns a signed value

Carbon 3 (pulled in by Laravel 12) changed `diffInMinutes()` and friends
from returning an **unsigned** value to a **signed float**, and flipped
the `$absolute` parameter default from `true` to `false`. Code that
relied on the old behaviour — e.g. `if ($diff > 20)` — would silently
invert when the second argument is earlier than the first.

**Resolution:** every `diffIn*()` comparison was wrapped in `abs()` to
preserve the pre-upgrade meaning. Affected call sites:

- `app/Models/Device.php`
- `app/Console/Commands/MonitorDesfases.php`
- `app/Console/Commands/PullFingerprints.php`
- `app/Http/Controllers/DeviceController.php`
- `resources/views/devices/{attendance,fingerdata,index,monitor}.blade.php`

Keep this in mind for any new code that compares timestamps.

## 📮 Postman Collection

For testing and interacting with the API endpoints, you can use the provided Postman collection:
[Postman Collection](WebRoster%20ADMS%20Server.postman_collection.json)
(environment: [WebRoster ADMS.postman_environment.json](WebRoster%20ADMS.postman_environment.json))

## 👨‍💻 Contributors

-   [@saifulcoder](https://github.com/saifulcoder) — original project
-   [@XMindware](https://github.com/XMindware)
-   [@mdestafadilah](https://github.com/mdestafadilah)

## 🤝 Contributing

This project helps you and you want to help keep it going? Buy me a coffee:
<br> <a href="https://www.buymeacoffee.com/saifulcoder" target="_blank"><img src="https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png" alt="Buy Me A Coffee" style="height: 61px !important;width: 174px !important;box-shadow: 0px 3px 2px 0px rgba(190, 190, 190, 0.5) !important;" ></a><br>
or via <br>
<a href="https://saweria.co/saifulcoder">https://saweria.co/saifulcoder</a>

## 📄 License

MIT

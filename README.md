# 🔐 ADMS (Attendance Device Management System)

ADMS is a comprehensive Attendance Device Management System designed to handle biometric and access control data from various devices. This system is built using Laravel, a PHP framework, provides functionalities to store, manage user and fingerprint data.

## ✨ Features

-   🔒 Fingerprint data storage
-   📊 Device status monitoring

## 📸 Screenshots

Device Connected
![App Screenshot](https://github.com/saifulcoder/adms-server-ZKTeco/blob/main/Screenshot_7.png)
Attendance Recorded
![App Screenshot](https://github.com/saifulcoder/adms-server-ZKTeco/blob/main/Screenshot_8.png)
Device Log
![App Screenshot](https://github.com/saifulcoder/adms-server-ZKTeco/blob/main/Screenshot_9.png)
Attendence Log
![App Screenshot](https://github.com/saifulcoder/adms-server-ZKTeco/blob/main/Screenshot_10.png)

## 🖥️ Device Support

-   Solutions X100C
-

## 📦 Installation

### ⚙️ Prerequisites

Before you begin, ensure you have the following installed on your system:

-   PHP >= 8.0
-   Composer
-   MySQL or any other supported database
-   Web server (Apache, Nginx, etc.)

### 📋 Steps

1. **Clone the repository**

    ```bash
    git clone https://github.com/saifulcoder/adms-server-ZKTeco.git adms-server
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

6. **Run the migrations**

    ```bash
    php artisan migrate
    ```

7. **Serve the application**
    ```bash
    php artisan serve
    ```

### 🔐 Device authentication (`/iclock/*`)

Every `/iclock/*` request is checked against the `devices` table: is its serial number registered and enabled? By default the server only logs what it finds and serves the request as before. Rejecting unknown clocks is an explicit opt-in.

**Deploy order:** run `php artisan migrate` before deploying this code. It adds the `devices.enabled` column (existing devices stay enabled). Without the column every device lookup fails: in `log` mode each request logs `device auth: lookup failed`, and in `enforce` mode every clock gets a 403.

Settings (in `.env`, read by `config/adms.php`). They are not in `.env.example`, so add them yourself if you want non-default values:

```env
ADMS_DEVICE_AUTH=log
ADMS_DEVICE_RATE_LIMIT=0
ADMS_DEVICE_RATE_LIMIT_PER_IP=0
```

| Variable | Default | Meaning |
| --- | --- | --- |
| `ADMS_DEVICE_AUTH` | `log` | `log`: unknown, disabled or conflicting serials are logged and still served exactly as before (unknown serials are still auto-created on their first handshake). `enforce`: they get `403` with body `ERROR: Device not registered`; nothing is stored and no device row is created. The value is trimmed and case-insensitive. Any value other than `enforce`, including a typo, means `log`. |
| `ADMS_DEVICE_RATE_LIMIT` | `0` | Requests per minute per serial number + IP. `0` = no limit. |
| `ADMS_DEVICE_RATE_LIMIT_PER_IP` | `0` | Requests per minute per IP across all serials (clocks behind one NAT address share it). `0` = no limit. |

The two rate limits are independent. With both at `0` (the default) there is no throttling and responses carry no `X-RateLimit-*` headers. If you turn them on, keep them generous: a full re-upload after a bulk pull posts many batches quickly.

What gets logged (warning level, at most once per serial and reason per hour):

| Message | When |
| --- | --- |
| `device auth: unregistered serial number` | The serial is not in `devices`, or the request has no serial. |
| `device auth: disabled serial number` | The device exists but **Habilitado** is unchecked. |
| `device auth: conflicting serial number` | The query string and body carry different serials (`SN` / `sn`). |
| `device auth: lookup failed` | The database lookup threw. Logged on every failing request. |

Each entry has `sn` (truncated to 64 characters) and `mode`. The first three also have `ip` and `path`; `lookup failed` has `exception` (the exception class only, never its message). Request bodies, biometric templates and tokens are never logged. If the lookup fails, `log` mode serves the request and `enforce` mode returns the same 403.

To disable a clock, uncheck **Habilitado** on its edit page. In `log` mode it keeps working but is reported; in `enforce` mode it gets a 403.

**Switching to `enforce` later:**

1. Run in `log` mode for at least a week.
2. Collect the serials from the `device auth:` warnings and compare them, and every row in `devices`, with the clocks you actually own. In `log` mode an unknown serial is warned on its first request and then auto-created, so look for rows you don't recognise, not only for warnings.
3. Uncheck **Habilitado** on any device you don't own. Don't just delete it: in `log` mode its next handshake creates it again.
4. Set `ADMS_DEVICE_AUTH=enforce`, then run `php artisan config:clear` (or `php artisan config:cache` if you cache config) so the new value is read.
5. Watch the logs for clocks that now get 403. To roll back, set `ADMS_DEVICE_AUTH=log` and clear the config cache again.

### 📡 Monitoring Device Status

You can monitor the status of devices by querying the `devices` table where the `online` field indicates the last time the device was online.

## 📮 Postman Collection

For testing and interacting with the API endpoints, you can use the provided Postman collection:
[Postman Collection](https://github.com/saifulcoder/adms-server-ZKTeco/blob/main/ADMS server ZKTeco.postman_collection.json)

## 👨‍💻 Authors

-   [@saifulcoder](https://github.com/saifulcoder)

## 💡 For Improvement and project

contact us saiful.coder@gmail.com

## 🤝 Contributing

This project helps you and you want to help keep it going? Buy me a coffee:
<br> <a href="https://www.buymeacoffee.com/saifulcoder" target="_blank"><img src="https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png" alt="Buy Me A Coffee" style="height: 61px !important;width: 174px !important;box-shadow: 0px 3px 2px 0px rgba(190, 190, 190, 0.5) !important;" ></a><br>
or via <br>
<a href="https://saweria.co/saifulcoder">https://saweria.co/saifulcoder</a>

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Device status API (for WebRoster)

Read-only, authenticated per empresa with an API key. A key only ever sees devices whose `idempresa` matches it.

```bash
php artisan migrate
php artisan empresa-keys:create <idempresa> --name="webroster prod"   # prints the key once
php artisan empresa-keys:revoke <key id>
php artisan route:clear   # if routes were cached (bootstrap/cache/routes-v7.php)
```

```bash
curl -H "Authorization: Bearer wrk_..." "https://<adms-host>/api/empresa/devices?idoficina=60&online=1"
curl -H "Authorization: Bearer wrk_..." "https://<adms-host>/api/empresa/devices/<serial_number>"
```

Each device returns `online` (contacted the server within `ADMS_ONLINE_THRESHOLD_MINUTES`, default 3), `last_seen_at`, `seconds_since_last_seen`, `last_checada` (`employee_id`, `device_timestamp` as stored, `received_at`), `pending_commands`, office and timezone. The list also carries a `summary` (total/online/offline) that ignores the `online` filter. Missing/invalid/revoked key → 401; a serial number from another empresa → 404.

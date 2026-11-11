# Home Network Monitor: Backend (API, scanner and home agent)

The server side of the **Home Network Device Monitoring and Alert System** (PHP). It stores each account's devices, applies the alert logic, sends alert emails, and includes the **home agent** that scans a home network.

The dashboard that users see is a separate repository:
**[home-network-monitoring-frontend](https://github.com/nicholasT27/home-network-monitoring-frontend)** (React, deployed on Cloudflare Pages).

```
Home computer (agent.php) ──HTTPS, outgoing only──►  Backend: PHP API + MySQL  ◄──HTTPS──  Dashboard (frontend repo)
 pings the network, reads the ARP list                (this repository)                      any browser
```

A website cannot see inside a home network, so a small **agent** runs on a computer at home, scans the network and uploads the result to this backend. The backend recognises devices by MAC address, tells Trusted from Unknown, raises alerts and emails them.

## What it does

- **Accounts:** sign-up and sign-in; every record belongs to one account and cannot be seen by another (signed login tokens, hashed passwords).
- **Discovery:** the agent pings every address in the account's network range (which makes active devices answer and fills the ARP cache), then reads the ARP list for MAC addresses. A device counts as **online** only if it answered the ping.
- **Alerts:** a new MAC address becomes **Unknown** and raises one alert (dashboard + email). A trusted device whose hostname or manufacturer changes is marked **Suspicious**. A trusted phone that rotates its private MAC address is recognised by its hostname, without a false alarm. The first scan is a baseline with a single summary alert. IP address changes are logged, not alerted.
- **History:** first seen, came online, went offline, IP changed, MAC rotated.
- **Email:** sent through an SMTP account (for example Gmail with an app password).

## API

One entry point: `index.php?action=<name>`. Requests and replies are JSON.

| Action | Method | Who | Purpose |
|---|---|---|---|
| `register`, `login` | POST | anyone | create an account / sign in; returns a token |
| `status` | GET | signed in (`X-Auth-Token`) | account summary, network range, last scan, agent online |
| `devices`, `history` | GET | signed in | the account's devices / their connection history |
| `alerts`, `alert_ack` | GET, POST | signed in | open alerts / dismiss one or all |
| `device_update`, `device_delete` | POST | signed in | trust, untrust, rename, remove a device |
| `settings` | POST | signed in | network range and alert email |
| `test_email` | POST | signed in | send a test email to the saved address |
| `scan` | POST | signed in | scan now (asks the home agent in `agent` mode) |
| `agent_poll`, `agent_ingest` | GET, POST | the home agent (`X-Agent-Key`) | ask for work / upload scan results |

## Run it locally (API only)

You need **PHP 8.0 or newer** with the `pdo_sqlite` extension (XAMPP, Homebrew `php`, or php.net).

```bash
cp .env.example .env        # Windows: copy .env.example .env
# in .env set SCAN_MODE=demo (or live) and leave the database lines commented out
php -S 127.0.0.1:8081 router.php
curl "http://127.0.0.1:8081/index.php?action=status"     # -> {"error":"Not signed in"}
```

Then run the dashboard (frontend repository) with `VITE_API_BASE_URL=http://127.0.0.1:8081`. `router.php` exposes only `index.php`; nothing else in this folder can be downloaded through it.

## Configuration

Settings come from `.env` next to `index.php`, or from real environment variables (which win). `.env.example` lists everything.

| Variable | Purpose |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (or `DB_DSN`) | MySQL. If none is set, a SQLite file (`data/monitor.sqlite`) is used |
| `SCAN_MODE` | `agent` (home agents scan; use on a web host), `live` (this server scans its own network), `demo` (simulated devices) |
| `AUTH_SECRET` | long random string that signs login tokens. **Change it.** |
| `ALLOW_REGISTRATION` | `0` closes sign-ups |
| `ALLOWED_ORIGINS` | address(es) of the dashboard allowed to call the API (CORS), comma separated |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS` | sender account for alert emails |
| `SUBNET` | default network range (each user sets their own in the dashboard) |

## Deploy on a PHP web host

You need a host with **PHP 8.0+, MySQL, phpMyAdmin and HTTPS** (a file manager or FTP to upload). Read "Choosing a host" below first.

1. **Create a MySQL database** in the host's control panel and note the database host, name, user and password.
2. **Create the tables:** in phpMyAdmin select the database, click **Import**, and import **`schema.shared-hosting.sql`**. Six tables appear. (`schema.sql` is the same but also creates a database, for a server where you are allowed to.)
3. **Upload** the contents of this repository (the files, not the outer folder; `tests/` is optional) into a folder on the host, for example `public_html/api/`.
4. **Create `.env`** in that folder (copy `.env.example`, a plain file named `.env`, not `.env.txt`) with the database values, `SCAN_MODE=agent`, a long random `AUTH_SECRET`, and `ALLOWED_ORIGINS` set to your dashboard address (for example `https://home-network-monitoring-frontend.pages.dev`).
5. **Check it:**
   - `https://your-site/api/index.php?action=status` must show `{"error":"Not signed in"}`.
   - `https://your-site/api/.env` must show **403 / Forbidden / Not found**. If it shows the file's contents, the host ignores `.htaccess`: delete the file and set the same names as environment variables in the host's panel.
6. **Tell the dashboard where the backend is:** in the frontend repository (Cloudflare Pages) set `VITE_API_BASE_URL=https://your-site/api` and redeploy.

### Choosing a host

- It must give the backend an **https://** address (the dashboard is https, and browsers block calls to plain http).
- It must **not put a security page in front of API calls.** Some free hosts do. For example, InfinityFree's free plan runs a JavaScript "browser security" check; reports on its forum say [curl and scripts cannot pass it](https://forum.infinityfree.com/t/php-api-requests-blocked-by-javascript-security-challenge/121461), which would block the home agent, and that [cross-domain browser requests to a free site are blocked](https://forum.infinityfree.com/t/why-isnt-api-access-working-on-my-website/115198/1), which would block a dashboard hosted elsewhere. (Its free plan also restricts PHP `mail()`, but [sending through an outside SMTP account such as Gmail is allowed](https://forum.infinityfree.com/t/how-do-i-set-up-smtp/13217/4), which is how this project sends email.)
- **Test any host before relying on it** (two minutes): upload a file `ping.php` containing `<?php header('Content-Type: application/json'); echo '{"ok":true}';` and run

  ```
  curl -i https://your-site/ping.php
  ```

  You should see `{"ok":true}`. If you get an HTML page that mentions `aes.js` or `__test`, API calls are being blocked: choose another host.
- Free hosts may also block outgoing email. Press **Send test email** in the dashboard to find out; alerts still appear in the dashboard.

## The home agent

`agent.php` runs on a computer at home (anything connected to the home Wi-Fi or router, left on). Each user does this once:

1. Create an account in the dashboard. The **Home agent** panel shows two lines containing the backend address and the personal key.
2. On the home computer install PHP 8 (for example XAMPP) and copy this repository's files (at least `agent.php`, `config.php`, `lib/`).
3. Copy `agent.env.example` to `.env` there and paste the two lines from the dashboard.
4. Run it and leave the window open (Windows: `C:\xampp\php\php.exe agent.php`; Mac with XAMPP: `/Applications/XAMPP/xamppfiles/bin/php agent.php`; otherwise `php agent.php`). The panel turns **Online** within seconds.
5. In the dashboard enter the network range (the computer's IP with the last number as 0, e.g. `192.168.0.0/24`), then click **Scan now**.

The agent scans on request and every two minutes, so alerts arrive even when the dashboard is closed. `php agent.php --once` scans once and exits. For `SCAN_MODE=live` (the backend itself at home) schedule `php cli_scan.php` every two minutes instead.

## Email alerts

Users type their own address in the dashboard (Network & alerts) and can send a test email. The backend sends from one SMTP account set in `.env`. For Gmail: turn on 2-Step Verification, create an app password at https://myaccount.google.com/apppasswords, then set `SMTP_HOST=smtp.gmail.com`, `SMTP_PORT=465`, `SMTP_SECURE=ssl`, `SMTP_USER=<gmail address>`, `SMTP_PASS=<app password>` (no spaces). Never commit the password. Without `SMTP_HOST`, PHP `mail()` is used, which needs mail software on the server.

## Tests

```bash
php tests/run.php      # unit tests: ARP parsing, accounts, isolation, alerts, MAC rotation, spoofing, scan lock, agent, SMTP, env config
php tests/api.php      # HTTP tests: every endpoint, accounts, agent, CORS (starts its own server on a temporary database)
```

## Project layout

```
index.php            entry point; sends each request to a handler in routes/
routes/              auth, agent, account, devices, alerts, scan
lib/                 db, http (CORS, tokens), users, scanner, monitor, mailer, vendor (manufacturer lookup)
agent.php            the home agent          cli_scan.php   scheduled scan for SCAN_MODE=live
router.php           API-only server for local use
config.php           reads .env / environment variables
schema.shared-hosting.sql  schema.sql  schema.sqlite.sql
.env.example  agent.env.example  .htaccess
tests/               run.php, api.php
```

## Limits

IPv4 ranges from /22 to /30 only; the manufacturer lookup is a small built-in table (`lib/vendor.php`); devices that ignore pings (for example a sleeping phone) can appear offline while connected; MAC randomisation is handled by hostname matching; the agent must keep running at home.

Part of the CSN project "Home Network Device Monitoring and Alert System" (Wawasan Open University).

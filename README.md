# Home Network Monitor: Backend and Home Agent

![Dashboard](docs/images/08-new-unknown-device.png)

The server side of the **Home Network Device Monitoring and Alert System** (PHP + MySQL/SQLite). It stores each account's devices, applies the alert logic, sends alert emails, and includes the **home agent** that scans a home network. A student project for the CSN Project (TSN309/10) at Wawasan Open University.

| Repository | What it is |
|---|---|
| **home-network-monitoring-backend** (this one) | PHP API, database code, alert emails and the home agent (`agent.php`) |
| [home-network-monitoring-frontend](https://github.com/nicholasT27/home-network-monitoring-frontend) | the web dashboard (React) that people use in a browser |

**Live system (for marking):** dashboard https://home-network-monitoring-frontend.pages.dev, backend https://home-network-monitoring-backend.wasmer.app. You can create an account there. To scan a real network you also run the home agent (steps below).

## Contents

1. [How it works](#how-it-works)
2. [What you need](#what-you-need)
3. [Install and run, option A: quick demo with simulated devices](#option-a-quick-demo-simulated-devices)
4. [Install and run, option B: scan your own network on one computer](#option-b-scan-your-own-network-on-one-computer)
5. [Install and run, option C: use the live system](#option-c-use-the-live-system-with-your-own-home-agent)
6. [Using the dashboard](#using-the-dashboard)
7. [What it does](#what-it-does), [API](#api), [Configuration](#configuration), [Email alerts](#email-alerts)
8. [Deploy on a PHP web host](#deploy-on-a-php-web-host), [The home agent](#the-home-agent), [Tests](#tests), [Troubleshooting](#troubleshooting)

## How it works

```
Home computer (agent.php) ──HTTPS, outgoing only──►  Backend: PHP API + MySQL  ◄──HTTPS──  Dashboard (frontend repo)
 pings the network, reads the ARP list                (this repository)                      any browser
```

A website cannot see inside a home network, so a small **agent** runs on a computer at home, scans the network and uploads the result to this backend. The backend recognises devices by MAC address, tells **Trusted** from **Unknown**, raises alerts and emails them. The dashboard shows the result.

A scan can start in two ways: you click **Scan now** (the backend saves a request and the agent picks it up within seconds), or the agent scans by itself every 2 minutes.

## What you need

| For | You need |
|---|---|
| Everything | **PHP 8.0 or newer** (with the `pdo_sqlite` extension; XAMPP, Homebrew `php`, or php.net). Check with `php -v` |
| The dashboard (options A and B) | **Node.js 20.19+ or 22** ([nodejs.org](https://nodejs.org)). Check with `node -v` |
| Option B and C (real scan) | a computer on the home Wi-Fi or router; on Mac or Linux it is best run with `sudo`, on Windows from an Administrator terminal |
| Email alerts (optional) | a Gmail account with an app password, see [Email alerts](#email-alerts) |

Download both repositories: on each GitHub page click the green **Code** button, then **Download ZIP**, and unzip them side by side (or use `git clone`).

## Option A: quick demo (simulated devices)

The fastest way to see the system working. No router, agent or email is needed: the backend invents a small household and, from the third scan, an intruder.

**1. Start the backend** (in the backend folder):

```bash
cp .env.example .env        # Windows: copy .env.example .env
```

Open `.env` and set `SCAN_MODE=demo`, then:

```bash
php -S 127.0.0.1:8081 router.php
```

Leave this window open. Check it: open http://127.0.0.1:8081/index.php?action=status in a browser. You should see `{"error":"Not signed in"}`.

**2. Start the dashboard** (in the frontend folder, in a second terminal):

```bash
npm install
cp .env.example .env        # Windows: copy .env.example .env  (it already points at http://127.0.0.1:8081)
npm run dev
```

**3. Use it.** Open http://localhost:5173, click **Create an account**, then **Scan now** a few times. After the third scan a new Unknown device appears and an alert is raised. Click **Trust** on the devices you recognise.

![Create an account](docs/images/02-create-account.png)

## Option B: scan your own network on one computer

This runs all three parts on one computer connected to your home network: the backend, the dashboard and the agent.

**1. Backend.** In the backend folder copy `.env.example` to `.env`, set `SCAN_MODE=agent`, and set `AUTH_SECRET` to any long random sentence. Then:

```bash
php -S 127.0.0.1:8081 router.php
```

**2. Dashboard.** In the frontend folder (second terminal): `npm install`, copy `.env.example` to `.env`, then `npm run dev`. Open http://localhost:5173 and create an account.

**3. Set your network range.** In the **Network & alerts** panel type your range and click **Save network**. To find it, take your computer's IP address (Mac: System Settings > Wi-Fi > Details > TCP/IP, or `ipconfig getifaddr en0`; Windows: `ipconfig`), keep the first three numbers and end with `.0/24`. For example `192.168.0.151` gives `192.168.0.0/24`.

![Network and alerts panel](docs/images/03-network-and-email.png)

**4. Connect the agent.** In the **Home agent** panel click **Copy**, then open the backend's `.env` file and add the two lines the panel shows (`AGENT_API_URL` and `AGENT_KEY`) at the end.

![Home agent panel](docs/images/05-home-agent-panel.png)

**5. Start the agent** (third terminal, in the backend folder) and leave it open:

- Mac or Linux: `sudo php agent.php`
- Windows: open the terminal **as Administrator**, then `php agent.php`

The first line should say `ARP check: ON`. (Without administrator rights it says `ARP check: OFF`; the agent still works, but devices that ignore pings are missed.) The Home agent panel turns **Online** within seconds.

**6. Scan.** Click **Scan now**. The dashboard shows "Scan requested. Waiting for the home agent…", then the result. Click **Trust** on the devices you recognise; anything you do not recognise stays **Unknown**.

![Scan requested](docs/images/06-scan-waiting.png)
![Scan finished](docs/images/07-scan-finished.png)

## Option C: use the live system with your own home agent

Use the already deployed dashboard and backend; you only run the agent.

1. Install PHP 8 or newer and download this repository (green **Code** button, **Download ZIP**, unzip).
2. Open https://home-network-monitoring-frontend.pages.dev, click **Create an account**, and sign in.
3. Set the network range and alert email in the **Network & alerts** panel (see Option B, step 3).
4. In the **Home agent** panel click **Copy**. In the unzipped folder create a file named `.env` (you can copy `agent.env.example`) and paste the two lines.
5. Start the agent as in Option B, step 5. The panel turns **Online**; then click **Scan now**.

Stop the agent with `Ctrl+C`; the dashboard then shows it as Offline. Closing the dashboard does not stop scanning: the agent keeps scanning every 2 minutes.

## Using the dashboard

| Area | What it does |
|---|---|
| Header | **Scan now** starts a scan; **Sign out** leaves. The line under the title shows your network range, the agent status and the last scan time |
| Summary tiles | counts of devices, online, trusted, unknown and suspicious |
| Devices | every device found; filters (All, Online, Unknown, Trusted, Suspicious) and a search box; **Trust/Untrust**, the pencil renames, the clock shows history, the bin removes it |
| Home agent | shows Online or Offline and the two lines the agent needs |
| Active alerts | a new Unknown or Suspicious device raises one alert (and an email); **Dismiss** clears it |
| Recent activity | a timeline of devices coming online, going offline or changing address |
| Network & alerts | save the network range and the alert email; **Send test email** checks the mail setup |

![Dashboard with a new unknown device](docs/images/08-new-unknown-device.png)

![Active alerts](docs/images/09-active-alerts.png)

![Device list and history](docs/images/10-devices-and-history.png)

## What it does

- **Accounts:** sign-up and sign-in; every record belongs to one account and cannot be seen by another (signed login tokens, hashed passwords).
- **Discovery:** the agent pings every address in the account's network range (which makes active devices answer and fills the ARP cache), then reads the ARP list for MAC addresses. A device counts as **online** if it answered the ping or, when the agent runs with administrator rights, a fresh ARP request (an old ARP entry alone never counts).
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

## Configuration

Settings come from `.env` next to `index.php`, or from real environment variables (which win). `.env.example` lists everything.

| Variable | Purpose |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (or `DB_DSN`, or `DATABASE_URL`; `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and `MYSQL_*` also work) | MySQL, tables created automatically. If none is set, a SQLite file (`data/monitor.sqlite`) is used |
| `SCAN_MODE` | `agent` (home agents scan; use on a web host), `live` (this server scans its own network), `demo` (simulated devices) |
| `AUTH_SECRET` | long random string that signs login tokens. **Change it.** |
| `ALLOW_REGISTRATION` | `0` closes sign-ups |
| `ALLOWED_ORIGINS` | address(es) of the dashboard allowed to call the API (CORS), comma separated |
| `DEBUG` | `1` switches on the `?action=health` setup diagnostic (names of settings, never values). Keep it off |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS` | sender account for alert emails |
| `SUBNET` | default network range (each user sets their own in the dashboard) |

## Deploy on a PHP web host

You need a host with **PHP 8.0+, MySQL, phpMyAdmin and HTTPS** (a file manager or FTP to upload). Read "Choosing a host" below first.

1. **Create a MySQL database** in the host's control panel and note the database host, name, user and password.
2. **Create the tables.** On MySQL the backend creates its six tables by itself the first time it runs, so this step is optional. To do it by hand: in phpMyAdmin select the database, click **Import**, and import **`schema.shared-hosting.sql`**. (`schema.sql` is the same but also creates a database, for a server where you are allowed to.)
3. **Upload** the contents of this repository (the files, not the outer folder; `tests/` is optional) into a folder on the host, for example `public_html/api/`.
4. **Create `.env`** in that folder (copy `.env.example`, a plain file named `.env`, not `.env.txt`) with the database values, `SCAN_MODE=agent`, a long random `AUTH_SECRET`, and `ALLOWED_ORIGINS` set to your dashboard address (for example `https://home-network-monitoring-frontend.pages.dev`).
5. **Check it:**
   - `https://your-site/api/index.php?action=status` must show `{"error":"Not signed in"}`.
   - `https://your-site/api/.env` must show **403 / Forbidden / Not found**. If it shows the file's contents, the host ignores `.htaccess`: delete the file and set the same names as environment variables in the host's panel.
6. **Tell the dashboard where the backend is:** in the frontend repository (Cloudflare Pages) set `VITE_API_BASE_URL=https://your-site/api` and redeploy.

### Deploy on Wasmer Edge (free tier)

Wasmer Edge runs PHP apps and can provide a MySQL database. A test app on its free plan reported PHP 8.3 with `pdo_mysql`, `openssl` and `curl` available, which is what this backend needs. These steps follow Wasmer's current dashboard and have **not** been tried end to end; if a screen differs, follow Wasmer's PHP guide (https://docs.wasmer.io/edge/guides/php).

1. Sign in at https://wasmer.io (with GitHub) and choose **Deploy an app** → **Import from GitHub** → this repository.
2. On **Configure your app** keep the preset **php** and PHP **8.3**. Under **Show advanced settings** look for the database option and **enable the database** (Wasmer provisions MySQL for the app and gives it connection variables). Click **Deploy**.
3. Open the app's **Settings → Environment variables** and add:
   - `SCAN_MODE` = `agent`
   - `AUTH_SECRET` = a long random sentence
   - `ALLOWED_ORIGINS` = the dashboard address (for example `https://home-network-monitoring-frontend.pages.dev`)
   - Only if Wasmer did **not** provide database variables itself: `DATABASE_URL` = `mysql://USER:PASSWORD@HOST:PORT/NAME` (from the database details), or `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`. The backend also understands the names `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and `MYSQL_*`.
   - Optional email: the `SMTP_*` values from `.env.example`.
4. Redeploy, then open `https://YOUR-APP.wasmer.app/index.php?action=status`. You should see `{"error":"Not signed in"}`. The tables are created on that first request.
5. In the dashboard (Cloudflare Pages) set `VITE_API_BASE_URL` to `https://YOUR-APP.wasmer.app` (no `/api`, no trailing slash) and redeploy it.

If you see `{"error":"Database error…"}`: add `DEBUG` = `1`, open `https://YOUR-APP.wasmer.app/index.php?action=health`, and read it. It lists which database settings exist (names only, never values) and the connection error. **Remove `DEBUG` again afterwards.** Do not use SQLite on Wasmer: its files are not kept between restarts.

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

For the best results run the agent with administrator rights: `sudo php agent.php` on Mac and Linux, or from an Administrator terminal on Windows. Each scan then clears the computer's ARP cache, pings every address, and asks again by ARP (several tries over a few seconds) for the addresses that did not answer, so a device counts as online when it answers a ping or an ARP request, which a firewall cannot block. This finds devices that ignore pings (for example a Windows laptop with its firewall on). Without administrator rights the agent still works, but such devices show as offline. When it starts, the agent prints `ARP check: ON` or `ARP check: OFF`.

The agent scans on request and every two minutes, so alerts arrive even when the dashboard is closed. `php agent.php --once` scans once and exits. For `SCAN_MODE=live` (the backend itself at home) schedule `php cli_scan.php` every two minutes instead.

## Email alerts

Users type their own address in the dashboard (Network & alerts) and can send a test email. The backend sends from one SMTP account set in `.env`. For Gmail: turn on 2-Step Verification, create an app password at https://myaccount.google.com/apppasswords, then set `SMTP_HOST=smtp.gmail.com`, `SMTP_PORT=465`, `SMTP_SECURE=ssl`, `SMTP_USER=<gmail address>`, `SMTP_PASS=<app password>` (no spaces). Never commit the password. Without `SMTP_HOST`, PHP `mail()` is used, which needs mail software on the server.

## Tests

```bash
# add TEST_DB_HOST, TEST_DB_PORT, TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS to run either command against an empty MySQL database
php tests/run.php      # unit tests: ARP parsing, accounts, isolation, alerts, MAC rotation, spoofing, scan lock, agent, SMTP, env config
php tests/api.php      # HTTP tests: every endpoint, accounts, agent, CORS (starts its own server on a temporary database)
```

## Project layout

```
index.php            entry point; sends each request to a handler in routes/
routes/              auth, agent, account, devices, alerts, scan, health
lib/                 db, http (CORS, tokens), users, scanner, monitor, mailer, vendor (manufacturer lookup)
agent.php            the home agent          cli_scan.php   scheduled scan for SCAN_MODE=live
router.php           API-only server for local use
config.php           reads .env / environment variables
schema.shared-hosting.sql  schema.sql  schema.sqlite.sql
.env.example  agent.env.example  .htaccess
tests/               run.php, api.php (and fake_smtp.php, a mail server for the tests)
docs/images/         screenshots used in this README
```

## Limits

IPv4 ranges from /22 to /30 only; the manufacturer lookup is a small built-in table (`lib/vendor.php`); a device in deep sleep that answers neither a ping nor an ARP request can appear offline while connected, and without administrator rights devices that ignore pings are missed; MAC randomisation is handled by hostname matching; the agent must keep running at home.

Part of the CSN project "Home Network Device Monitoring and Alert System" (Wawasan Open University).

## Troubleshooting

| What you see | What to do |
|---|---|
| `php: command not found` | PHP is not installed or not on your PATH. Install PHP 8 and open a new terminal (Windows XAMPP: `C:\xampp\php\php.exe`) |
| Dashboard says "Cannot reach the monitoring server" | The backend is not running, or `VITE_API_BASE_URL` in the frontend `.env` is wrong. Restart `npm run dev` after changing it |
| Browser console shows a CORS error | Add the dashboard's address to `ALLOWED_ORIGINS` in the backend `.env` (`http://localhost:5173` is allowed by default) |
| Home agent stays Offline | The agent is not running, or `AGENT_API_URL` / `AGENT_KEY` are wrong. Copy them again from the Home agent panel |
| `ARP check: OFF` | Start the agent with `sudo` (Mac or Linux) or from an Administrator terminal (Windows) |
| Scan finds 0 devices | The network range is wrong. Check the first three numbers against your computer's IP address |
| No alert emails | Press **Send test email** and see [Email alerts](#email-alerts); alerts still appear in the dashboard |

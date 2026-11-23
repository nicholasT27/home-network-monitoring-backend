# Home Network Monitor: Backend and Home Agent

![Dashboard](docs/images/08-new-unknown-device.png)

A system that finds the devices on your home network and emails you when an unknown device joins. This repository is the **backend** (PHP) and the **home agent** (`agent.php`) that scans your network. The dashboard you look at in a browser is in the [frontend repository](https://github.com/nicholasT27/home-network-monitoring-frontend).

**Live dashboard:** https://home-network-monitoring-frontend.pages.dev

## Steps for a new user

You need a computer at home that stays on and is connected to your Wi-Fi or router (Windows, Mac or Linux).

**1. Install PHP 8 or newer** on that computer. Check it by typing `php -v` in a terminal. (Windows: install [XAMPP](https://www.apachefriends.org). Mac: `brew install php`.)

**2. Download this project.** Click the green **Code** button at the top of this page, then **Download ZIP**, and unzip it.

**3. Create an account.** Open https://home-network-monitoring-frontend.pages.dev, click **Create an account**, type an email and a password (at least 8 characters).

![Create an account](docs/images/02-create-account.png)

**4. Set your network range and alert email.** In the **Network & alerts** panel:
- Network range: take your computer's IP address, keep the first three numbers and end with `.0/24`. For example `192.168.0.151` becomes `192.168.0.0/24`. (Mac: System Settings > Wi-Fi > Details > TCP/IP. Windows: type `ipconfig`.) Click **Save network**.
- Alert email: type the address that should receive alerts, click **Save email**, then **Send test email**.

![Network and alerts](docs/images/03-network-and-email.png)

**5. Connect the home agent.** In the **Home agent** panel click **Copy**. In the folder you unzipped, create a new file named `.env` and paste the two lines into it (`AGENT_API_URL` and `AGENT_KEY`). Save it.

![Home agent panel](docs/images/05-home-agent-panel.png)

**6. Start the agent.** Open a terminal in the unzipped folder and run it. Leave the window open.
- Mac or Linux: `sudo php agent.php`
- Windows: open the terminal **as Administrator**, then `php agent.php`

The first line should say `ARP check: ON`. The Home agent panel turns **Online**.

**7. Scan.** Click **Scan now**. After a few seconds the devices on your network are listed.

![Scan requested](docs/images/06-scan-waiting.png)
![Scan finished](docs/images/07-scan-finished.png)

**8. Trust your own devices.** Click **Trust** next to each device you recognise. A device you do not recognise stays **Unknown**. From now on a new unknown device raises an alert in **Active alerts** and an email, and the agent scans by itself every 2 minutes.

![A new unknown device](docs/images/08-new-unknown-device.png)

To stop, press `Ctrl+C` in the agent's terminal.

## Something not working?

| What you see | What to do |
|---|---|
| `php: command not found` | PHP is not installed. Install it and open a new terminal |
| Home agent stays Offline | Copy the two lines from the Home agent panel again and check the `.env` file name (it starts with a dot) |
| `ARP check: OFF` | Start the agent with `sudo` (Mac or Linux) or from an Administrator terminal (Windows) |
| Scan finds 0 devices | The network range is wrong. Check the first three numbers against your computer's IP address |
| No alert emails | Click **Send test email**. Alerts still appear in the dashboard |

---

# For developers

### Try it on one computer without the live site

1. Backend (this folder): copy `.env.example` to `.env`, set `SCAN_MODE=demo`, then `php -S 127.0.0.1:8081 router.php`. Opening http://127.0.0.1:8081/index.php?action=status should show `{"error":"Not signed in"}`.
2. Dashboard (frontend folder, [Node.js](https://nodejs.org) 20.19+ or 22): `npm install`, copy `.env.example` to `.env`, `npm run dev`.
3. Open http://localhost:5173, create an account and click **Scan now** a few times. The backend invents a small household and, from the third scan, an intruder.

To scan a real network this way, set `SCAN_MODE=agent` instead and follow steps 3 to 8 above, using the local dashboard.

### What it does

- **Accounts:** sign-up and sign-in; every record belongs to one account and cannot be seen by another (signed login tokens, hashed passwords).
- **Discovery:** the agent pings every address in the account's network range (which makes active devices answer and fills the ARP cache), then reads the ARP list for MAC addresses. A device counts as **online** if it answered the ping or, when the agent runs with administrator rights, a fresh ARP request (an old ARP entry alone never counts).
- **Alerts:** a new MAC address becomes **Unknown** and raises one alert (dashboard + email). A trusted device whose hostname or manufacturer changes is marked **Suspicious**. A trusted phone that rotates its private MAC address is recognised by its hostname, without a false alarm. The first scan is a baseline with a single summary alert. IP address changes are logged, not alerted.
- **History:** first seen, came online, went offline, IP changed, MAC rotated.
- **Email:** sent through an SMTP account (for example Gmail with an app password).

### API

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

### Configuration

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

### Deploy on a PHP web host

You need a host with **PHP 8.0+, MySQL, phpMyAdmin and HTTPS** (a file manager or FTP to upload). Read "Choosing a host" below first.

1. **Create a MySQL database** in the host's control panel and note the database host, name, user and password.
2. **Create the tables.** On MySQL the backend creates its six tables by itself the first time it runs, so this step is optional. To do it by hand: in phpMyAdmin select the database, click **Import**, and import **`schema.shared-hosting.sql`**. (`schema.sql` is the same but also creates a database, for a server where you are allowed to.)
3. **Upload** the contents of this repository (the files, not the outer folder; `tests/` is optional) into a folder on the host, for example `public_html/api/`.
4. **Create `.env`** in that folder (copy `.env.example`, a plain file named `.env`, not `.env.txt`) with the database values, `SCAN_MODE=agent`, a long random `AUTH_SECRET`, and `ALLOWED_ORIGINS` set to your dashboard address (for example `https://home-network-monitoring-frontend.pages.dev`).
5. **Check it:**
   - `https://your-site/api/index.php?action=status` must show `{"error":"Not signed in"}`.
   - `https://your-site/api/.env` must show **403 / Forbidden / Not found**. If it shows the file's contents, the host ignores `.htaccess`: delete the file and set the same names as environment variables in the host's panel.
6. **Tell the dashboard where the backend is:** in the frontend repository (Cloudflare Pages) set `VITE_API_BASE_URL=https://your-site/api` and redeploy.

#### Deploy on Wasmer Edge (free tier)

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

#### Choosing a host

- It must give the backend an **https://** address (the dashboard is https, and browsers block calls to plain http).
- It must **not put a security page in front of API calls.** Some free hosts do. For example, InfinityFree's free plan runs a JavaScript "browser security" check; reports on its forum say [curl and scripts cannot pass it](https://forum.infinityfree.com/t/php-api-requests-blocked-by-javascript-security-challenge/121461), which would block the home agent, and that [cross-domain browser requests to a free site are blocked](https://forum.infinityfree.com/t/why-isnt-api-access-working-on-my-website/115198/1), which would block a dashboard hosted elsewhere. (Its free plan also restricts PHP `mail()`, but [sending through an outside SMTP account such as Gmail is allowed](https://forum.infinityfree.com/t/how-do-i-set-up-smtp/13217/4), which is how this project sends email.)
- **Test any host before relying on it** (two minutes): upload a file `ping.php` containing `<?php header('Content-Type: application/json'); echo '{"ok":true}';` and run

  ```
  curl -i https://your-site/ping.php
  ```

  You should see `{"ok":true}`. If you get an HTML page that mentions `aes.js` or `__test`, API calls are being blocked: choose another host.
- Free hosts may also block outgoing email. Press **Send test email** in the dashboard to find out; alerts still appear in the dashboard.

### Email alerts

Users type their own address in the dashboard (Network & alerts) and can send a test email. The backend sends from one SMTP account set in `.env`. For Gmail: turn on 2-Step Verification, create an app password at https://myaccount.google.com/apppasswords, then set `SMTP_HOST=smtp.gmail.com`, `SMTP_PORT=465`, `SMTP_SECURE=ssl`, `SMTP_USER=<gmail address>`, `SMTP_PASS=<app password>` (no spaces). Never commit the password. Without `SMTP_HOST`, PHP `mail()` is used, which needs mail software on the server.

### Tests

```bash
# add TEST_DB_HOST, TEST_DB_PORT, TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS to run either command against an empty MySQL database
php tests/run.php      # unit tests: ARP parsing, accounts, isolation, alerts, MAC rotation, spoofing, scan lock, agent, SMTP, env config
php tests/api.php      # HTTP tests: every endpoint, accounts, agent, CORS (starts its own server on a temporary database)
```

### Project layout

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

### Limits

IPv4 ranges from /22 to /30 only; the manufacturer lookup is a small built-in table (`lib/vendor.php`); a device in deep sleep that answers neither a ping nor an ARP request can appear offline while connected, and without administrator rights devices that ignore pings are missed; MAC randomisation is handled by hostname matching; the agent must keep running at home.

Part of the CSN project "Home Network Device Monitoring and Alert System" (Wawasan Open University).

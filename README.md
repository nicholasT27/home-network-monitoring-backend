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

# Home Networking Monitoring System: Backend and Home Agent

![Dashboard](docs/images/08-new-unknown-device.png)

A system that finds the devices on your home network and emails you when an unknown device joins. This repository is the **backend** (PHP) and the **home agent** (`agent.php`) that scans your network. The dashboard you look at in a browser is in the [frontend repository](https://github.com/nicholasT27/home-network-monitoring-frontend).

**Live dashboard:** https://home-network-monitoring-frontend.pages.dev

## About

Most homes are set up once and never checked again, so the owner cannot tell when a new, unknown device joins the network. The **Home Networking Monitoring System** finds the devices on a home network, tells the ones you know (**Trusted**) from the ones you do not (**Unknown**), and emails you when a new or suspicious device appears.

It has three parts:
- **Dashboard** (frontend repository): the web page where you see your devices and alerts.
- **Backend** (this repository): a PHP and MySQL service that keeps each account's data, decides what is new or suspicious, and sends the alert emails.
- **Home agent** (`agent.php`, this repository): a small program on a computer at home that scans the network and sends the result to the backend.

A scan starts when you click **Scan now**, or by itself every 2 minutes. Devices are recognised by their MAC address, and each account only sees its own devices.

Built as the final year project for the CSN Project (TSN309/10), Wawasan Open University.

## Steps for a new user

You need a computer at home that stays on and is connected to your Wi-Fi or router (Windows, Mac or Linux).

**1. Install VS Code (recommended).** Download it free from https://code.visualstudio.com and install it. It lets you open the project folder, create the `.env` file and run the commands in one window.

**2. Install PHP 8 or newer.** VS Code does not come with PHP, but you can install PHP from inside VS Code: choose **Terminal > New Terminal** and type the command for your computer, then close and reopen VS Code.
- **Mac:** `brew install php` (needs [Homebrew](https://brew.sh); the page shows its one-line install).
- **Windows:** `winget install PHP.PHP.8.3`

Check it by typing `php -v` in the terminal; it should show a version starting with 8. On Windows also type `php -m` and look for `openssl` in the list. If `openssl` is missing, the agent cannot connect to the dashboard's server: use XAMPP's PHP instead, as described in [Using XAMPP's PHP](#using-xampps-php) below.

**3. Download this project with git.** In the VS Code terminal type:

```bash
git clone https://github.com/nicholasT27/home-network-monitoring-backend.git
```

Then choose **File > Open Folder** in VS Code and pick the new `home-network-monitoring-backend` folder. (If `git` is not found: Mac asks to install the developer tools, say yes; Windows: `winget install Git.Git`, then reopen VS Code. Or skip git: click the green **Code** button on this page, then **Download ZIP**, and unzip it.)

**4. Sign in.** Open https://home-network-monitoring-frontend.pages.dev and sign in with the test account:

| | |
|---|---|
| Email | `test@gmail.com` |
| Password | `test12345` |

(You can also click **Create an account** and use your own email and a password of at least 8 characters.)

![Create an account](docs/images/02-create-account.png)

**5. Set your network range and alert email.** In the **Network & alerts** panel:
- **Network range.** First find your computer's IP address. In the VS Code terminal (**Terminal > New Terminal**) use `ipconfig` on both Windows and Mac:
  - **Windows:** type `ipconfig`. Under **Wireless LAN adapter Wi-Fi** (or **Ethernet adapter** if you use a cable) find the line **IPv4 Address**, for example `192.168.0.151`.
  - **Mac:** type `ipconfig getifaddr en0`. It prints the address, for example `192.168.0.151`. (If it prints nothing, try `ipconfig getifaddr en1`.)

  Now keep the first three numbers and end with `.0/24`: `192.168.0.151` becomes `192.168.0.0/24`. Type that in the **Your network range** box and click **Save network**. (Not sure? See [Finding your network range](#finding-your-network-range) below.)
- Alert email: type the address that should receive alerts, click **Save email**, then **Send test email**.

![Network and alerts](docs/images/03-network-and-email.png)

**6. Connect the home agent.** In the **Home agent** panel click **Copy**. In VS Code choose **File > New File**, paste the two lines (`AGENT_API_URL` and `AGENT_KEY`), and save the file as `.env` in the project folder.

![Home agent panel](docs/images/05-home-agent-panel.png)

**7. Start the agent.** In VS Code choose **Terminal > New Terminal** (it opens in the project folder) and run the command below. Leave the terminal open.
- Mac or Linux: `sudo php agent.php`
- Windows: close VS Code and open it again **as Administrator** (right-click VS Code, **Run as administrator**), open a new terminal, then `php agent.php`

The first line should say `ARP check: ON`. The Home agent panel turns **Online**.

**8. Scan.** Click **Scan now**. After a few seconds the devices on your network are listed.

![Scan requested](docs/images/06-scan-waiting.png)
![Scan finished](docs/images/07-scan-finished.png)

**9. Trust your own devices.** Click **Trust** next to each device you recognise. A device you do not recognise stays **Unknown**. From now on a new unknown device raises an alert in **Active alerts** and an email, and the agent scans by itself every 2 minutes.

![A new unknown device](docs/images/08-new-unknown-device.png)

To stop, click in the VS Code terminal and press `Ctrl+C`.

## Finding your network range

The **network range** is the group of addresses on your home network that the agent checks. Every device at home has an address like `192.168.0.151`; devices on the same network share the first three numbers, and only the last number changes. So the range is those three numbers, then `.0/24`.

**General way, on any device:**
1. Find the IP address of any device that is connected to your Wi-Fi (your computer, or your phone: open its Wi-Fi settings and tap your network's name or the "i" icon, then look for **IP address**).
2. Write down the first three numbers, for example `192.168.0` from `192.168.0.151`.
3. Add `.0/24` at the end: `192.168.0.0/24`.

**Another way:** your router's own address (also called the default gateway) is on the same network. It is usually printed on a sticker on the router, for example `192.168.0.1` or `192.168.1.1`. Take its first three numbers and add `.0/24`.

**Common ranges at home**

| If your address starts with | Your range is |
|---|---|
| `192.168.0.` | `192.168.0.0/24` |
| `192.168.1.` | `192.168.1.0/24` |
| `10.0.0.` | `10.0.0.0/24` |
| `192.168.100.` | `192.168.100.0/24` |

**Check:** after you click **Save network** and scan, your own computer and your router should appear in the device list. If the list is empty, the range is wrong: check the first three numbers again.

(For almost every home network `/24` is right. If your router uses a bigger network, the dashboard accepts ranges from `/22` to `/30`.)

## Using XAMPP's PHP

XAMPP is an alternative way to get PHP (it already has `openssl` switched on). You only need its PHP: **do not start Apache or MySQL**.

1. Download XAMPP from https://www.apachefriends.org and install it with the default options (it installs into `C:\xampp` on Windows and `/Applications/XAMPP` on Mac).
2. Check its PHP works. In the VS Code terminal type:
   - **Windows:** `C:\xampp\php\php.exe -v`
   - **Mac:** `/Applications/XAMPP/xamppfiles/bin/php -v`

   It should show a version starting with 8.
3. Use that full path wherever the steps say `php`. To start the agent (Windows, in a VS Code opened **as Administrator**):

   ```
   C:\xampp\php\php.exe agent.php
   ```

   Mac: `sudo /Applications/XAMPP/xamppfiles/bin/php agent.php`
4. Optional, so you can type just `php`: on Windows press the Windows key, type **environment variables**, open **Edit the system environment variables**, click **Environment Variables**, select **Path** under "System variables", click **Edit**, **New**, type `C:\xampp\php`, then **OK** on every window and reopen VS Code.

## Something not working?

| What you see | What to do |
|---|---|
| `php: command not found` | PHP is not installed. Install it and open a new terminal |
| Home agent stays Offline | Copy the two lines from the Home agent panel again and check the `.env` file name (it starts with a dot) |
| `ARP check: OFF` | Start the agent with `sudo` (Mac or Linux) or from an Administrator terminal (Windows) |
| Scan finds 0 devices | The network range is wrong. Check the first three numbers against your computer's IP address |
| No alert emails | Click **Send test email**. Alerts still appear in the dashboard |

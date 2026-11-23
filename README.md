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

**1. Install PHP 8 or newer.** The home agent is a PHP program, so the computer needs PHP.
- **Windows (recommended: XAMPP):** download XAMPP from https://www.apachefriends.org and install it with the default options. You only need its PHP: **do not start Apache or MySQL**. To check it, press the Windows key, type `cmd`, press Enter, and type `C:\xampp\php\php.exe -v`. It should show a version starting with 8.
- **Mac:** open **Terminal** and type `brew install php` (needs [Homebrew](https://brew.sh); the page shows its one-line install). Check it with `php -v`. VS Code (https://code.visualstudio.com) is a handy free editor if you like, but it is not required.

**2. Download this project.** Click the green **Code** button at the top of this page, then **Download ZIP**, and unzip it. If you already use git, you can instead clone the repository with `git clone https://github.com/nicholasT27/home-network-monitoring-backend.git`. Nothing else needs installing: this project has no extra packages, so there is no `npm install`; PHP is all it needs.

**3. Sign in.** Open https://home-network-monitoring-frontend.pages.dev and sign in with the test account:

| | |
|---|---|
| Email | `test@gmail.com` |
| Password | `test12345` |

(You can also click **Create an account** and use your own email and a password of at least 8 characters.)

![Create an account](docs/images/02-create-account.png)

**4. Set your network range and alert email.** In the **Network & alerts** panel:
- **Network range.** First find your computer's IP address. In Command Prompt (Windows) or Terminal (Mac) use:
  - **Windows:** type `ipconfig`. Under **Wireless LAN adapter Wi-Fi** (or **Ethernet adapter** if you use a cable) find the line **IPv4 Address**, for example `192.168.0.151`.
  - **Mac:** type `ipconfig getifaddr en0`. It prints the address, for example `192.168.0.151`. (If it prints nothing, try `ipconfig getifaddr en1`.)

  Now keep the first three numbers and end with `.0/24`: `192.168.0.151` becomes `192.168.0.0/24`. Type that in the **Your network range** box and click **Save network**. (Not sure? See [Finding your network range](#finding-your-network-range) below.)
  The dashboard will not scan until you have saved your network range, so do this step first.
- Alert email: type the address that should receive alerts, click **Save email**, then **Send test email**.

![Network and alerts](docs/images/03-network-and-email.png)

**5. Connect the home agent.** In the **Home agent** panel click **Copy**. In the project folder open the file named `.env` with a text editor (right-click it and choose **Open with > Notepad** on Windows, or open it with TextEdit or VS Code on Mac). It is already there. Replace its two lines (`AGENT_API_URL` and `AGENT_KEY`) with the two lines you copied, and save the file. Keep your agent key private: do not share the `.env` file or put it on GitHub.

![Home agent panel](docs/images/05-home-agent-panel.png)

**6. Start the agent.** Leave the window open while it runs.
- **Windows:**
  1. Press the Windows key, type `cmd`, right-click **Command Prompt** and choose **Run as administrator**.
  2. Go to the project folder: type `cd /d ` (with a space after it) and then the folder's path, for example `cd /d C:\Users\you\Downloads\home-network-monitoring-backend-main`. (In File Explorer, click the address bar of the folder to copy its path.)
  3. Type `C:\xampp\php\php.exe agent.php`
- **Mac or Linux:** open Terminal, go to the project folder (for example `cd ~/Downloads/home-network-monitoring-backend-main`) and type `sudo php agent.php`.

The first line should say `ARP check: ON`. The Home agent panel turns **Online**.

**7. Scan.** Click **Scan now**. After a few seconds the devices on your network are listed.

![Scan requested](docs/images/06-scan-waiting.png)
![Scan finished](docs/images/07-scan-finished.png)

**8. Trust your own devices.** Click **Trust** next to each device you recognise. A device you do not recognise stays **Unknown**. From now on a new unknown device raises an alert in **Active alerts** and an email, and the agent scans by itself every 2 minutes.

![A new unknown device](docs/images/08-new-unknown-device.png)

To stop, click in the window and press `Ctrl+C`.

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

## Something not working?

| What you see | What to do |
|---|---|
| `php` is not recognized (Windows) | Use the full path as in step 6: `C:\xampp\php\php.exe agent.php`. If that is not found either, install XAMPP (step 1) |
| `php: command not found` (Mac) | PHP is not installed. Install it as in step 1 and open a new Terminal |
| Home agent stays Offline | Click **Copy** in the Home agent panel again, paste both lines over the two lines in the `.env` file, and save it |
| `ARP check: OFF` | Start the agent with `sudo` (Mac or Linux) or from a Command Prompt opened as Administrator (Windows) |
| Scan finds 0 devices | The network range is wrong. Check the first three numbers against your computer's IP address |
| No alert emails | Click **Send test email**. Alerts still appear in the dashboard |

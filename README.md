# DVSwitch Cockpit
## Modern Status Dashboard for ASL3 + DVSwitch

✅ Built for ASL3 + DVSwitch on Debian-based systems  
✅ Works with normal DVSwitch installs  
✅ Optional support for BM/STFU and TGIF/HBLink when present  
✅ No network control logic added  

DVSwitch Cockpit is a modern web dashboard for watching what your ASL3 / DVSwitch node is doing.

It gives you one clean place to see:

* current network / mode
* current talkgroup or target
* last heard station
* gateway activity
* DMR ID to callsign lookup
* clickable QRZ callsign links
* YSF quality information when available
* Analog_Bridge and MMDVM_Bridge restart buttons

Simple. Clean. Read-only where it should be.

`screenshot.png` is included in the repo if you want to preview the web interface.

---

## ✨ WHAT DVSWITCH COCKPIT CAN DO

DVSwitch Cockpit is meant to be a one-screen status cockpit.

With it, you can:

* see whether your node is on BrandMeister, TGIF, YSF, or another supported mode
* see the current talkgroup / target
* see recent gateway activity
* see DMR IDs resolved to callsigns when the DVSwitch subscriber database is available
* click resolved callsigns to open QRZ
* see YSF duration, loss, and BER when the backend provides it
* restart Analog_Bridge
* restart MMDVM_Bridge
* restart Analog_Bridge + MMDVM_Bridge together

---

## ⚠️ WHAT DVSWITCH COCKPIT DOES NOT DO

DVSwitch Cockpit is **not** a network controller.

It does **not** connect or disconnect:

* BrandMeister
* TGIF
* YSF
* AllStarLink
* EchoLink
* D-Star
* P25
* NXDN

It only reads live runtime information and displays it.

Use your normal DVSwitch tools, macros, radio commands, AllTune2, or other control program to change networks or talkgroups.

---

## ⚠️ BEFORE YOU INSTALL

You MUST already have:

* Working ASL3
* Working DVSwitch
* Analog_Bridge installed
* MMDVM_Bridge installed
* Apache/PHP available on the node

If your ASL3 / DVSwitch system is not already working, fix that first.

DVSwitch Cockpit sits on top of a working base system.  
It is not meant to repair a broken DVSwitch install.

---

## ✅ SUPPORTED SETUPS

DVSwitch Cockpit is designed to work first with a normal ASL3 / DVSwitch install.

It can also detect optional setups when present:

* Stock DMR / BrandMeister through DVSwitch
* YSF through DVSwitch
* BM/STFU if STFU is installed and running
* TGIF/HBLink if that setup is present
* DMR subscriber database for callsign lookup

You do **not** need STFU or HBLink for Cockpit to work.

If STFU or HBLink are not installed, Cockpit should simply ignore them.

---

## 📦 INSTALL - FIRST TIME

Use these commands only for a brand-new install:

```bash
cd /var/www/html
git clone https://github.com/TerryClaiborne/dvswitch-cockpit.git
cd dvswitch-cockpit
sudo ./setup_dvswitch_cockpit.sh
```

Then open Cockpit in your browser:

```text
http://YOUR-NODE-IP/dvswitch_cockpit/
```

Example:

```text
http://192.168.1.120/dvswitch_cockpit/
```

Replace `192.168.1.120` with the IP address of your own node.

Note: the GitHub repo folder uses a dash:

```text
/var/www/html/dvswitch-cockpit
```

The installed web folder uses an underscore:

```text
/var/www/html/dvswitch_cockpit
```

The setup script handles that for you.

---

## ⚡ UPDATE INDICATOR

DVSwitch Cockpit includes a small lightning-bolt update indicator beside the title.

The bolt normally stays hidden. It only lights up when the `VERSION` file on GitHub is newer than the local installed `VERSION` file.

This check is done quietly from the browser. If GitHub cannot be reached, Cockpit simply hides the bolt and keeps working.

---

## 🔄 UPDATE OR REINSTALL

Use these commands if DVSwitch Cockpit is already installed from GitHub:

```bash
cd /var/www/html/dvswitch-cockpit
git pull origin main
sudo ./setup_dvswitch_cockpit.sh
```

### Important

Always run:

```bash
sudo ./setup_dvswitch_cockpit.sh
```

after:

```bash
git pull origin main
```

Do not stop at `git pull` by itself.

The setup script refreshes:

* the installed web files in `/var/www/html/dvswitch_cockpit`
* permissions
* ownership
* sudoers
* Apache/PHP basics

If you installed using a different folder name, run `git pull` from the folder that contains the `.git` directory.

---

## 🛠️ WHAT THE SETUP SCRIPT DOES

`setup_dvswitch_cockpit.sh` helps by:

* checking for basic Apache/PHP packages
* installing missing basics when possible
* setting file ownership
* setting file permissions
* creating the sudoers rule
* validating the sudoers rule with `visudo`
* reloading Apache when available

The setup script does **not** change your DVSwitch network settings.

It does not edit:

* `/opt/Analog_Bridge/Analog_Bridge.ini`
* `/opt/MMDVM_Bridge/MMDVM_Bridge.ini`
* `/opt/MMDVM_Bridge/DVSwitch.ini`

---

## 🔐 SUDOERS

The restart buttons need a small sudoers rule.

The installer creates:

```text
/etc/sudoers.d/dvswitch-cockpit-services
```

That rule allows the web server to run only these commands:

```text
/usr/bin/systemctl restart analog_bridge.service
/usr/bin/systemctl restart mmdvm_bridge.service
/usr/sbin/asterisk -rx rpt nodes *
```

The Asterisk command is read-only and is used so Cockpit can check private node link status when needed for TGIF/HBLink detection.

To check the sudoers file:

```bash
sudo visudo -cf /etc/sudoers.d/dvswitch-cockpit-services
```

---

## 🌐 OPEN DVSWITCH COCKPIT IN YOUR BROWSER

After install, open:

```text
http://YOUR-NODE-IP/dvswitch_cockpit/
```

Example:

```text
http://192.168.1.120/dvswitch_cockpit/
```

Replace `192.168.1.120` with your own node IP address.

---

## 🖥️ HOW TO USE DVSWITCH COCKPIT

Once installed, open Cockpit in your browser.

Basic idea:

* connect to your desired mode using your normal DVSwitch control method
* open Cockpit
* watch the current network, target, and activity
* use the restart buttons only if a DVSwitch service needs restarted

Cockpit is for watching status.  
It is not for changing talkgroups or connecting networks.

---

## 🔘 RESTART BUTTONS

Cockpit includes:

* Restart Analog Bridge
* Restart MMDVM Bridge
* Restart Analog + MMDVM

These only restart services.

They do not connect or disconnect from any network.

---

## 📡 GATEWAY ACTIVITY

The Gateway Activity table shows recent activity.

Columns include:

* Time
* Mode
* Station
* Target
* Src
* Dur(s)
* Quality

### Src

`Net` means network-side activity.

`LNet` means local/node-side activity.

### Dur(s)

Duration is shown when the backend logs provide enough information.

Cockpit does not fake durations.

### Quality

Quality is shown when the backend logs provide it.

YSF commonly reports:

```text
Loss 0% / BER 0.0%
```

BM, TGIF, and other DMR paths may not expose loss or BER.  
That is normal.

---

## 📶 TGIF RX NOTE

TGIF/HBLink may show `RX` in the Dur(s) column for network receive rows.

Example:

```text
DMR/TGIF   9990   TG 9990   Net   RX
```

This means Cockpit saw the TGIF receive event, but the backend did not provide a reliable receive duration.

This is normal for that path.

Your local TGIF key-up can still show duration when Analog_Bridge logs it.

---

## 🔎 DMR ID, CALLSIGN, AND QRZ LOOKUP

For DMR rows, Cockpit tries to turn DMR IDs into callsigns.

If the callsign is found, it shows the callsign and makes it clickable to QRZ.

Example:

```text
2358691 -> CALLSIGN
```

If no match is found, Cockpit leaves the number alone.

Cockpit uses the local DVSwitch subscriber database when available.

Common file:

```text
/var/lib/dvswitch/subscriber_ids.csv
```

Analog_Bridge usually points to it here:

```text
/opt/Analog_Bridge/Analog_Bridge.ini
```

Look for:

```text
subscriberFile = /var/lib/dvswitch/subscriber_ids.csv
```

Cockpit does not own or update this database.

Many DVSwitch systems maintain it through normal DVSwitch tools.

To check the file:

```bash
ls -lh /var/lib/dvswitch/subscriber_ids.csv
stat /var/lib/dvswitch/subscriber_ids.csv
```

To update it on systems that support the DVSwitch update command:

```bash
sudo /opt/MMDVM_Bridge/dvswitch.sh update
sudo /opt/MMDVM_Bridge/dvswitch.sh reloadDatabase
```

To clear Cockpit's callsign cache:

```bash
sudo rm -f /var/www/html/dvswitch_cockpit/data/cache/dmr_subscribers.json
```

### Subscriber database protection

Cockpit protects itself from failed or partial DVSwitch subscriber database updates.

If `/var/lib/dvswitch/subscriber_ids.csv` is missing, tiny, or invalid, Cockpit will not rebuild its callsign cache from that bad file. When possible, it keeps using a last-known-good fallback copy instead:

```text
/var/www/html/dvswitch_cockpit/data/cache/subscriber_ids.lastgood.csv
```

This helps prevent QRZ/callsign links from disappearing just because a DVSwitch database update temporarily failed.

The setup script creates the cache directory and gives the web server permission to use it.

---

## 🧹 CLEARING DISPLAY HISTORY

If old activity rows look wrong after an update, clear Cockpit history:

```bash
sudo rm -f /var/www/html/dvswitch_cockpit/data/cache/gateway_history.json
```

If current runtime state looks stuck, clear Cockpit runtime cache:

```bash
sudo rm -f /var/www/html/dvswitch_cockpit/data/cache/runtime_state.json
```

Then refresh the browser.

---

## 🧪 TROUBLESHOOTING BASICS

### Page will not load

Check Apache:

```bash
sudo systemctl status apache2
```

Check PHP:

```bash
php -v
```

### Runtime status does not update

Run:

```bash
php /var/www/html/dvswitch_cockpit/api/runtime_status.php
```

### Restart buttons do not work

Check sudoers:

```bash
sudo visudo -cf /etc/sudoers.d/dvswitch-cockpit-services
```

Check services:

```bash
systemctl status analog_bridge.service
systemctl status mmdvm_bridge.service
```

### Callsigns do not resolve

Check the subscriber file:

```bash
ls -lh /var/lib/dvswitch/subscriber_ids.csv
```

Try looking up one DMR ID:

```bash
grep '^310997,' /var/lib/dvswitch/subscriber_ids.csv
```

Clear Cockpit's subscriber cache:

```bash
sudo rm -f /var/www/html/dvswitch_cockpit/data/cache/dmr_subscribers.json
```

---

## ♻️ SAFE ROLLBACK

If an update creates a problem, look for backup folders:

```bash
ls -ld /var/www/html/dvswitch_cockpit.backup.*
```

To restore one:

```bash
cd /var/www/html
sudo mv dvswitch_cockpit dvswitch_cockpit.bad
sudo cp -a dvswitch_cockpit.backup.YYYYMMDD-HHMMSS dvswitch_cockpit
sudo ./dvswitch_cockpit/setup_dvswitch_cockpit.sh
```

Replace `YYYYMMDD-HHMMSS` with the real backup folder name.

---

## 📁 IMPORTANT FILES

Main page:

```text
/var/www/html/dvswitch_cockpit/index.php
```

Main JavaScript:

```text
/var/www/html/dvswitch_cockpit/static/app.js
```

Main CSS:

```text
/var/www/html/dvswitch_cockpit/static/app.css
```

Runtime JSON endpoint:

```text
/var/www/html/dvswitch_cockpit/api/runtime_status.php
```

Service restart endpoint:

```text
/var/www/html/dvswitch_cockpit/api/service_action.php
```

Installer:

```text
/var/www/html/dvswitch_cockpit/setup_dvswitch_cockpit.sh
```

Screenshot included in repo:

```text
screenshot.png
```

---

## 🚦 SIMPLE RULES

### Edit these only if you know what you are doing:

```text
static/app.css
static/app.js
api/runtime_status.php
api/runtime/
setup_dvswitch_cockpit.sh
```

### Do not commit these:

```text
*.bak
*.zip
*.log
ABInfo_*.json
subscriber_ids.csv
config.ini
```

### Keep this project focused:

DVSwitch Cockpit should remain a status/display dashboard.

It should not become a connect/disconnect controller unless the project is intentionally redesigned.


### Runtime/cache files

DVSwitch Cockpit stores generated runtime/cache files inside the installed Cockpit directory:

```text
/var/www/html/dvswitch_cockpit/data/cache/dmr_subscribers.json
/var/www/html/dvswitch_cockpit/data/cache/gateway_history.json
/var/www/html/dvswitch_cockpit/data/cache/runtime_state.json
/var/www/html/dvswitch_cockpit/data/cache/subscriber_ids.lastgood.csv
```

These files are generated at runtime, ignored by Git, and not meant to be committed.

The repository includes `data/cache/.gitkeep` only so GitHub shows the expected cache directory. Runtime files in `data/cache/` are generated locally, ignored by Git, and should not be committed.

During updates, `setup_dvswitch_cockpit.sh` migrates older cache files from the previous temporary locations:

```text
/tmp/dvswitch_cockpit_dmr_subscribers.json
/tmp/dvswitch_cockpit_gateway_history.json
/tmp/dvswitch_cockpit_runtime_state.json
/var/cache/dvswitch-cockpit/subscriber_ids.lastgood.csv
```

Only those exact old Cockpit cache files are migrated/removed. DVSwitch-owned files such as `/var/lib/dvswitch/subscriber_ids.csv`, `/tmp/ABInfo_*.json`, `/opt/Analog_Bridge/*`, and `/opt/MMDVM_Bridge/*` are not moved or deleted.

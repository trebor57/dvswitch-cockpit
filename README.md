# DVSwitch Cockpit

## Modern Status Dashboard for ASL3 + DVSwitch

✅ Built for ASL3 + DVSwitch on Debian-based systems  
✅ Works with normal DVSwitch installs  
✅ Optional support for BM/STFU and TGIF/HBLink when present  
✅ No network control logic added  

DVSwitch Cockpit is a modern web dashboard for watching what your ASL3 / DVSwitch node is doing.

It gives you one clean place to see:

- current network / mode
- current talkgroup or target
- last heard station
- gateway activity
- local activity
- DMR ID to callsign lookup
- clickable QRZ callsign links
- YSF quality information when available
- Analog_Bridge and MMDVM_Bridge restart buttons

Simple. Clean. Read-only where it should be.

`screenshot.png` is included in the repo if you want to preview the web interface.

---

## ✨ What DVSwitch Cockpit can do

DVSwitch Cockpit is meant to be a one-screen status cockpit.

With it, you can:

- see whether your node is on BrandMeister, TGIF, YSF, D-Star, P25, NXDN, or another supported mode
- see the current talkgroup / target
- see recent Gateway Activity
- see recent Local Activity
- see DMR IDs resolved to callsigns when the DVSwitch subscriber database is available
- click resolved callsigns to open QRZ
- see YSF duration, loss, and BER when the backend provides it
- restart Analog_Bridge
- restart MMDVM_Bridge
- restart Analog_Bridge + MMDVM_Bridge together

---

## 🚫 What DVSwitch Cockpit does not do

DVSwitch Cockpit does **not** connect or disconnect networks or talkgroups.

It does not add network control logic. It is a status dashboard with limited service restart controls.

If you need mode or talkgroup control, use your normal DVSwitch tools or another controller.

---

## 🚀 Install

Run this on your ASL3 / DVSwitch node:

```bash
cd /var/www/html
git clone https://github.com/TerryClaiborne/dvswitch-cockpit.git dvswitch_cockpit
cd dvswitch_cockpit
sudo ./setup_dvswitch_cockpit.sh
```

Open:

```text
http://YOUR-NODE-IP/dvswitch_cockpit/
```

Example:

```text
http://192.168.1.120/dvswitch_cockpit/
```

---

## 🔄 Update

Run this from the Cockpit directory:

```bash
cd /var/www/html/dvswitch_cockpit
git pull origin main
sudo ./setup_dvswitch_cockpit.sh
```

Always run the setup script after `git pull`.

---

## 🛠️ If `git pull` says "not a git repository"

Your Cockpit folder was probably copied manually or installed from older instructions.

Fix it like this:

```bash
cd /var/www/html
sudo mv dvswitch_cockpit dvswitch_cockpit.old
git clone https://github.com/TerryClaiborne/dvswitch-cockpit.git dvswitch_cockpit
cd dvswitch_cockpit
sudo ./setup_dvswitch_cockpit.sh
```

After that, future updates are simple:

```bash
cd /var/www/html/dvswitch_cockpit
git pull origin main
sudo ./setup_dvswitch_cockpit.sh
```

---

## 📂 Runtime/cache files

DVSwitch Cockpit stores generated runtime/cache files here:

```text
/var/www/html/dvswitch_cockpit/data/cache/
```

Typical generated files:

```text
dmr_subscribers.json
gateway_history.json
runtime_state.json
subscriber_ids.lastgood.csv
```

These files are generated locally and ignored by Git.

The repo includes this placeholder:

```text
data/cache/.gitkeep
```

That file only makes GitHub show the expected cache directory.

Older Cockpit versions may have created cache files here:

```text
/tmp/dvswitch_cockpit_*.json
/var/cache/dvswitch-cockpit/
```

The setup script migrates those old Cockpit cache files into `data/cache/` when found.

---

## 📡 DMR callsign lookup

Cockpit uses the normal DVSwitch subscriber database when available:

```text
/var/lib/dvswitch/subscriber_ids.csv
```

If that file exists and is valid, Cockpit builds its own local lookup cache in:

```text
/var/www/html/dvswitch_cockpit/data/cache/dmr_subscribers.json
```

If the subscriber database is missing, Cockpit still loads. It will show numeric DMR IDs instead of resolved callsigns.

To update the DVSwitch subscriber database, use the normal DVSwitch update process. On many systems:

```bash
sudo /opt/MMDVM_Bridge/dvswitch.sh update
sudo /opt/MMDVM_Bridge/dvswitch.sh reloadDatabase
```

To clear Cockpit's DMR lookup cache:

```bash
sudo rm -f /var/www/html/dvswitch_cockpit/data/cache/dmr_subscribers.json
```

---

## 🔁 Restart buttons

The restart buttons require a sudoers rule so the web server can restart only the intended services.

The installer creates:

```text
/etc/sudoers.d/dvswitch-cockpit-services
```

Verify it with:

```bash
sudo visudo -cf /etc/sudoers.d/dvswitch-cockpit-services
```

The restart buttons are limited to:

- `analog_bridge.service`
- `mmdvm_bridge.service`

---

## 🧰 Troubleshooting

Check installed version:

```bash
cat /var/www/html/dvswitch_cockpit/VERSION
```

Check Git status:

```bash
cd /var/www/html/dvswitch_cockpit
git status --short
git log --oneline --decorate -5
```

Check the API:

```bash
curl -ksS https://127.0.0.1/dvswitch_cockpit/api/runtime_status.php
```

Clear Cockpit runtime history:

```bash
sudo rm -f /var/www/html/dvswitch_cockpit/data/cache/gateway_history.json
sudo rm -f /var/www/html/dvswitch_cockpit/data/cache/runtime_state.json
```

Clear DMR callsign cache:

```bash
sudo rm -f /var/www/html/dvswitch_cockpit/data/cache/dmr_subscribers.json
```

Re-run setup:

```bash
cd /var/www/html/dvswitch_cockpit
git pull origin main
sudo ./setup_dvswitch_cockpit.sh
```

---

## 📌 Important paths

```text
/var/www/html/dvswitch_cockpit
/var/www/html/dvswitch_cockpit/data/cache/
/etc/sudoers.d/dvswitch-cockpit-services
/etc/apache2/conf-available/dvswitch-cockpit-security.conf
```

---

## 📝 Notes

DVSwitch Cockpit observes the existing DVSwitch runtime.

It should not own, overwrite, or replace DVSwitch databases, Analog_Bridge configuration, MMDVM_Bridge configuration, or AllTune2 files.

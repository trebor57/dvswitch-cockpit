# GitHub readiness notes

DVSwitch Cockpit is intended to be shared as a status dashboard for both stock ASL3 / DVSwitch users and users with optional add-ons.

## Compatibility goals

- Stock ASL3 / DVSwitch with Analog Bridge and MMDVM Bridge
- Optional BM/STFU installations
- Optional TGIF/HBLink installations
- Optional AllTune2 private DVSwitch audio-node installations

## Important design boundary

This project is display/status/restart only. It does not connect or disconnect BM, TGIF, YSF, AllStarLink, or EchoLink.

## Optional feature behavior

HBLink and STFU are detected only when their service/process/log evidence exists. They are not required.

The TGIF/HBLink private-link check is used only when an AllTune2-style `MYNODE` and `DVSWITCH_NODE` config is present. Stock users without that config fall back to normal DMR/log evidence.

## Files intentionally not included

Do not commit real local runtime/config files such as:

- `/tmp/ABInfo_*.json`
- `/var/log/*`
- `/var/www/html/alltune*/config.ini`
- backup files
- zip/tar release archives

The `.gitignore` is set up to reduce accidental backup/archive commits.

## DMR subscriber/callsign lookup

The activity table can resolve numeric DMR IDs to callsigns using the local DVSwitch subscriber database. This is optional and must not be treated as a required dependency.

Expected behavior:

- If `subscriber_ids.csv` is present and readable, numeric DMR IDs may display as callsigns.
- Resolved callsigns link to QRZ in the browser.
- If the lookup file is missing or a DMR ID is not found, the raw numeric ID remains visible.
- No QRZ API credentials are needed.
- Do not commit a real subscriber database into the repository.

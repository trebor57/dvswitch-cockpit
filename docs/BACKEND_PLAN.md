# Backend Plan

## Main jobs
- read live JSON status files from `/tmp/ABInfo_*.json`
- read recent DVSwitch logs from `/var/log/dvswitch/`
- report service state for approved units
- expose safe service actions through allow-listed commands only

## Safety
- no arbitrary shell commands from the browser
- no machine-specific secrets committed
- no edits to the original DVSwitch installation

## Supported network scope
The data model should be broad enough for:
- BrandMeister (STFU path)
- TGIF
- YSF
- D-Star
- P25
- NXDN

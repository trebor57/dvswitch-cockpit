# DVSwitch Cockpit recovery notes

This package was cleaned up after a failed patch loop. The main lesson is that the cockpit is not the network controller.

## Verified architecture

- `static/app.js` renders JSON from `api/runtime_status.php`.
- `api/service_action.php` restarts local services only.
- Runtime state is resolved in `api/runtime/*`.
- BM/TGIF/YSF connect/disconnect actions belong outside this project.

## Fixes applied in this cleaned package

- Kept the modular adapter architecture.
- Made TGIF/HBLink target display safer when the gateway ID appears as `txTg` or `dst`.
- Made the resolver choose the freshest connected DMR adapter when BM-stock and TGIF both appear valid.
- Made HBLink detection accept common service names and process evidence instead of only `hblink.service`.
- Removed the separate Restart DVSwitch Server button from the UI and sudoers path; the visible restart controls are Analog Bridge, MMDVM Bridge, and Analog + MMDVM together.
- Made the sudoers example match the exact commands used by the PHP endpoint.
- Changed root `index.html` into a redirect so it cannot override `index.php` on Apache installs where `.htaccess` is ignored.
- Added `setup_dvswitch_cockpit.sh` for repeatable installation/update.

## Still out of scope

- Fixing upstream BM/TGIF/YSF connect/disconnect behavior.
- Forcing the true TGIF runtime target.
- Replacing AllTune2, STFU, HBLink, or DVSwitch control scripts.

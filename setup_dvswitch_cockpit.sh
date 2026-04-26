#!/usr/bin/env bash
set -euo pipefail

APP_NAME="DVSwitch Cockpit"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST_DIR="${DEST_DIR:-/var/www/html/dvswitch_cockpit}"
SUDOERS_FILE="${SUDOERS_FILE:-/etc/sudoers.d/dvswitch-cockpit-services}"
CACHE_DIR="${CACHE_DIR:-/var/cache/dvswitch-cockpit}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"

if [[ $EUID -ne 0 ]]; then
  echo "Please run as root: sudo ./setup_dvswitch_cockpit.sh" >&2
  exit 1
fi

echo "============================================================"
echo "$APP_NAME setup"
echo "============================================================"
echo "Source:      $SRC_DIR"
echo "Destination: $DEST_DIR"
echo

echo "[1/6] Checking basic packages..."
if command -v apt-get >/dev/null 2>&1; then
  NEED_PKGS=()
  command -v apache2 >/dev/null 2>&1 || NEED_PKGS+=(apache2)
  command -v php >/dev/null 2>&1 || NEED_PKGS+=(php)
  command -v unzip >/dev/null 2>&1 || NEED_PKGS+=(unzip)

  if [[ ${#NEED_PKGS[@]} -gt 0 ]]; then
    echo "Installing missing packages: ${NEED_PKGS[*]}"
    apt-get update
    apt-get install -y "${NEED_PKGS[@]}"
  else
    echo "Required basic packages already present."
  fi
else
  echo "apt-get not found; skipping package install check."
fi

echo
echo "[2/6] Preparing destination..."
mkdir -p "$DEST_DIR"

SRC_REAL="$(realpath "$SRC_DIR")"
DEST_REAL="$(realpath "$DEST_DIR")"

if [[ "$SRC_REAL" == "$DEST_REAL" ]]; then
  echo "Source and destination are the same directory."
  echo "Skipping file copy and only applying permissions/sudoers."
else
  if [[ -d "$DEST_DIR" && -n "$(find "$DEST_DIR" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]]; then
    BACKUP="${DEST_DIR}.backup.$(date +%Y%m%d-%H%M%S)"
    echo "Existing install found. Creating backup:"
    echo "$BACKUP"
    cp -a "$DEST_DIR" "$BACKUP"
  fi

  echo "Copying files..."
  if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete \
      --exclude '.git' \
      --exclude '__pycache__' \
      --exclude '*.pyc' \
      --exclude '*.pyo' \
      --exclude '*.pyd' \
      --exclude '*.bak' \
      --exclude '*.old' \
      --exclude '*.orig' \
      --exclude '*.tmp' \
      --exclude '*.zip' \
      --exclude '*.tar' \
      --exclude '*.tar.gz' \
      --exclude '*.tgz' \
      --exclude '*~' \
      "$SRC_DIR/" "$DEST_DIR/"
  else
    echo "rsync not found; using tar fallback."
    find "$DEST_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    (cd "$SRC_DIR" && tar \
      --exclude='./.git' \
      --exclude='./__pycache__' \
      --exclude='*.pyc' \
      --exclude='*.pyo' \
      --exclude='*.pyd' \
      --exclude='*.bak' \
      --exclude='*.old' \
      --exclude='*.orig' \
      --exclude='*.tmp' \
      --exclude='*.zip' \
      --exclude='*.tar' \
      --exclude='*.tar.gz' \
      --exclude='*.tgz' \
      --exclude='*~' \
      -cf - .) | (cd "$DEST_DIR" && tar -xf -)
  fi
fi

echo
echo "[3/6] Applying ownership and permissions..."
mkdir -p "$CACHE_DIR"
if id "$WEB_USER" >/dev/null 2>&1; then
  chown -R "$WEB_USER:$WEB_GROUP" "$DEST_DIR" || true
  chown -R "$WEB_USER:$WEB_GROUP" "$CACHE_DIR" || true
else
  echo "Warning: web user '$WEB_USER' not found; ownership unchanged."
fi
chmod 0755 "$CACHE_DIR" || true

find "$DEST_DIR" -type d -exec chmod 0755 {} +
find "$DEST_DIR" -type f -exec chmod 0644 {} +
chmod 0755 "$DEST_DIR/setup_dvswitch_cockpit.sh" 2>/dev/null || true

echo
echo "[4/6] Installing sudoers rule..."
cat > "$SUDOERS_FILE" <<EOF_SUDOERS
# Managed by setup_dvswitch_cockpit.sh
# Allows the DVSwitch Cockpit web UI to restart DVSwitch services
# and read AllStarLink node-link state for TGIF private audio detection.
$WEB_USER ALL=(root) NOPASSWD: /usr/bin/systemctl restart analog_bridge.service, /usr/bin/systemctl restart mmdvm_bridge.service, /usr/sbin/asterisk -rx rpt nodes *
EOF_SUDOERS

chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE"

echo
echo "[5/6] Reloading Apache if available..."
if systemctl list-unit-files apache2.service >/dev/null 2>&1; then
  systemctl reload apache2 || systemctl restart apache2 || true
else
  echo "apache2.service not found; skipping Apache reload."
fi

echo
echo "[6/6] Setup summary"
echo "Install path: $DEST_DIR"
echo "Sudoers:     $SUDOERS_FILE"
echo "Cache dir:   $CACHE_DIR"
echo
echo "Open:"
echo "  http://<node-ip>/dvswitch_cockpit/"
echo
echo "Notes:"
echo "  - Cockpit reads runtime state only."
echo "  - It does not perform BM/TGIF/YSF connect or disconnect actions."
echo "  - Restart buttons are limited to Analog_Bridge and MMDVM_Bridge."
echo "  - DMR callsign lookup uses the existing DVSwitch subscriber database when available."
echo "  - A last-known-good subscriber fallback is kept in $CACHE_DIR when possible."
echo
echo "Setup complete."

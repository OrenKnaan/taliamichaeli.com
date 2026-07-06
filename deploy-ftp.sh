#!/usr/bin/env bash
# Uploads the tracked custom-code files (child theme + MailerLite plugin) to
# uPress over plain FTP. Assumes the FTP account is chrooted to the WordPress
# root, so remote paths mirror the local wp-content/... paths exactly.
#
# NOT YET VERIFIED: this has not completed a real transfer. The dev machine
# this was written on could reach the FTP control port but not the data
# channel (listings/uploads), so confirm connectivity with a GUI client
# (FileZilla/Cyberduck) or from the real deploy host before relying on this.
set -euo pipefail

cd "$(dirname "$0")"

if [ ! -f .env.ftp ]; then
  echo "Missing .env.ftp (FTP_HOST, FTP_PORT, FTP_USER, FTP_PASS). Not committed to git on purpose." >&2
  exit 1
fi
# shellcheck disable=SC1091
source .env.ftp

FILES=(
  "wp-content/themes/hello-elementor-talia/functions.php"
  "wp-content/themes/hello-elementor-talia/style.css"
  "wp-content/plugins/custom-mailerlite-integration/custom-mailerlite-integration.php"
)

for f in "${FILES[@]}"; do
  echo "Uploading $f ..."
  curl -sS --ftp-create-dirs \
    --user "${FTP_USER}:${FTP_PASS}" \
    -T "$f" \
    "ftp://${FTP_HOST}:${FTP_PORT}/${f}"
done

echo "Done."

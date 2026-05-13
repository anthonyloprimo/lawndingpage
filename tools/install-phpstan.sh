#!/usr/bin/env bash
# Download a pinned PHPStan PHAR into tools/phpstan.phar with SHA256 verification.
#
# Usage:
#   bash tools/install-phpstan.sh         # download + verify if missing
#   bash tools/install-phpstan.sh --force # re-download even if present
#
# Why pin: PHPStan ships new checks across minor versions, and a green
# CI run today must mean the same thing six months from now. Bumping is
# a deliberate edit here (update VERSION + SHA256, run with --force,
# commit the diff alongside any new findings addressed at that level).
#
# Updating the pin:
#   1. Pick a release from https://github.com/phpstan/phpstan/releases
#   2. Edit VERSION below
#   3. Run: bash tools/install-phpstan.sh --force
#      The script will print the "Computed SHA" of what it downloaded.
#   4. Paste that into SHA256 below.
#   5. Run once more to confirm the new pin verifies.
#   6. Re-run the full suite; address any new findings in the same commit.

set -euo pipefail

VERSION="2.1.54"
SHA256="b03ce93d4e0224c543090f2dc5258caaae35b62958aa80616456b8a5ae1541d4"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHAR_PATH="$SCRIPT_DIR/phpstan.phar"
URL="https://github.com/phpstan/phpstan/releases/download/$VERSION/phpstan.phar"

force=""
if [[ "${1:-}" == "--force" ]]; then
    force=1
fi

command -v curl >/dev/null      || { echo "curl required" >&2; exit 1; }
command -v sha256sum >/dev/null  || { echo "sha256sum required" >&2; exit 1; }

verify_sha() {
    local file="$1"
    local actual
    actual="$(sha256sum "$file" | awk '{print $1}')"
    if [[ "$actual" == "$SHA256" ]]; then
        return 0
    fi
    echo "SHA256 mismatch:" >&2
    echo "  Expected: $SHA256" >&2
    echo "  Computed: $actual" >&2
    return 1
}

if [[ -z "$force" && -f "$PHAR_PATH" ]]; then
    if verify_sha "$PHAR_PATH" 2>/dev/null; then
        echo "phpstan.phar $VERSION already present and verified."
        exit 0
    fi
    echo "Existing phpstan.phar fails SHA check — redownloading."
fi

echo "Downloading PHPStan $VERSION..."
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
curl -fsSL "$URL" -o "$tmp"

if ! verify_sha "$tmp"; then
    exit 1
fi

mv "$tmp" "$PHAR_PATH"
chmod +x "$PHAR_PATH"
trap - EXIT
echo "Installed phpstan.phar $VERSION (verified)."

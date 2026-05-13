#!/usr/bin/env bash
# Download a pinned phpDocumentor PHAR into tools/phpDocumentor.phar
# with SHA256 verification.
#
# Usage:
#   bash tools/install-phpdocumentor.sh         # download + verify if missing
#   bash tools/install-phpdocumentor.sh --force # re-download even if present
#
# Why pin: phpDocumentor's rendering output can shift across minor
# versions (template tweaks, type-narrowing rules, parser fixes). A
# published docs site that changes shape every time CI runs is
# confusing. Bumping is a deliberate edit here (update VERSION + SHA,
# run with --force, commit).
#
# Updating the pin:
#   1. Pick a release from https://github.com/phpDocumentor/phpDocumentor/releases
#   2. Edit VERSION below
#   3. Run: bash tools/install-phpdocumentor.sh --force
#      The script will print the "Computed SHA" of what it downloaded.
#   4. Paste that into SHA256 below.
#   5. Run once more to confirm the new pin verifies.
#   6. Generate docs locally (`php tools/phpDocumentor.phar`) and skim
#      the output for any rendering surprises before committing.

set -euo pipefail

VERSION="v3.9.1"
SHA256="ef5509af790c8e56d11fff8162f12c7c19473b9af6e79b0fb9a62aff26da2ea5"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHAR_PATH="$SCRIPT_DIR/phpDocumentor.phar"
URL="https://github.com/phpDocumentor/phpDocumentor/releases/download/$VERSION/phpDocumentor.phar"

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
        echo "phpDocumentor.phar $VERSION already present and verified."
        exit 0
    fi
    echo "Existing phpDocumentor.phar fails SHA check — redownloading."
fi

echo "Downloading phpDocumentor $VERSION..."
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
curl -fsSL "$URL" -o "$tmp"

if ! verify_sha "$tmp"; then
    exit 1
fi

mv "$tmp" "$PHAR_PATH"
chmod +x "$PHAR_PATH"
trap - EXIT
echo "Installed phpDocumentor.phar $VERSION (verified)."

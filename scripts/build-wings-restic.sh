#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="${PROJECT_ROOT:-$SCRIPT_DIR}"
BUILD_ROOT="${BUILD_ROOT:-$PROJECT_ROOT/.build/wings-restic}"
OUTPUT_DIR="${OUTPUT_DIR:-$PROJECT_ROOT}"
ADDON_ROOT="${ADDON_ROOT:-}"
PATCH_FILE=""
OUTPUT_BIN=""

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

latest_tag() {
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL https://api.github.com/repos/pterodactyl/wings/releases/latest \
      | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' \
      | head -n 1
  fi
}

find_addon_root() {
  if [ -n "$ADDON_ROOT" ] && [ -d "$ADDON_ROOT/ibyte/restic" ]; then
    return 0
  fi

  for candidate in \
    "$SCRIPT_DIR/wings-addon" \
    "$SCRIPT_DIR/../wings-addon" \
    "$SCRIPT_DIR/.." \
    "$PROJECT_ROOT/wings-addon" \
    "/etc/pterodactyl/wings-addon"
  do
    if [ -d "$candidate/ibyte/restic" ]; then
      ADDON_ROOT="$(cd "$candidate" && pwd)"
      return 0
    fi
  done

  return 1
}

download_addon_archive() {
  if [ -z "${ADDON_ARCHIVE_URL:-}" ]; then
    return 1
  fi

  require_command curl
  require_command tar

  local addon_build_root="$BUILD_ROOT/addon"
  local archive_path="$BUILD_ROOT/wings-addon.tar.gz"

  rm -rf "$addon_build_root"
  mkdir -p "$addon_build_root"

  echo "Downloading iByte Restic Wings add-on bundle..."
  curl -fsSL "$ADDON_ARCHIVE_URL" -o "$archive_path"
  tar -xzf "$archive_path" -C "$addon_build_root"

  for candidate in \
    "$addon_build_root/wings-addon" \
    "$addon_build_root"
  do
    if [ -d "$candidate/ibyte/restic" ]; then
      ADDON_ROOT="$(cd "$candidate" && pwd)"
      return 0
    fi
  done

  echo "Downloaded add-on archive did not contain ibyte/restic." >&2
  return 1
}

download_addon_files() {
  if [ -z "${ADDON_BASE_URL:-}" ]; then
    return 1
  fi

  require_command curl

  local base_url="${ADDON_BASE_URL%/}"
  local addon_build_root="$BUILD_ROOT/downloaded-addon"

  rm -rf "$addon_build_root"
  mkdir -p "$addon_build_root/ibyte/restic" "$addon_build_root/patches"

  echo "Downloading iByte Restic Wings add-on files..."
  for file in routes.go service.go handlers_backups.go handlers_admin_download.go; do
    curl -fsSL "$base_url/ibyte/restic/$file" -o "$addon_build_root/ibyte/restic/$file"
  done
  curl -fsSL "$base_url/patches/router-v1.13.1.patch" -o "$addon_build_root/patches/router-v1.13.1.patch"

  ADDON_ROOT="$(cd "$addon_build_root" && pwd)"
  return 0
}

WINGS_VERSION="${WINGS_VERSION:-$(latest_tag)}"
WINGS_VERSION="${WINGS_VERSION:-v1.13.1}"
OUTPUT_BIN="$OUTPUT_DIR/wings-restic-$WINGS_VERSION"

require_command go
require_command gofmt
require_command patch

if ! command -v git >/dev/null 2>&1; then
  require_command curl
  require_command tar
fi

rm -rf "$BUILD_ROOT"
mkdir -p "$BUILD_ROOT" "$OUTPUT_DIR"

if ! find_addon_root && ! download_addon_archive && ! download_addon_files; then
  echo "Missing add-on package." >&2
  echo >&2
  echo "This script can run from /etc/pterodactyl, but it still needs the add-on source." >&2
  echo "Put the wings-addon folder beside this script:" >&2
  echo "  /etc/pterodactyl/wings-addon/ibyte/restic" >&2
  echo "  /etc/pterodactyl/wings-addon/patches/router-v1.13.1.patch" >&2
  echo >&2
  echo "Or point ADDON_ROOT at the folder:" >&2
  echo "  ADDON_ROOT=/path/to/wings-addon bash ./wingsrestic.sh" >&2
  echo >&2
  echo "Or provide a tar.gz bundle URL:" >&2
  echo "  ADDON_ARCHIVE_URL=https://example.com/wings-addon.tar.gz bash ./wingsrestic.sh" >&2
  echo >&2
  echo "Or provide a GitHub raw base URL:" >&2
  echo "  ADDON_BASE_URL=https://raw.githubusercontent.com/OWNER/REPO/BRANCH/wings-addon bash ./wingsrestic.sh" >&2
  exit 1
fi

PATCH_FILE="$ADDON_ROOT/patches/router-v1.13.1.patch"

if [ ! -f "$PATCH_FILE" ]; then
  echo "Missing router patch: $PATCH_FILE" >&2
  echo "Make sure the full wings-addon folder was uploaded, not only this script." >&2
  exit 1
fi

echo "Downloading official pterodactyl/wings $WINGS_VERSION..."
if command -v git >/dev/null 2>&1; then
  git clone --depth 1 --branch "$WINGS_VERSION" https://github.com/pterodactyl/wings.git "$BUILD_ROOT/src"
else
  curl -fsSL "https://github.com/pterodactyl/wings/archive/refs/tags/$WINGS_VERSION.tar.gz" -o "$BUILD_ROOT/wings.tar.gz"
  tar -xzf "$BUILD_ROOT/wings.tar.gz" -C "$BUILD_ROOT"
  mv "$BUILD_ROOT"/wings-* "$BUILD_ROOT/src"
fi

echo "Copying iByte Restic add-on package..."
mkdir -p "$BUILD_ROOT/src/ibyte"
cp -R "$ADDON_ROOT/ibyte/restic" "$BUILD_ROOT/src/ibyte/restic"

echo "Applying one-line router registration patch..."
cd "$BUILD_ROOT/src"
if ! patch -p1 < "$PATCH_FILE"; then
  echo "Patch failed. Wings router.go likely changed. Rebase only the import and ibyterestic.Register(...) line." >&2
  exit 1
fi

echo "Formatting add-on code..."
gofmt -w ibyte/restic router/router.go

echo "Building custom Wings binary..."
if command -v make >/dev/null 2>&1; then
  make build
  if [ -f wings ]; then
    cp wings "$OUTPUT_BIN"
  elif [ -f build/wings ]; then
    cp build/wings "$OUTPUT_BIN"
  else
    echo "Build completed, but no Wings binary was found at ./wings or ./build/wings." >&2
    exit 1
  fi
else
  go build -trimpath -o "$OUTPUT_BIN" .
fi

if [ ! -s "$OUTPUT_BIN" ]; then
  echo "Build failed: output binary is missing or empty: $OUTPUT_BIN" >&2
  exit 1
fi

chmod +x "$OUTPUT_BIN"

echo "Custom Wings binary created:"
ls -lah "$OUTPUT_BIN"

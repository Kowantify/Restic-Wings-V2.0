#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-$(pwd)}"
BUILD_DIR="${BUILD_DIR:-$ROOT/.build/wings-restic-installer}"
ADDON_REPO_URL="${ADDON_REPO_URL:-https://github.com/Kowantify/Restic-Wings-V2.0.git}"
ADDON_BRANCH="${ADDON_BRANCH:-}"

need() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Missing required command: $1" >&2
    exit 1
  }
}

clone_repo() {
  local url="$1"
  local dest="$2"
  if [ -n "$ADDON_BRANCH" ]; then
    git clone --depth 1 --branch "$ADDON_BRANCH" "$url" "$dest"
  else
    git clone --depth 1 "$url" "$dest"
  fi
}

need git

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

echo "Downloading iByte Restic Wings installer from $ADDON_REPO_URL"
clone_repo "$ADDON_REPO_URL" "$BUILD_DIR/source"

SCRIPT=""
for candidate in \
  "$BUILD_DIR/source/wings-addon/scripts/build-wings-restic.sh" \
  "$BUILD_DIR/source/scripts/build-wings-restic.sh" \
  "$BUILD_DIR/source/build-wings-restic.sh"
do
  if [ -f "$candidate" ]; then
    SCRIPT="$candidate"
    break
  fi
done

if [ -z "$SCRIPT" ]; then
  echo "Could not find build-wings-restic.sh in downloaded repository." >&2
  exit 1
fi

echo "Running $SCRIPT"
bash "$SCRIPT"

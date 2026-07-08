#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-$(pwd)}"
BUILD_DIR="${BUILD_DIR:-$ROOT/.build/wings-restic}"
OUT_DIR="${OUT_DIR:-$ROOT}"

ADDON_REPO_URL="${ADDON_REPO_URL:-https://github.com/Kowantify/Restic-Wings-V2.0.git}"
ADDON_BRANCH="${ADDON_BRANCH:-}"
ADDON_PATH="${ADDON_PATH:-wings-addon}"

WINGS_VERSION="${WINGS_VERSION:-v1.13.1}"
GO_VERSION_REQUIRED="${GO_VERSION_REQUIRED:-1.24.0}"
OUT_BIN="$OUT_DIR/wings-restic-$WINGS_VERSION"
INSTALL_WINGS="${INSTALL_WINGS:-1}"
WINGS_BIN="${WINGS_BIN:-/usr/local/bin/wings}"
WINGS_SERVICE="${WINGS_SERVICE:-wings}"

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

install_go() {
  local arch
  case "$(uname -m)" in
    x86_64|amd64) arch="amd64" ;;
    aarch64|arm64) arch="arm64" ;;
    *)
      echo "Unsupported CPU architecture for automatic Go install: $(uname -m)" >&2
      exit 1
      ;;
  esac

  local tarball="go${GO_VERSION_REQUIRED}.linux-${arch}.tar.gz"
  local url="https://go.dev/dl/${tarball}"
  local tmp="/tmp/${tarball}"

  need curl
  need tar

  echo "Installing Go ${GO_VERSION_REQUIRED} from ${url}"
  curl -fsSL "$url" -o "$tmp"
  rm -rf /usr/local/go
  tar -C /usr/local -xzf "$tmp"
  rm -f "$tmp"
  export PATH="/usr/local/go/bin:$PATH"
}

go_is_new_enough() {
  command -v go >/dev/null 2>&1 || return 1
  local version
  version="$(go env GOVERSION 2>/dev/null | sed 's/^go//')"
  case "$version" in
    1.24*|1.25*|1.26*|1.27*|1.28*|1.29*|[2-9].*) return 0 ;;
    *) return 1 ;;
  esac
}

install_runtime_tool() {
  local package="$1"
  local binary="$2"

  if command -v "$binary" >/dev/null 2>&1; then
    return 0
  fi

  echo "Installing $package runtime dependency"
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y "$package"
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y "$package"
  elif command -v yum >/dev/null 2>&1; then
    yum install -y "$package"
  elif command -v apk >/dev/null 2>&1; then
    apk add --no-cache "$package"
  else
    echo "Could not install $package automatically. Please install it and rerun this script." >&2
    exit 1
  fi

  need "$binary"
}

need git
need perl

install_runtime_tool restic restic

if ! go_is_new_enough; then
  install_go
fi

need go
need gofmt

echo "Using $(go version)"

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR" "$OUT_DIR"

echo "Cloning iByte Restic add-on from $ADDON_REPO_URL"
clone_repo "$ADDON_REPO_URL" "$BUILD_DIR/addon-src"
ADDON_ROOT="$BUILD_DIR/addon-src/$ADDON_PATH"

if [ ! -d "$ADDON_ROOT/ibyte/restic" ]; then
  if [ -d "$BUILD_DIR/addon-src/ibyte/restic" ]; then
    ADDON_ROOT="$BUILD_DIR/addon-src"
  elif [ -d "$BUILD_DIR/addon-src/wings-addon/ibyte/restic" ]; then
    ADDON_ROOT="$BUILD_DIR/addon-src/wings-addon"
  else
    echo "Missing add-on package." >&2
    echo "Expected one of:" >&2
    echo "  $BUILD_DIR/addon-src/$ADDON_PATH/ibyte/restic" >&2
    echo "  $BUILD_DIR/addon-src/ibyte/restic" >&2
    echo "  $BUILD_DIR/addon-src/wings-addon/ibyte/restic" >&2
    echo "If the repo layout is different, set ADDON_PATH. Example: ADDON_PATH=. ./wingsrestic.sh" >&2
    exit 1
  fi
fi

echo "Using add-on path: $ADDON_ROOT"

echo "Cloning official pterodactyl/wings $WINGS_VERSION"
git clone --depth 1 --branch "$WINGS_VERSION" https://github.com/pterodactyl/wings.git "$BUILD_DIR/src"

echo "Installing add-on package"
mkdir -p "$BUILD_DIR/src/ibyte"
cp -R "$ADDON_ROOT/ibyte/restic" "$BUILD_DIR/src/ibyte/restic"

echo "Patching Wings router"
cd "$BUILD_DIR/src"
if ! grep -q 'github.com/pterodactyl/wings/ibyte/restic' router/router.go; then
  perl -0pi -e 's#("github.com/pterodactyl/wings/router/middleware"\n)#$1\tibyterestic "github.com/pterodactyl/wings/ibyte/restic"\n#' router/router.go
fi
if ! grep -q 'ibyterestic.Register(server.Group("/restic"))' router/router.go; then
  perl -0pi -e 's#(\n\t\tbackup := server\.Group\("/backup"\)\n\t\t\{\n\t\t\tbackup\.POST\("", postServerBackup\)\n\t\t\tbackup\.POST\("/:backup/restore", postServerRestoreBackup\)\n\t\t\tbackup\.DELETE\("/:backup", deleteServerBackup\)\n\t\t\}\n)#$1\n\t\tibyterestic.Register(server.Group("/restic"))\n#s' router/router.go
fi
if ! grep -q 'ibyterestic.Register(server.Group("/restic"))' router/router.go; then
  echo "Failed to patch Wings router; router layout changed." >&2
  exit 1
fi

echo "Building Wings"
gofmt -w ibyte/restic router/router.go
if command -v make >/dev/null 2>&1; then
  make build
  if [ -f wings ]; then
    cp wings "$OUT_BIN"
  elif [ -f build/wings ]; then
    cp build/wings "$OUT_BIN"
  else
    echo "Build finished, but no wings binary was found." >&2
    exit 1
  fi
else
  go build -trimpath -o "$OUT_BIN" .
fi

chmod +x "$OUT_BIN"
echo "Created:"
ls -lah "$OUT_BIN"

if [ "$INSTALL_WINGS" = "0" ] || [ "$INSTALL_WINGS" = "false" ]; then
  echo "Skipping install because INSTALL_WINGS=$INSTALL_WINGS"
  exit 0
fi

if [ "$(id -u)" -ne 0 ]; then
  echo "Built successfully, but installing to $WINGS_BIN requires root." >&2
  echo "Rerun with sudo, or use INSTALL_WINGS=0 to build only." >&2
  exit 1
fi

if ! command -v systemctl >/dev/null 2>&1; then
  echo "Built successfully, but systemctl was not found. Install manually:" >&2
  echo "  cp $OUT_BIN $WINGS_BIN" >&2
  echo "  chmod +x $WINGS_BIN" >&2
  exit 1
fi

backup_path=""
if [ -f "$WINGS_BIN" ]; then
  backup_path="$WINGS_BIN.backup.$(date +%Y%m%d-%H%M%S)"
  echo "Backing up current Wings binary to $backup_path"
  cp "$WINGS_BIN" "$backup_path"
fi

echo "Stopping $WINGS_SERVICE"
systemctl stop "$WINGS_SERVICE" || true

echo "Installing custom Wings binary to $WINGS_BIN"
install -m 0755 "$OUT_BIN" "$WINGS_BIN"

echo "Starting $WINGS_SERVICE"
systemctl start "$WINGS_SERVICE"

echo "Wings service status:"
systemctl status "$WINGS_SERVICE" --no-pager || true

echo "Production install complete."
if [ -n "$backup_path" ]; then
  echo "Previous binary backup: $backup_path"
fi

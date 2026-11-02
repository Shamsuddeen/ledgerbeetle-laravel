#!/usr/bin/env bash
# Build resources/lib/<platform>/: the FFI shim (native/ledgerbeetle_tb.c)
# plus TigerBeetle's official libtb_client shared library it links against.
#
# libtb_client comes from TigerBeetle's `tigerbeetle` wheel on PyPI, which
# bundles prebuilt libraries for every platform. The wheel is checked against
# the SHA-256 PyPI publishes for it before anything is extracted.
#
# Usage: bin/build-native.sh [tb-version]      (default: 0.17.9)
#        The version must match native/tb_client.h.
# Env:   TB_WHEEL=path   use an already-downloaded wheel (still verified)
#        CC=cc           C compiler (default: cc)
# Needs: curl, unzip, php, a C compiler.
set -euo pipefail

TB_VERSION="${1:-0.17.9}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CC="${CC:-cc}"

libc=gnu.2.27
if [[ "$(uname -s)" == Linux ]] && ldd --version 2>&1 | grep -qi musl; then
    libc=musl
fi

case "$(uname -s)-$(uname -m)" in
    Darwin-arm64)            PLATFORM=aarch64-macos; TB_DIR=aarch64-macos;        EXT=dylib ;;
    Darwin-x86_64)           PLATFORM=x86_64-macos;  TB_DIR=x86_64-macos;         EXT=dylib ;;
    Linux-aarch64|Linux-arm64) PLATFORM=aarch64-linux; TB_DIR=aarch64-linux-$libc; EXT=so ;;
    Linux-x86_64)            PLATFORM=x86_64-linux;  TB_DIR=x86_64-linux-$libc;   EXT=so ;;
    *) echo "Unsupported platform: $(uname -s)-$(uname -m)" >&2; exit 1 ;;
esac

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Wheel URL and published digest from PyPI's JSON API.
read -r WHEEL_URL WHEEL_SHA < <(curl -fsSL "https://pypi.org/pypi/tigerbeetle/${TB_VERSION}/json" | php -r '
    $release = json_decode(stream_get_contents(STDIN), true);
    foreach ($release["urls"] ?? [] as $u) {
        if (str_ends_with($u["filename"], "-py3-none-any.whl")) { echo $u["url"], " ", $u["digests"]["sha256"], "\n"; exit; }
    }
    fwrite(STDERR, "No universal wheel found for this version\n"); exit(1);')

WHEEL="${TB_WHEEL:-$WORK/tigerbeetle.whl}"
if [[ -z "${TB_WHEEL:-}" ]]; then
    echo "Fetching tigerbeetle ${TB_VERSION} wheel from PyPI"
    curl -fsSL -o "$WHEEL" "$WHEEL_URL"
fi

actual="$(php -r 'echo hash_file("sha256", $argv[1]);' "$WHEEL")"
if [[ "$actual" != "$WHEEL_SHA" ]]; then
    echo "SHA-256 mismatch for the wheel: expected ${WHEEL_SHA}, got ${actual}" >&2
    exit 1
fi
echo "Wheel verified (sha256 ${WHEEL_SHA})"

TB_LIB="libtb_client.$EXT"
unzip -q -j "$WHEEL" "tigerbeetle/lib/${TB_DIR}/${TB_LIB}" -d "$WORK"

OUT_DIR="$ROOT/resources/lib/$PLATFORM"
mkdir -p "$OUT_DIR"
cp "$WORK/$TB_LIB" "$OUT_DIR/$TB_LIB"

# The shim finds libtb_client next to itself.
if [[ "$EXT" == dylib ]]; then
    RPATH='-Wl,-rpath,@loader_path -Wl,-install_name,@rpath/libledgerbeetle_tb.dylib'
else
    RPATH='-Wl,-rpath,$ORIGIN'
fi

"$CC" -O2 -Wall -Wextra -shared -fPIC -I"$ROOT/native" -o "$OUT_DIR/libledgerbeetle_tb.$EXT" \
    "$ROOT/native/ledgerbeetle_tb.c" -L"$OUT_DIR" -ltb_client $RPATH -lpthread

echo "$TB_VERSION ($TB_DIR)" > "$OUT_DIR/TB_VERSION"
echo "Built $OUT_DIR/libledgerbeetle_tb.$EXT against tb_client ${TB_VERSION} (${TB_DIR})"

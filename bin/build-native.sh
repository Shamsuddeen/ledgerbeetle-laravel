#!/usr/bin/env bash
# Build resources/lib/<platform>/libledgerbeetle_tb.(so|dylib): the FFI shim in
# native/ledgerbeetle_tb.c linked with TigerBeetle's official static tb_client.
#
# The static library and header come from the tigerbeetle-go module, where
# TigerBeetle publishes them for every release. Each file is checked against
# the git blob hash GitHub reports for that tag before it is used.
#
# Usage: bin/build-native.sh [tb-version]      (default: 0.17.9)
# Env:   TB_NATIVE_DIR=dir  use already-downloaded files instead of fetching
#        CC=cc              C compiler (default: cc)
set -euo pipefail

TB_VERSION="${1:-0.17.9}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CC="${CC:-cc}"

case "$(uname -s)-$(uname -m)" in
    Darwin-arm64)  PLATFORM=aarch64-macos; EXT=dylib ;;
    Darwin-x86_64) PLATFORM=x86_64-macos;  EXT=dylib ;;
    Linux-aarch64) PLATFORM=aarch64-linux; EXT=so ;;
    Linux-x86_64)  PLATFORM=x86_64-linux;  EXT=so ;;
    *) echo "Unsupported platform: $(uname -s)-$(uname -m)" >&2; exit 1 ;;
esac

LIB="libtb_client_${PLATFORM}.a"
SRC_DIR="${TB_NATIVE_DIR:-}"

if [[ -z "$SRC_DIR" ]]; then
    SRC_DIR="$(mktemp -d)"
    trap 'rm -rf "$SRC_DIR"' EXIT
    base="https://raw.githubusercontent.com/tigerbeetle/tigerbeetle-go/v${TB_VERSION}/native"
    for f in "$LIB" tb_client.h; do
        echo "Fetching $f (tigerbeetle-go v${TB_VERSION})"
        curl -fsSL -o "$SRC_DIR/$f" "$base/$f"
    done
fi

echo "Verifying against the v${TB_VERSION} git tree"
tree="$(curl -fsSL "https://api.github.com/repos/tigerbeetle/tigerbeetle-go/git/trees/v${TB_VERSION}?recursive=1")"
for f in "$LIB" tb_client.h; do
    expected="$(printf '%s' "$tree" | php -r '
        $t = json_decode(stream_get_contents(STDIN), true)["tree"] ?? [];
        foreach ($t as $e) { if ($e["path"] === "native/".$argv[1]) { echo $e["sha"]; } }' "$f")"
    actual="$(git hash-object "$SRC_DIR/$f")"
    if [[ -z "$expected" || "$expected" != "$actual" ]]; then
        echo "Hash mismatch for $f: expected '${expected}', got '${actual}'" >&2
        exit 1
    fi
done

OUT_DIR="$ROOT/resources/lib/$PLATFORM"
OUT="$OUT_DIR/libledgerbeetle_tb.$EXT"
mkdir -p "$OUT_DIR"

if [[ "$EXT" == dylib ]]; then
    # Apple's ld rejects Zig's archive (members not 8-byte aligned), so link
    # the object files directly. Zig also stores members with mode 000.
    OBJ_DIR="$(mktemp -d)"
    (cd "$OBJ_DIR" && ar x "$SRC_DIR/$LIB" && chmod u+r ./*.o)
    "$CC" -O2 -Wall -Wextra -shared -fPIC -I"$SRC_DIR" -o "$OUT" \
        "$ROOT/native/ledgerbeetle_tb.c" "$OBJ_DIR"/*.o -lpthread
    rm -rf "$OBJ_DIR"
else
    "$CC" -O2 -Wall -Wextra -shared -fPIC -I"$SRC_DIR" -o "$OUT" \
        "$ROOT/native/ledgerbeetle_tb.c" -Wl,--no-as-needed "$SRC_DIR/$LIB" -lpthread -ldl -lm
fi

echo "$TB_VERSION" > "$OUT_DIR/TB_VERSION"
echo "Built $OUT (tb_client ${TB_VERSION})"

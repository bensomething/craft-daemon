#!/usr/bin/env bash
#
# Builds the Doom engine artefacts and drops them into the plugin.
#
# Nobody publishes a prebuilt Doom WebAssembly release: cloudflare/doom-wasm has
# no tags and no releases, and there is no npm package. So the artefacts are
# built once, here, and committed. This script is what makes that reproducible.
#
# Usage:  bin/build-engine.sh
#
set -euo pipefail

REPO_URL="https://github.com/cloudflare/doom-wasm.git"

# Pinned so a rebuild produces the same engine. Bump deliberately, and note the
# bump in CHANGELOG.md when the artefacts change.
REPO_COMMIT="65e0d3ae2ffa604155eebd96ed40da6567bd08f4"

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${PLUGIN_DIR}/build/doom-wasm"
OUT_DIR="${PLUGIN_DIR}/src/web/assets/doom/dist/engine"

require() {
    command -v "$1" >/dev/null 2>&1 || {
        echo "error: $1 is not installed." >&2
        echo "       ${2}" >&2
        exit 1
    }
}

# Upstream's README also says to brew install sdl2, sdl2_mixer and sdl2_net.
# It doesn't need them: the PKG_CHECK_MODULES lines for SDL are commented out in
# configure.ac, and -s USE_SDL=2 pulls SDL from Emscripten's own ports. pkg-config
# is still needed, because autoreconf expands PKG_CHECK_MODULES for libpng and
# libsamplerate and fails without its m4 macros.
require emcc       "Install Emscripten: brew install emscripten"
require autoreconf "Install autoconf: brew install autoconf"
require automake   "Install automake: brew install automake"
require pkg-config "Install pkg-config: brew install pkg-config"
require git        "Install git."

# ---------------------------------------------------------------------------
# Source
# ---------------------------------------------------------------------------

if [ ! -d "${BUILD_DIR}/.git" ]; then
    echo "==> Cloning ${REPO_URL}"
    mkdir -p "$(dirname "${BUILD_DIR}")"
    git clone --quiet "${REPO_URL}" "${BUILD_DIR}"
fi

echo "==> Checking out ${REPO_COMMIT}"
git -C "${BUILD_DIR}" fetch --quiet origin
git -C "${BUILD_DIR}" checkout --quiet --force "${REPO_COMMIT}"
git -C "${BUILD_DIR}" clean -qfdx

# ---------------------------------------------------------------------------
# Link flags
#
# Three deliberate departures from upstream's configure.ac. Each is patched by
# exact string match so the build fails loudly rather than silently producing an
# engine with different behaviour if upstream edits that line.
# ---------------------------------------------------------------------------

CONFIGURE_AC="${BUILD_DIR}/configure.ac"

patch_flag() {
    local find="$1" replace="$2" label="$3"

    if ! grep -qF -- "${find}" "${CONFIGURE_AC}"; then
        echo "error: could not apply patch '${label}'." >&2
        echo "       Expected to find: ${find}" >&2
        echo "       Upstream configure.ac has changed. Re-check the flags before shipping." >&2
        exit 1
    fi

    # Literal substring replacement, not a regex: these flags are full of /,
    # [, ] and quotes, and every one of them is a metacharacter somewhere.
    DOOM_FIND="${find}" DOOM_REPLACE="${replace}" perl -pi -e '
        my $f = $ENV{DOOM_FIND};
        my $i = index($_, $f);
        substr($_, $i, length($f)) = $ENV{DOOM_REPLACE} if $i >= 0;
    ' "${CONFIGURE_AC}"
    echo "    patched: ${label}"
}

echo "==> Patching link flags"

# 1. IDBFS, so savegames and default.cfg persist in IndexedDB. Upstream doesn't
#    link it; without it the host script warns and saves die with the tab.
patch_flag "-lwebsocket.js" "-lwebsocket.js -lidbfs.js" "link IDBFS"

# 2. callMain and FS on the Module object. Upstream's index.html reaches for the
#    bare global, which only exists in a non-modularized build; exporting them
#    properly means the host script doesn't depend on that.
patch_flag \
    "-s EXTRA_EXPORTED_RUNTIME_METHODS=[['FS','ccall']]" \
    "-s EXPORTED_RUNTIME_METHODS=[['FS','ccall','callMain','addRunDependency','removeRunDependency']]" \
    "export runtime methods"

# 3. SAFE_HEAP is a debugging aid that costs a large slice of frame budget.
#    Upstream ships it on; a release build shouldn't.
patch_flag "-s SAFE_HEAP=1" "-s SAFE_HEAP=0" "disable SAFE_HEAP"

# 4. Pin the language standard to C17.
#
#    doomtype.h carries Doom's own `typedef enum { false, true } boolean;`,
#    guarded by __bool_true_false_are_defined, which nothing here defines. In
#    C23 `true` and `false` became keywords, so that enum stops compiling:
#
#        ../../src/doomtype.h:113:5: error: expected identifier
#
#    Emscripten 6.x ships a Clang that defaults to gnu23, so the upstream build
#    fails on a current toolchain and succeeded on the one it was written for.
patch_flag "-s ASYNCIFY -O3" "-s ASYNCIFY -std=gnu17 -O3" "pin C17"

# 5. Strip DWARF debug info, unless DOOM_DEBUG=1.
#
#    Upstream builds with -gsource-map, which is right for working on the engine
#    and wrong for shipping it: of a 7.3MB .wasm, 4.94MB is .debug_* sections.
#    It also emits a .wasm.map we don't distribute, so the sourceMappingURL
#    section points at a 404. Stripping leaves the code, data and name sections,
#    which is what actually runs.
#
#    Build with DOOM_DEBUG=1 bin/build-engine.sh to keep the symbols.
#
#    Patching EMFLAGS is only half of it. AC_PROG_CC defaults CFLAGS to "-g -O2"
#    whenever CFLAGS is unset, and configure.ac prepends that to EMFLAGS, so a
#    bare -g survives into the Makefile and emits DWARF anyway. Passing an
#    explicit (possibly empty) CFLAGS to configure is what actually stops it:
#    autoconf tests whether CFLAGS is set, not whether it is non-empty.
if [ "${DOOM_DEBUG:-0}" = "1" ]; then
    echo "    DOOM_DEBUG=1: keeping source maps"
    CONFIGURE_CFLAGS="-g"
else
    patch_flag "-gsource-map -s INVOKE_RUN=1" "-s INVOKE_RUN=1" "strip debug info"
    patch_flag " --source-map-base /" "" "strip source map base"
    patch_flag 'CFLAGS="-O$OPT_LEVEL -g $WARNINGS $orig_CFLAGS"' 'CFLAGS="-O$OPT_LEVEL $WARNINGS $orig_CFLAGS"' "drop -g"
    CONFIGURE_CFLAGS=""
fi

# ---------------------------------------------------------------------------
# Build
#
# USE_PTHREADS is already 0 upstream, and stays that way: a threaded build needs
# SharedArrayBuffer, which needs COOP/COEP response headers that a Craft plugin
# has no way to set on the host's server.
# ---------------------------------------------------------------------------

echo "==> Building (this takes a few minutes)"
cd "${BUILD_DIR}"
emconfigure autoreconf -fiv >/dev/null
ac_cv_exeext=".html" emconfigure ./configure --host=none-none-none CFLAGS="${CONFIGURE_CFLAGS}" >/dev/null
emmake make -j"$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 2)"

# ---------------------------------------------------------------------------
# Install
# ---------------------------------------------------------------------------

JS_FILE="websockets-doom.js"
WASM_FILE="websockets-doom.wasm"
SRC_DIR="${BUILD_DIR}/src"

for f in "${JS_FILE}" "${WASM_FILE}"; do
    if [ ! -f "${SRC_DIR}/${f}" ]; then
        echo "error: build finished but ${f} is missing from ${SRC_DIR}." >&2
        exit 1
    fi
done

mkdir -p "${OUT_DIR}"
cp "${SRC_DIR}/${JS_FILE}" "${SRC_DIR}/${WASM_FILE}" "${OUT_DIR}/"

cat > "${OUT_DIR}/BUILD.json" <<EOF
{
    "source": "${REPO_URL}",
    "commit": "${REPO_COMMIT}",
    "engine": "Chocolate Doom (WebAssembly)",
    "license": "GPL-2.0-or-later",
    "emscripten": "$(emcc --version | head -1 | sed 's/"/\\"/g')",
    "built": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF

echo "==> Done"
ls -lh "${OUT_DIR}"
echo
echo "Commit the artefacts above. They are GPL-2.0 derivatives; see NOTICE.md."

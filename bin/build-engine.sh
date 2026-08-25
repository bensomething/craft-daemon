#!/usr/bin/env bash
#
# Builds the Doom engine artefacts and drops them into the plugin.
#
# The engine is GMH-Code/Dwasm, a WebAssembly port of PrBoom+ / PrBoomX. Nobody
# publishes prebuilt Doom WebAssembly artefacts, so they are built once, here,
# and committed. This script is what makes that reproducible.
#
# Usage:  bin/build-engine.sh
#
set -euo pipefail

REPO_URL="https://github.com/GMH-Code/Dwasm.git"

# Pinned so a rebuild produces the same engine. Bump deliberately, and note the
# bump in CHANGELOG.md when the artefacts change.
REPO_TAG="v2.2.0"

# The engine's own resource WAD, generated in the native stage below. Upstream
# publishes this hash, so a mismatch means the generator produced something
# other than what the project ships.
PRBOOM_WAD_SHA256="506fe7159eaf0a6cb479f866131ec7653638bb08928029cb8dabe1b3b1c9474d"

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${PLUGIN_DIR}/build/dwasm"
OUT_DIR="${PLUGIN_DIR}/src/web/assets/doom/dist/engine"

require() {
    command -v "$1" >/dev/null 2>&1 || {
        echo "error: $1 is not installed." >&2
        echo "       ${2}" >&2
        exit 1
    }
}

require emcc    "Install Emscripten: brew install emscripten"
require emcmake "Install Emscripten: brew install emscripten"
require cmake   "Install CMake: brew install cmake"
require make    "Install make."
require git     "Install git."

# ---------------------------------------------------------------------------
# Source
# ---------------------------------------------------------------------------

if [ ! -d "${BUILD_DIR}/.git" ]; then
    echo "==> Cloning ${REPO_URL}"
    mkdir -p "$(dirname "${BUILD_DIR}")"
    git clone --quiet "${REPO_URL}" "${BUILD_DIR}"
fi

echo "==> Checking out ${REPO_TAG}"
git -C "${BUILD_DIR}" fetch --quiet --tags origin
git -C "${BUILD_DIR}" checkout --quiet --force "${REPO_TAG}"
git -C "${BUILD_DIR}" clean -qfdx

REPO_COMMIT="$(git -C "${BUILD_DIR}" rev-parse HEAD)"

# ---------------------------------------------------------------------------
# Patch
#
# Exactly one, matched literally so the build fails loudly rather than silently
# producing an engine the host script cannot drive.
# ---------------------------------------------------------------------------

patch_source() {
    local file="${BUILD_DIR}/$1" find="$2" replace="$3" label="$4"

    if ! grep -qF -- "${find}" "${file}"; then
        echo "error: could not apply patch '${label}'." >&2
        echo "       Expected to find: ${find}" >&2
        echo "       in ${file}. Upstream has changed; re-check before shipping." >&2
        exit 1
    fi

    DOOM_FIND="${find}" DOOM_REPLACE="${replace}" perl -pi -e '
        my $f = $ENV{DOOM_FIND};
        my $i = index($_, $f);
        substr($_, $i, length($f)) = $ENV{DOOM_REPLACE} if $i >= 0;
    ' "${file}"
    echo "    patched: ${label}"
}

echo "==> Patching"

# Export FS so the host can write a WAD into the filesystem at runtime.
#
# Dwasm expects an IWAD baked into index.data at build time (drop it in
# wasm/fs). This plugin does not ship a WAD and does not want a per-WAD rebuild,
# so it writes whichever WAD the admin configured into the Emscripten FS before
# the engine starts and passes -iwad. That needs FS on the Module object.
# callMain comes along for the ride so startup can be deferred if it ever needs
# to be; the engine currently auto-runs from Module.arguments.
patch_source "CMakeLists.txt" \
    "            -sEMULATE_FUNCTION_POINTER_CASTS=1 \\" \
    "            -sEXPORTED_RUNTIME_METHODS=['FS','callMain','addRunDependency','removeRunDependency'] \\\\\n            -sEMULATE_FUNCTION_POINTER_CASTS=1 \\\\" \
    "export FS for runtime WAD loading"

# ---------------------------------------------------------------------------
# Stage 1: the engine's resource WAD
#
# prboomx.wad is mandatory and is produced by a small host tool (rdatawad), not
# by the game build. CMake's configure step demands SDL2 before it will let us
# reach that target, even though rdatawad links none of it, so SDL2 is satisfied
# with placeholder paths. Cheaper than installing a native SDL stack to generate
# one 459KB file, and the hash check below proves it worked.
# ---------------------------------------------------------------------------

echo "==> Building prboomx.wad"

FAKE_SDL="${BUILD_DIR}/.fake-sdl2/include"
mkdir -p "${FAKE_SDL}"

rm -rf "${BUILD_DIR}/build_native"
mkdir -p "${BUILD_DIR}/build_native"
cd "${BUILD_DIR}/build_native"

cmake .. -DCMAKE_BUILD_TYPE=Release \
    -DSDL2_LIBRARY=/usr/lib/libSystem.B.dylib \
    -DSDL2_INCLUDE_DIR="${FAKE_SDL}" >/dev/null

make prboomwad -j"$(( $(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 2) > 3 ? $(getconf _NPROCESSORS_ONLN) - 2 : 1 ))" >/dev/null

PRBOOM_WAD="$(find "${BUILD_DIR}/build_native" -name prboomx.wad | head -1)"

if [ -z "${PRBOOM_WAD}" ]; then
    echo "error: prboomx.wad was not produced." >&2
    exit 1
fi

ACTUAL="$(shasum -a 256 "${PRBOOM_WAD}" | cut -d' ' -f1)"

if [ "${ACTUAL}" != "${PRBOOM_WAD_SHA256}" ]; then
    echo "error: prboomx.wad hash mismatch." >&2
    echo "       expected ${PRBOOM_WAD_SHA256}" >&2
    echo "       got      ${ACTUAL}" >&2
    exit 1
fi

echo "    prboomx.wad verified"

# ---------------------------------------------------------------------------
# Stage 2: the WebAssembly build
#
# wasm/fs is preloaded into index.data. Only the engine's own resource WAD goes
# in: no IWAD, so the package carries no game content and one build serves every
# WAD. GL4ES is deliberately not built, so the software renderer is used;
# upstream documents the OpenGL path as corrupting floor textures.
# ---------------------------------------------------------------------------

echo "==> Building engine (this takes a few minutes)"

cp "${PRBOOM_WAD}" "${BUILD_DIR}/wasm/fs/"

rm -rf "${BUILD_DIR}/build_wasm"
mkdir -p "${BUILD_DIR}/build_wasm"
cd "${BUILD_DIR}/build_wasm"

emcmake cmake .. -DCMAKE_BUILD_TYPE=Release >/dev/null

CORES="$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 2)"
JOBS=$(( CORES > 3 ? CORES - 2 : 1 ))
echo "    using ${JOBS} of ${CORES} cores"
make -j"${JOBS}" >/dev/null

# ---------------------------------------------------------------------------
# Install
# ---------------------------------------------------------------------------

for f in index.js index.wasm index.data; do
    if [ ! -f "${BUILD_DIR}/build_wasm/${f}" ]; then
        echo "error: build finished but ${f} is missing." >&2
        exit 1
    fi
done

mkdir -p "${OUT_DIR}"
rm -f "${OUT_DIR}"/websockets-doom.* 2>/dev/null || true
cp "${BUILD_DIR}/build_wasm/index.js" "${BUILD_DIR}/build_wasm/index.wasm" \
   "${BUILD_DIR}/build_wasm/index.data" "${OUT_DIR}/"

cat > "${OUT_DIR}/BUILD.json" <<EOF
{
    "source": "${REPO_URL}",
    "tag": "${REPO_TAG}",
    "commit": "${REPO_COMMIT}",
    "engine": "PrBoom+ / PrBoomX (Dwasm)",
    "license": "GPL-2.0-or-later",
    "emscripten": "$(emcc --version | head -1 | sed 's/"/\\"/g')",
    "built": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF

echo "==> Done"
ls -lh "${OUT_DIR}"
echo
echo "Commit the artefacts above. They are GPL-2.0 derivatives; see NOTICE.md."

# Engine artefacts

This directory holds the compiled Doom engine. It is empty on a fresh clone.

Run `bin/build-engine.sh` from the plugin root to populate it with:

- `websockets-doom.js` (Emscripten glue)
- `websockets-doom.wasm` (the engine)
- `BUILD.json` (provenance: upstream commit, Emscripten version, build date)

Those files are GPL-2.0-or-later derivatives of cloudflare/doom-wasm. Read
[NOTICE.md](../../../../../../NOTICE.md) before redistributing them.

Do not drop upstream's own build in here by hand. This plugin's host script
expects four build-flag patches that upstream does not apply (IDBFS, exported
runtime methods, SAFE_HEAP off, and `-std=gnu17` so the C23 keyword collision in
`doomtype.h` does not break the build); the build script applies them and fails
loudly if upstream's `configure.ac` has moved underneath it.

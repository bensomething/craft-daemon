# Engine artefacts

This directory holds the compiled Doom engine. It is empty on a fresh clone.

Run `bin/build-engine.sh` from the plugin root to populate it with:

- `index.js` (Emscripten glue)
- `index.wasm` (the engine)
- `index.data` (preloaded filesystem: the engine's own `prboomx.wad`, no game content)
- `BUILD.json` (provenance: upstream tag and commit, Emscripten version, build date)

Those files are GPL-2.0-or-later derivatives of GMH-Code/Dwasm. Read
[NOTICE.md](../../../../../../NOTICE.md) before redistributing them.

Do not drop an upstream build in here by hand. This plugin's host script needs
`FS` exported so it can write the admin's WAD into the filesystem at runtime,
which stock Dwasm does not do (it expects an IWAD baked into `index.data` at
build time). The build script applies that patch and fails loudly if upstream's
`CMakeLists.txt` has moved underneath it.

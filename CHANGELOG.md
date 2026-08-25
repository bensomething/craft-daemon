# Release Notes for Doom

## Unreleased

### Added
- Initial scaffold: control panel section, `doom:play` permission, settings screen, WAD service, console commands, and the WebAssembly host layer.

### Notes
- The engine build pins `-std=gnu17`. Chocolate Doom's `doomtype.h` declares its own `false`/`true` enum, which stops compiling under the C23 default of current Emscripten toolchains.
- The engine build strips DWARF debug info, taking the `.wasm` from 7.3MB to 2.1MB. Upstream builds with `-gsource-map`, and `configure.ac` hardcodes `-g` on top of it. `DOOM_DEBUG=1` restores both.

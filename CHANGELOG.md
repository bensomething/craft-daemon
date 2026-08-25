# Release Notes for Doom

## Unreleased

### Added
- Initial release: a control panel section running a WebAssembly build of PrBoom+ on a canvas, behind its own `doom:play` permission.
- Settings screen with a "Download Freedoom" button and a progress bar, so a WAD can be installed without dropping to a terminal.
- `doom/wad/fetch`, `doom/wad/list` and `doom/wad/status` console commands.
- `bin/build-engine.sh`, which builds the engine reproducibly from a pinned upstream tag and verifies the engine resource WAD against the SHA-256 upstream publishes.

### Notes
- WADs are never served as static files. They live under `@storage` and reach the browser through a permission-checked controller action.
- The `.wasm` is fetched to an ArrayBuffer rather than streamed, because `cpresources` is served by the site's own web server and `application/wasm` is not reliably in its MIME map.
- The engine is [Dwasm](https://github.com/GMH-Code/Dwasm) (PrBoom+ / PrBoomX). An earlier implementation used cloudflare/doom-wasm (Chocolate Doom) and was abandoned: it needed eleven build patches to compile and run on a current toolchain, and still crashed whenever a level loaded. Dwasm needs one patch, and that one only because this plugin loads WADs at runtime rather than baking them in.

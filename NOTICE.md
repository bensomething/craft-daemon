# Third-party notices

## Doom engine (GPL-2.0-or-later)

The files in `src/web/assets/doom/dist/engine/` are compiled WebAssembly
artefacts. They are a derivative work of [cloudflare/doom-wasm][doom-wasm], a
WebAssembly port of [Chocolate Doom][chocolate], which is itself derived from
id Software's Doom source release.

That source is licensed under the **GNU General Public License, version 2 or
later**. The compiled artefacts therefore carry the GPL, regardless of the MIT
license on the rest of this plugin.

**Written offer of source.** The complete corresponding source for the compiled
artefacts is the upstream repository at the exact commit recorded in
`src/web/assets/doom/dist/engine/BUILD.json`, plus the three link-flag patches
applied by `bin/build-engine.sh` (both files are in this repository). Running
that script reproduces the artefacts. If you would prefer to receive the source
another way, open an issue on this repository and it will be provided.

A copy of the GPL-2.0 text is available at
<https://www.gnu.org/licenses/old-licenses/gpl-2.0.html>.

[doom-wasm]: https://github.com/cloudflare/doom-wasm
[chocolate]: https://www.chocolate-doom.org/

## WAD files

No WAD ships with this plugin.

`craft doom/wad/fetch` downloads [Freedoom][freedoom], which is distributed
under a BSD 3-clause license. Freedoom is not redistributed by this package; it
is fetched at the administrator's request and written to `storage/doom/`.

If you point the plugin at a commercial or shareware WAD instead, the terms of
that WAD are between you and its copyright holder.

[freedoom]: https://freedoom.github.io/

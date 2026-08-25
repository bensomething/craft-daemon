# Third-party notices

## Doom engine (GPL-2.0-or-later)

The files in `src/web/assets/doom/dist/engine/` are compiled WebAssembly
artefacts. They are a derivative work of [Dwasm][dwasm], a WebAssembly port of
[PrBoom+][prboom] and [PrBoomX][prboomx], which are themselves derived from
id Software's Doom source release.

That source is licensed under the **GNU General Public License, version 2 or
later**. The compiled artefacts therefore carry the GPL, regardless of the MIT
license on the rest of this plugin.

`index.data` additionally contains `prboomx.wad`, the engine's own resource
WAD. It is generated from the engine source during the build and carries the
same license. It holds no game content.

**Written offer of source.** The complete corresponding source for the compiled
artefacts is the upstream repository at the exact tag and commit recorded in
`src/web/assets/doom/dist/engine/BUILD.json`, plus the single patch applied by
`bin/build-engine.sh` (both files are in this repository). Running that script
reproduces the artefacts. If you would prefer to receive the source another
way, open an issue on this repository and it will be provided.

A copy of the GPL-2.0 text is available at
<https://www.gnu.org/licenses/old-licenses/gpl-2.0.html>.

[dwasm]: https://github.com/GMH-Code/Dwasm
[prboom]: https://github.com/coelckers/prboom-plus
[prboomx]: https://github.com/JadingTsunami/prboomX

## WAD files

No WAD ships with this plugin.

`craft doom/wad/fetch` downloads [Freedoom][freedoom], which is distributed
under a BSD 3-clause license. Freedoom is not redistributed by this package; it
is fetched at the administrator's request and written to `storage/doom/`.

If you point the plugin at a commercial or shareware WAD instead, the terms of
that WAD are between you and its copyright holder.

[freedoom]: https://freedoom.github.io/

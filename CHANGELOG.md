# Release Notes for Daemon

## Unreleased

### Added
- Initial release: a control panel section running a WebAssembly build of PrBoom+ on a canvas, behind its own `daemon:play` permission.
- Settings screen with a "Download Freedoom" button and a progress bar, so a WAD can be installed without dropping to a terminal.
- `daemon/wad/fetch`, `daemon/wad/shareware`, `daemon/wad/list` and `daemon/wad/status` console commands. Both downloads also have a button on the settings screen, with progress.
- `bin/build-engine.sh`, which builds the engine reproducibly from a pinned upstream tag and verifies the engine resource WAD against the SHA-256 upstream publishes.
- A **Game** menu on the last breadcrumb of the Daemon screen, in the same place an entry's section sits, listing every WAD the plugin can see. Known IWADs are named; anything else is listed by filename.
- Savegames can be kept in Craft, behind the **Keep savegames** setting, which is off by default. With it on, each save the engine writes is copied into `storage/daemon/saves/` under the user who made it, ten versions of every slot are retained, and a **Saves** menu beside the breadcrumbs restores an older version. Saving is the game's own Save Game and nothing else: the plugin watches the engine's filesystem sync rather than adding a button that asks for the same thing twice.
- A background image behind the game, so the pane is not a black rectangle floating in white. The pane fills the window and the game is centred in it.
- A browser warning before leaving mid-game with progress the engine hasn't written down. Doom saves when the player says so and at no other moment, so a closed tab is a lost level.
- The default WAD is chosen rather than inherited. It used to be whichever file sorted first in the first search directory, which is decided by accident; the settings table now has a **Default** column, and the chosen WAD sorts first everywhere that reads the list.
- WAD names are editable. The settings screen lists everything the plugin can see in a table, with the derived name as the field's placeholder, so an empty field means "call it the usual thing" rather than "call it nothing".
- A **WAD directory** setting, searched alongside `storage/daemon/`, so a collection of WADs can be read where it already lives. It accepts an `@alias` or a `$ENVIRONMENT_VARIABLE`.

### Notes
- The shareware IWAD is fetched, never bundled. It is id's, and a Composer package carrying it would no longer be wholly MIT and GPL. Its checksum is pinned against an artefact matching the MD5 Debian's game-data-packager publishes, so the hash does not come from whoever serves the bytes.
- Only IWADs are offered as games. A PWAD is a patch loaded on top of a game rather than a game, and passing one to `-iwad` gets a warning followed by a crash on the first missing texture.
- Savegames reach Craft by key, never by path: the browser sends the engine's own relative path, which is split into segments and matched against a strict pattern before any of it becomes a filename. Reading back works the same way, and the user id comes from the session rather than the request, so no id one player holds can reach another's saves.
- Restoring at boot fills gaps only. A save already in the browser is never overwritten by the stored copy, because it may be newer than anything that reached the server.
- Safari reports the arrow keys as numeric-keypad keys, and the host script corrects that before the engine sees them. macOS sets the numeric-pad flag on the arrows, WebKit passes it through as `KeyboardEvent.location` 3, and SDL's keycode mapping duly turns `SDLK_UP` into `SDLK_KP_8`. PrBoom binds nothing to the keypad, so in Safari the menu cursor would not move, the option sliders would not slide, and the arrows would not turn the player, while every other key worked. `ev.code` is right in every browser, so a real numpad press is left alone.
- The IWAD is written into the engine's filesystem under a filename derived from the WAD, not a fixed one. PrBoom names its savegame directory after an MD5 of the loaded WADs' basenames, so a fixed filename would give every game the same save slots and let one overwrite another.
- WADs are never served as static files. They live under `@storage` and reach the browser through a permission-checked controller action.
- The Game menu passes a key, never a path, and the key is resolved by looking it up in the list built from the filesystem. Nothing a request sends is used to address a file.
- Switching game before pressing Play only changes which URL gets fetched. Switching mid-game reloads the page: the engine takes its IWAD as a command line argument to a `main()` that has already run, and Emscripten's glue is not built to be instantiated twice in one document. The choice lives in the URL, so the reload keeps it.
- The `.wasm` is fetched to an ArrayBuffer rather than streamed, because `cpresources` is served by the site's own web server and `application/wasm` is not reliably in its MIME map.
- The engine is [Dwasm](https://github.com/GMH-Code/Dwasm) (PrBoom+ / PrBoomX). An earlier implementation used cloudflare/doom-wasm (Chocolate Doom) and was abandoned: it needed eleven build patches to compile and run on a current toolchain, and still crashed whenever a level loaded. Dwasm needs one patch, and that one only because this plugin loads WADs at runtime rather than baking them in.

<img src="src/icon.svg" width="96" alt="Doom">

# Daemon
**A Doom engine, in the Craft control panel. For some reason. **

A control panel section that runs a WebAssembly build of PrBoom+ on a canvas, behind its own permission, playing a WAD you supply. A joke with a test suite.

## Requirements

- Craft CMS 5.10 or later
- PHP 8.2 or later
- A browser with WebAssembly and WebGL (so, a browser)

## Installation

```sh
composer require bensomething/craft-daemon
php craft plugin/install daemon
```

The engine ships with the package as a committed artefact. See [Building the engine](#building-the-engine) only if you are working on the plugin itself.

## Getting a WAD

This plugin ships with the engine, but not the game files. Freedoom is 28.8MB per WAD and that's a lot to put in everyone's `vendor/` for a joke.

```sh
php craft daemon/wad/fetch
```

That downloads Freedoom 0.13.0, verifies it against a pinned checksum, and unpacks both IWADs into `storage/daemon/`. Run it once per environment, since `storage/` is not usually deployed.

The settings screen has buttons for the same downloads, with progress.

### Doom's shareware episode

`php craft daemon/wad/shareware`, or the button beside it on the settings screen, fetches and verifies id's Doom shareware IWAD. It is a download rather than a bundle because a Composer package carrying id's game data would make every install a redistributor of it, and would stop this package being wholly MIT and GPL. The WAD it installs is id Software's and is not covered by this plugin's licence.

The checksum is pinned against an artefact matching the MD5 that Debian's `game-data-packager` publishes, so it does not depend on whoever serves the file.

### WADs you already have

Point the **WAD directory** setting at them: an absolute path, an `@alias`, or a `$ENVIRONMENT_VARIABLE`. They join the ones in `storage/daemon/`, and nothing is copied or moved. That directory is searched first, so its first WAD becomes the default.

Heretic, Hexen and Strife are found but not offered. They are Doom engine games shipping the same container, so they look valid, but PrBoom+ implements Doom's game logic alone. The settings screen lists them as unsupported rather than handing the engine a crash.

### Naming games

Known IWADs are named for you and anything else is listed by filename, both only defaults: the settings table gives every WAD an editable name, with the derived one as its placeholder. Names are keyed on the filename, so renaming a file forgets the name you gave it. The same table picks the **default**, meaning the WAD that loads when you open the screen.

### Switching game

With more than one WAD, the last breadcrumb becomes a menu of them. The items are ordinary links, so switching is a page load. It has to be: the engine takes its IWAD as a command line argument to a `main()` that has already run, and cannot be handed another. The choice lives in the URL (`?wad=freedoom2`), so a reload keeps it and a bookmark restores it.

## Playing

<kbd>W</kbd><kbd>A</kbd><kbd>S</kbd><kbd>D</kbd> moves, the arrows turn, <kbd>Ctrl</kbd> fires, <kbd>Space</kbd> opens, <kbd>Esc</kbd> is the menu. The controls are listed on the screen itself. A keyboard is required: the engine reads keys and mouse buttons only, so a touchscreen can fire the weapon and do nothing else.

## Savegames

The engine keeps its own saves in the browser, in IndexedDB. They survive a reload, but not clearing site data, a different browser, or a different machine. Leaving mid-game with progress the engine hasn't written down gets the browser's own "leave site?" warning, because Doom saves when you say so and at no other moment.

### Keeping saves in Craft

Turn on **Keep savegames** and every save is also copied into `storage/daemon/saves/`, under the id of the user who made it, ten versions per slot. On page load, anything Craft holds that the browser does not is written back, so a fresh machine starts with your slots filled. There is nothing to press: saving is the game's own Save Game, from the Esc menu, and the copy follows it. A **Saves** menu beside the breadcrumbs puts an older version back in its slot; press Esc, then Load Game, to play it.

It is off by default. Saves are tied to the engine build that wrote them, so bumping `REPO_TAG` in `bin/build-engine.sh` may orphan them.

## Console commands

| Command | What it does |
| --- | --- |
| `daemon/wad/fetch` | Downloads and verifies Freedoom into `storage/daemon/` |
| `daemon/wad/shareware` | Downloads and verifies the Doom shareware IWAD into `storage/daemon/` |
| `daemon/wad/list` | Lists the WADs the plugin can see, marking the default |
| `daemon/wad/status` | Prints where it looked, what it found, and how the engine was built |

## Permissions

`daemon:play` controls access to the section, registered separately from Craft's automatic `accessplugin-daemon` so it can be granted on its own. The nav item disappears without it.

## Notes for the curious

**Nothing is addressed by path.** WADs live under `@storage`, outside the web root, and reach the browser through a permission-checked controller action. The Game menu passes a key that is resolved against the filesystem. Savegames work the same way, and the user id comes from the session rather than the request, so no id one player holds can reach another's saves.

**The `.wasm` is fetched to an ArrayBuffer, not streamed.** `cpresources` is served by the site's own nginx or Apache, and plenty of stacks have no `application/wasm` in their MIME map. `instantiateStreaming` would fail there and nowhere else, which is the worst kind of bug to ship.

**The build is single-threaded on purpose.** A pthreads build needs `SharedArrayBuffer`, which needs COOP and COEP response headers, which a plugin cannot set on someone else's server.

**Keyboard input is scoped, not stolen.** The host script pushes a Garnish UI layer, the same mechanism modals use, to scope Craft's own shortcuts away, then calls `preventDefault` on a capture-phase window listener for the keys the browser would act on itself. It never calls `stopPropagation`: SDL listens on `window`, so halting propagation would disarm the game along with Craft.

**Safari lies about the arrow keys.** macOS sets the numeric-pad flag on them and WebKit passes it through as `KeyboardEvent.location` 3, so SDL's numpad branch turns `SDLK_UP` into `SDLK_KP_8` and PrBoom receives `KEYD_KEYPAD8`, which nothing is bound to. The menu cursor sits still, the sliders will not slide and the arrows will not turn, while every other key works, which makes it look like the arrows alone are dead. The host script shadows `location` with an own property on the event before SDL's listener reads it, on key-ups as well, since SDL matches a release to its press by keycode. A real numpad press arrives as `Numpad8` in `ev.code` and is left alone.

**Pointer lock waits for a gesture.** Browsers only grant a pointer lock from a user gesture, so a request raised from the game loop is refused. The engine asks through `Module.captureMouse` rather than calling `requestPointerLock` itself, and the host retries on the next keypress.

**Each WAD gets its own save slots.** PrBoom names its savegame directory after an MD5 of the loaded WADs' basenames, so the IWAD is written into the engine's filesystem under a name derived from the WAD rather than a fixed one. A fixed name would let a Doom II save land in a Freedoom slot.

**The settings screen updates in place.** It is a full page form carrying `data-confirm-unload`, so reloading it after a download would either discard names being typed or stop to ask about them. The list is re-rendered by `daemon/wad/list` and swapped in instead.

## Content Security Policy

WebAssembly instantiation needs `wasm-unsafe-eval` in `script-src`. Without it the section loads and then refuses to start.

## Building the engine

Only needed if you are working on the plugin. Site installs get the compiled artefacts from the package, because nobody publishes prebuilt Doom WebAssembly artefacts.

```sh
brew install emscripten cmake
bin/build-engine.sh
```

That clones [GMH-Code/Dwasm][dwasm] at a pinned tag and builds in two stages. A native stage generates `prboomx.wad`, the engine's mandatory resource WAD, and checks it against the SHA-256 upstream publishes. The Emscripten stage then produces `index.js`, `index.wasm` and `index.data` into `src/web/assets/daemon/dist/engine/`. Commit what it writes, about 2.5MB.

Exactly one patch is applied, and it exists because of the WAD policy above: Dwasm expects an IWAD baked into `index.data` at build time, so the build exports `FS` and the host writes whichever WAD the admin configured into the filesystem at runtime instead. One build serves every WAD, and the package carries no game content. The patch is matched literally, so the build fails loudly if upstream moves rather than quietly producing an engine the host cannot drive.

GL4ES is deliberately not built, so the software renderer is used. Upstream documents the OpenGL path as corrupting floor textures.

The compiled artefacts are GPL-2.0-or-later, which is why this package is `MIT AND GPL-2.0-or-later` rather than plain MIT. The PHP never links against the engine, since the browser loads the WebAssembly separately, so the two sit together as mere aggregation. See [NOTICE.md](NOTICE.md) for the source offer.

[dwasm]: https://github.com/GMH-Code/Dwasm

## Why "Daemon"?

The word comes from the engine's own startup log:

    Z_Init: Init zone memory allocation daemon

Which is convenient, because it means three things at once: the Unix sense that suits a CMS plugin, the memory allocator, and the things you shoot.

This project is not affiliated with, endorsed by, or in any way connected to id Software, Bethesda Softworks, or ZeniMax Media. All trademarks and copyrights are the property of their respective owners.

## License

MIT for everything written here, GPL-2.0-or-later for the compiled engine. See [LICENSE.md](LICENSE.md) and [NOTICE.md](NOTICE.md).

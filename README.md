<p align="center"><img src="src/icon.svg" width="96" alt="Doom"></p>

<h1 align="center">Daemon</h1>

<p align="center">A Doom engine, in the Craft control panel. An explicit novelty, engineered like it isn't.</p>

---

This is a joke with a test suite. It adds a control panel section that runs a
WebAssembly build of PrBoom+ on a canvas, gated behind its own permission,
loading a WAD you supply.

It is not a catalogue plugin and it is not pretending to be useful. It is
pretending to be well built, and it is not pretending about that.

## Requirements

- Craft CMS 5.10 or later
- PHP 8.2 or later
- A browser with WebAssembly and WebGL (so, a browser)

## Installation

```sh
composer require bensomething/craft-daemon
php craft plugin/install daemon
```

The engine comes with the package. It is a compiled artefact committed to this
repository, not something you build. See [Building the engine](#building-the-engine)
if you are working on the plugin itself.

## Getting a WAD

The engine ships with the package. The WAD does not, and it is the only thing
you need to fetch.

No WAD ships here because Freedoom is 28.8MB per IWAD, and that is a lot to put
in everyone's `vendor/` for a joke. So:

```sh
php craft daemon/wad/fetch
```

That downloads Freedoom 0.13.0, verifies it against a checksum pinned in the
plugin source, and unpacks `freedoom1.wad` and `freedoom2.wad` into
`storage/daemon/`. Nothing else to configure.

Run it once per environment. `storage/` is not usually deployed, so a fresh
server needs its own copy.

### Already have a WAD?

Set the path in **Settings** to **Plugins** to **Daemon**. It takes an absolute
path, an `@alias`, or a `$ENVIRONMENT_VARIABLE`, and it wins over anything in
`storage/daemon/`.

## Console commands

| Command | What it does |
| --- | --- |
| `daemon/wad/fetch` | Downloads and verifies Freedoom into `storage/daemon/` |
| `daemon/wad/list` | Lists the WADs the plugin can see, marking the active one |
| `daemon/wad/status` | Prints where it looked, what it found, and how the engine was built |

## Permissions

`daemon:play` controls access to the section. It is registered separately from
Craft's automatic `accessplugin-daemon` so it can be granted on its own, and the
nav item disappears for anyone without it.

## Notes for the curious

Several things about this are less obvious than they look.

**WADs are never served statically.** They live under `@storage`, outside the
web root, and reach the browser through a permission-checked controller action.
A WAD sitting in `web/` would be readable by anyone who guessed the filename.

**The `.wasm` is fetched to an ArrayBuffer, not streamed.** `cpresources` is
served by the site's own nginx or Apache, and a surprising number of stacks have
no `application/wasm` entry in their MIME map. `instantiateStreaming` would fail
on those servers and nowhere else, which is the worst kind of bug to ship.

**The build is single-threaded on purpose.** A pthreads build needs
`SharedArrayBuffer`, which needs COOP and COEP response headers, which a plugin
cannot set on someone else's server.

**Keyboard input is scoped, not stolen.** Craft binds its own shortcuts, and
Doom wants arrows, Ctrl and Space. The host script pushes a Garnish UI layer
(the same mechanism modals use) to scope the control panel's shortcuts away,
then calls `preventDefault` on a capture-phase window listener for the keys the
browser itself would act on. It never calls `stopPropagation`: SDL listens on
`window`, so halting propagation would disarm the game along with Craft.

**Pointer lock waits for a gesture.** Browsers only grant a pointer lock from a
user gesture, so a request raised from the game loop is refused. The engine asks
through `Module.captureMouse` rather than calling `requestPointerLock` itself,
and the host retries on the next keypress if the immediate attempt fails.

**Saves persist in IndexedDB.** The engine handles this itself. No server round
trip, and no patch needed.

## Content Security Policy

WebAssembly instantiation needs `wasm-unsafe-eval` in `script-src`. If your
control panel runs under a CSP, add it, or the section will load and then
refuse to start.

## Building the engine

Only needed if you are working on the plugin. Site installs get the compiled
artefacts from the package.

Nobody publishes prebuilt Doom WebAssembly artefacts, so they are built once and
committed.

```sh
brew install emscripten cmake
bin/build-engine.sh
```

That clones [GMH-Code/Dwasm][dwasm] at a pinned tag and builds in two stages.
First a native stage generates `prboomx.wad`, the engine's mandatory resource
WAD, and checks it against the SHA-256 upstream publishes. Then the Emscripten
stage produces `index.js`, `index.wasm` and `index.data` into
`src/web/assets/daemon/dist/engine/`. Commit what it writes, about 2.5MB.

Exactly one patch is applied, and it exists because of the WAD policy above:
Dwasm expects an IWAD baked into `index.data` at build time, so the build
exports `FS` and the host writes whichever WAD the admin configured into the
filesystem at runtime instead. One build serves every WAD, and the package
carries no game content. The patch is matched literally and the build fails
loudly if upstream moves, rather than silently producing an engine the host
cannot drive.

GL4ES is deliberately not built, so the software renderer is used. Upstream
documents the OpenGL path as corrupting floor textures.

The compiled artefacts are GPL-2.0-or-later, which is why this package is
`MIT AND GPL-2.0-or-later` rather than plain MIT. The PHP never links against
the engine (the browser loads the WebAssembly separately), so the two sit
together as mere aggregation. See [NOTICE.md](NOTICE.md) for the source offer.

[dwasm]: https://github.com/GMH-Code/Dwasm

## Why "Daemon"

The package contains no Doom. It ships a WebAssembly build of PrBoom+ and, by
default, plays Freedoom. "Doom" names a game that is not in the box, and it is
a live trademark; using it to describe what the engine is compatible with is
fair, using it as a product name is a different thing.

The word comes from the engine's own startup log:

    Z_Init: Init zone memory allocation daemon

Which is convenient, because it means three things at once: the Unix sense that
suits a CMS plugin, the memory allocator, and the things you shoot.

This project is not affiliated with, endorsed by, or in any way connected to
id Software, Bethesda Softworks, or ZeniMax Media. All trademarks and
copyrights are the property of their respective owners.

## License

MIT for everything written here. GPL-2.0-or-later for the compiled engine.
See [LICENSE.md](LICENSE.md) and [NOTICE.md](NOTICE.md).

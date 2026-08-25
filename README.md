<p align="center"><img src="src/icon.svg" width="96" alt="Doom"></p>

<h1 align="center">Doom for Craft CMS</h1>

<p align="center">Doom, in the control panel. An explicit novelty, engineered like it isn't.</p>

---

This is a joke with a test suite. It adds a control panel section that runs a
WebAssembly build of Chocolate Doom on a canvas, gated behind its own
permission, loading a WAD you supply.

It is not a catalogue plugin and it is not pretending to be useful. It is
pretending to be well built, and it is not pretending about that.

## Requirements

- Craft CMS 5.10 or later
- PHP 8.2 or later
- A browser with WebAssembly and WebGL (so, a browser)

## Installation

```sh
composer require bensomething/craft-doom
php craft plugin/install doom
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
php craft doom/wad/fetch
```

That downloads Freedoom 0.13.0, verifies it against a checksum pinned in the
plugin source, and unpacks `freedoom1.wad` and `freedoom2.wad` into
`storage/doom/`. Nothing else to configure.

Run it once per environment. `storage/` is not usually deployed, so a fresh
server needs its own copy.

### Already have a WAD?

Set the path in **Settings** to **Plugins** to **Doom**. It takes an absolute
path, an `@alias`, or a `$ENVIRONMENT_VARIABLE`, and it wins over anything in
`storage/doom/`.

## Console commands

| Command | What it does |
| --- | --- |
| `doom/wad/fetch` | Downloads and verifies Freedoom into `storage/doom/` |
| `doom/wad/list` | Lists the WADs the plugin can see, marking the active one |
| `doom/wad/status` | Prints where it looked, what it found, and how the engine was built |

## Permissions

`doom:play` controls access to the section. It is registered separately from
Craft's automatic `accessplugin-doom` so it can be granted on its own, and the
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
browser itself would act on. It never calls `stopPropagation`: SDL2 listens on
`window` in the bubble phase, so halting propagation would disarm the game
along with Craft.

**Saves persist in IndexedDB.** The build links `-lidbfs.js`, and the host
mounts it at `/persist` for savegames and `default.cfg`. No server round trip.

## Content Security Policy

WebAssembly instantiation needs `wasm-unsafe-eval` in `script-src`. If your
control panel runs under a CSP, add it, or the section will load and then
refuse to start.

## Building the engine

Only needed if you are working on the plugin. Site installs get the compiled
artefacts from the package.

No upstream publishes a prebuilt Doom WebAssembly release: `cloudflare/doom-wasm`
has no tags and no releases, and there is no npm package. So the artefacts are
built once and committed.

```sh
brew install emscripten automake autoconf pkg-config
bin/build-engine.sh
```

That clones [cloudflare/doom-wasm][doom-wasm] at a pinned commit, applies four
documented build-flag patches, compiles, and writes `websockets-doom.js`,
`websockets-doom.wasm` and `BUILD.json` into
`src/web/assets/doom/dist/engine/`. Commit what it writes. The result is about
2.3MB.

Build with `DOOM_DEBUG=1 bin/build-engine.sh` to keep DWARF symbols and source
maps. That produces a 7.3MB `.wasm`, which is why it is not the default.

The compiled artefacts are GPL-2.0-or-later, which is why this package is
`MIT AND GPL-2.0-or-later` rather than plain MIT. The PHP never links against
the engine (the browser loads the WebAssembly separately), so the two sit
together as mere aggregation. See [NOTICE.md](NOTICE.md) for the source offer.

## License

MIT for everything written here. GPL-2.0-or-later for the compiled engine.
See [LICENSE.md](LICENSE.md) and [NOTICE.md](NOTICE.md).

[doom-wasm]: https://github.com/cloudflare/doom-wasm

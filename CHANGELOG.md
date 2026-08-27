# Release Notes for Daemon

## Unreleased

### Fixed
- The compiled engine no longer carries the absolute path of the machine that built it. `assert()` bakes `__FILE__` into the binary and CMake compiles with absolute paths, so `index.wasm` shipped with a developer's home directory in it. The build now compiles with `-ffile-prefix-map` and refuses to install an artefact containing the build path.

## 1.0.0-beta.3 - 2026-08-26

### Added
- A user's savegames are deleted along with the user. They are filed under a user id, and nothing on disk knew when that user went away, so the directory outlived them. Swept during Craft's own garbage collection rather than hooked to the delete, because users are soft deleted first and can be restored.

### Changed
- The WAD downloads moved from the plugin's settings screen to a **Daemon WADs** utility, with a link in their place. Craft renders plugin settings through `Html::disableInputs()` when `allowAdminChanges` is off, which disabled both buttons and discarded the JavaScript driving them, so on a production install they did nothing at all. Utilities are not subject to that setting. The console commands were never affected.

## 1.0.0-beta.2 - 2026-08-26

### Changed
- `daemon/wad/fetch` is now `daemon/wad/freedoom`. Both download commands name the game they install, rather than one naming the action and the other a licence. The settings screen buttons are unaffected.

## 1.0.0-beta.1 - 2026-08-26

### Added
- Initial release: a control panel section running a WebAssembly build of PrBoom+ on a canvas, behind its own `daemon:play` permission.
- A **Game** menu on the last breadcrumb, listing every WAD the plugin can see. Known IWADs are named, anything else is listed by filename, and both can be renamed on the settings screen.
- A **Default** column on the settings table, picking the WAD that loads when the screen opens.
- A **WAD directory** setting, searched alongside `storage/daemon/`, accepting an `@alias` or a `$ENVIRONMENT_VARIABLE`.
- Freedoom and the Doom shareware episode can be downloaded from the settings screen with progress, or with `daemon/wad/fetch` and `daemon/wad/shareware`. The list updates in place when a download finishes.
- `daemon/wad/list` and `daemon/wad/status` console commands.
- A **Keep savegames** setting, off by default, copying each save the engine writes into `storage/daemon/saves/` and adding a **Saves** menu to restore an older version.
- A browser warning before leaving mid-game with progress the engine hasn't written down.
- The controls listed on the screen itself, with a note on touch devices that a keyboard is needed.
- `bin/build-engine.sh`, which builds the engine reproducibly from a pinned upstream tag and verifies the engine resource WAD against the SHA-256 upstream publishes.

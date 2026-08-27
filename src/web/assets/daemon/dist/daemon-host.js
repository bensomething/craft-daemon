/*
 * Host layer for the Doom CP section: the shim between Dwasm (PrBoom+
 * compiled to WebAssembly) and a control panel with its own opinions about
 * keyboard input. Dwasm expects an IWAD baked in at build time, so the
 * admin's is written into its filesystem here before the engine starts.
 */
(function ($) {
    'use strict';

    /*
     * Keys the browser acts on itself and the game also wants. preventDefault
     * only: SDL listens on window, so stopPropagation would disarm the game
     * along with Craft.
     */
    var SWALLOWED_KEYS = [
        'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight',
        'Space', 'Tab', 'Enter', 'Backspace',
        'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', 'F11', 'F12',
        'Slash', 'Quote',
    ];

    /*
     * ev.code, not ev.key: only ev.code tells a real numpad press from an arrow
     * key claiming to be one.
     */
    var ARROW_CODES = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'];

    /*
     * Undoes Safari's claim that the arrow keys are on the numeric keypad.
     *
     * macOS sets the numeric-pad flag on them and WebKit passes it through as
     * KeyboardEvent.location 3, so SDL maps SDLK_UP to SDLK_KP_8 and PrBoom
     * receives KEYD_KEYPAD8, which nothing is bound to: no menu cursor, no
     * sliders, no turning, while every other key works.
     *
     * Only the keycode is wrong, the scancode comes from ev.code. location is a
     * prototype getter, so an own property shadows it, and SDL reads the event
     * later. A real numpad press is Numpad8, not ArrowUp, so it is left alone.
     */
    function unfakeNumpad(ev) {
        if (ev.location === 0 || ARROW_CODES.indexOf(ev.code) === -1) {
            return;
        }

        try {
            Object.defineProperty(ev, 'location', {value: 0, configurable: true});
        } catch (e) {
            // The key still reaches the game, just the wrong one.
        }
    }

    /*
     * Where the admin's IWAD is written before the engine starts. The filename
     * matters: PrBoom names its savegame directory after an MD5 of the loaded
     * WADs' basenames, so a fixed path would give every game the same slots.
     */
    function wadPathFor(key) {
        return '/' + key + '.wad';
    }

    /* Where Dwasm mounts IDBFS, hardcoded in the engine as I_DoomExeDir(). */
    var SAVE_ROOT = '/dwasm';

    /* What the engine calls a savegame. */
    var SAVE_PATTERN = /\.dsg$/i;

    /* How long to wait after an engine sync before uploading. */
    var UPLOAD_DELAY = 800;

    /*
     * Where -levelstat writes. e6y_WriteStats() opens it with a bare relative
     * path and nothing in the engine ever calls chdir, so it lands in
     * Emscripten's default working directory rather than under SAVE_ROOT. It is
     * MEMFS, not IDBFS: it does not survive a reload, which is why it is read
     * while the game runs rather than at the end.
     */
    var LEVELSTAT_PATH = '/levelstat.txt';

    /*
     * How often to look at it. There is nothing to hook: the file is written
     * inside G_DoCompleted and the engine prints nothing on a normal level exit
     * (the FINISHED: line looks like a signal but only fires for demo playback
     * with the drawers off). A level exit is followed by an intermission screen
     * that takes a good deal longer than this, so nothing is missed.
     */
    var STAT_POLL_DELAY = 3000;

    /*
     * FNV-1a. Only ever compared against another hash of the same function, so it
     * needs to be fast and stable, not cryptographic.
     */
    function hashBytes(bytes) {
        var hash = 0x811c9dc5;

        for (var i = 0; i < bytes.length; i++) {
            hash ^= bytes[i];
            hash = (hash + (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24)) >>> 0;
        }

        return bytes.length + ':' + hash;
    }

    /* Uint8Array to base64, chunked so a 200KB save doesn't blow the stack. */
    function toBase64(bytes) {
        var chunks = [];

        for (var i = 0; i < bytes.length; i += 0x8000) {
            chunks.push(String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000)));
        }

        return btoa(chunks.join(''));
    }

    /* base64 back to a Uint8Array. */
    function fromBase64(text) {
        var binary = atob(text);
        var bytes = new Uint8Array(binary.length);

        for (var i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }

        return bytes;
    }

    var DaemonHost = Garnish.Base.extend({
        $container: null,
        $canvas: null,
        $overlay: null,
        $status: null,

        module: null,
        running: false,
        started: false,
        layerAdded: false,
        keyHandler: null,
        keyUpHandler: null,

        $saveMenu: null,

        /* path in the engine's filesystem => hash, as of the last upload. */
        baseline: null,

        /* Set while this code is the one writing, so its own syncs are not
           mistaken for the player saving. */
        writing: false,

        uploadTimer: null,
        uploading: false,

        /* Whether the engine has written a savegame since play started. */
        unsaved: false,

        /* Set while this code is the one navigating, so its own reload
           doesn't trip the warning. */
        leaving: false,

        unloadHandler: null,

        /* Whether the two window key listeners are currently attached. They come
           and go independently of the rest of captureInput(), because the
           leaderboard needs them off while the beforeunload warning stays on. */
        keysBound: false,

        statTimer: null,

        /* mtime of levelstat.txt as of the last look, so an unchanged file is
           not read. */
        statMtime: null,

        /* How many lines of levelstat.txt Craft has taken. The file is rewritten
           whole every level and only ever grows within a session, so this is
           where the new ones start. */
        statsSent: 0,

        statsPushing: false,

        /* Whether the last attempt to record a level failed. Play is not
           interrupted over it, but the board says so when it is opened, which is
           the moment somebody is looking for the level that is missing. */
        statsFailed: false,

        /* One skill per level exit, read off stdout, parallel to the lines in
           levelstat.txt. The engine prints it before it writes the file and does
           both in the same call, so the nth entry belongs to the nth line. */
        skills: null,

        $leaderboardBtn: null,
        slideout: null,

        /* Set while the leaderboard is open and the engine is being held. */
        suspended: false,
        keyBlocker: null,

        init: function (container, settings) {
            this.setSettings(settings, DaemonHost.defaults);

            this.$container = $(container);
            this.$canvas = this.$container.find('.daemon-canvas');
            this.$overlay = this.$container.find('.daemon-overlay');
            this.$status = this.$container.find('.daemon-status');

            this.addListener(this.$overlay.find('.daemon-start'), 'click', 'start');

            this.addListener(this.$canvas, 'contextmenu', function (ev) {
                ev.preventDefault();
            });

            if (this.settings.wadMenu) {
                this.addListener($(this.settings.wadMenu), 'click', 'onWadClick');
            }

            this.$saveMenu = $(this.settings.saveMenu);

            this.addListener(this.$saveMenu, 'click', 'onSaveMenuClick');

            this.refreshSaveMenu();

            this.skills = [];

            this.$leaderboardBtn = $(this.settings.leaderboardButton);

            this.addListener(this.$leaderboardBtn, 'click', 'openLeaderboard');
        },

        /**
         * Switching game is an ordinary page load: the engine takes its IWAD as an
         * argument to a main() that has already run and cannot be handed another.
         * The player chose to leave, so the unsaved warning is not their question.
         */
        onWadClick: function (ev) {
            var key = $(ev.target).closest('[data-wad]').data('wad');

            if (!key || key === this.settings.wad) {
                return;
            }

            this.leaving = true;
        },

        /**
         * The stream URL for the selected WAD.
         */
        wadUrl: function () {
            return this.settings.wadUrls[this.settings.wad];
        },

        /** Driven by a user gesture, because AudioContext will not start without one. */
        start: function () {
            if (this.running) {
                return;
            }

            this.running = true;
            this.started = true;
            this.$overlay.addClass('daemon-overlay--busy');

            var self = this;

            this.setStatus(Craft.t('daemon', 'Loading WAD…'));

            this.fetchBuffer(this.wadUrl())
                .then(function (wad) {
                    self.setStatus(Craft.t('daemon', 'Loading engine…'));
                    return self.fetchBuffer(self.settings.wasmUrl).then(function (wasm) {
                        return {wad: new Uint8Array(wad), wasm: wasm};
                    });
                })
                .then(function (assets) {
                    return self.boot(assets);
                })
                .catch(function (error) {
                    self.fail(error);
                });
        },

        /**
         * Fetches a URL as an ArrayBuffer. The .wasm comes through here too rather
         * than instantiateStreaming: cpresources is served by the site's own web
         * server, and plenty have no application/wasm in their MIME map.
         */
        fetchBuffer: function (url) {
            return fetch(url, {credentials: 'same-origin'}).then(function (response) {
                if (!response.ok) {
                    throw new Error(url + ' returned ' + response.status);
                }

                return response.arrayBuffer();
            });
        },

        boot: function (assets) {
            var self = this;
            var settings = this.settings;
            var canvas = this.$canvas[0];

            canvas.addEventListener('webglcontextlost', function (ev) {
                ev.preventDefault();
                self.fail(new Error(Craft.t('daemon', 'The WebGL context was lost. Reload the page to play again.')));
            }, false);

            var module = {
                canvas: canvas,

                // The engine runs main() itself, taking its command line from here.
                // -levelstat is PrBoom+'s own stat table, and the only way any
                // of this reaches the page: the build exports main() and the
                // filesystem, so a file is the only thing there is to read.
                arguments: settings.leaderboard
                    ? ['-iwad', wadPathFor(settings.wad), '-levelstat']
                    : ['-iwad', wadPathFor(settings.wad)],

                // The published URLs carry ?v= cache busters, so they have to be
                // handed over rather than derived from a base path.
                locateFile: function (path) {
                    if (path === 'index.wasm') {
                        return settings.wasmUrl;
                    }

                    if (path === 'index.data') {
                        return settings.dataUrl;
                    }

                    return path;
                },

                // From an ArrayBuffer, for the MIME-type reason above. Returning
                // {} tells Emscripten the instantiation is asynchronous.
                instantiateWasm: function (imports, successCallback) {
                    WebAssembly.instantiate(assets.wasm, imports)
                        .then(function (result) {
                            successCallback(result.instance, result.module);
                        })
                        .catch(function (error) {
                            self.fail(error);
                        });

                    return {};
                },

                preRun: [function () {
                    module.FS.writeFile(wadPathFor(settings.wad), assets.wad);
                    self.watchSaves(module.FS);
                }],

                onRuntimeInitialized: function () {
                    self.onReady();
                },

                onAbort: function (reason) {
                    self.fail(new Error(String(reason)));
                },

                /**
                 * The engine asks through this rather than calling requestPointerLock
                 * itself: a lock is only granted from a user gesture, so a request
                 * raised from the game loop is refused.
                 */
                captureMouse: function () {
                    if (!settings.pointerLock) {
                        return;
                    }

                    if (module._canLockPointer !== false && !self.attemptPointerLock()) {
                        module._canLockPointer = false;
                        document.addEventListener('keydown', self.lockPointerOnKey);
                    }
                },

                winResized: function () {
                    // Sizing is the engine's; the stylesheet only says how large.
                },

                softExit: function (status) {
                    self.unsaved = false;
                    self.releaseInput();
                    console.log('[daemon] engine exited with status', status);
                },

                print: function (text) {
                    self.onEnginePrint(text);
                    console.log(text);
                },

                printErr: function (text) {
                    console.error(text);
                },

                setStatus: function () {
                    // Only exists to stop the engine's status handling throwing.
                },

                monitorRunDependencies: function () {
                },
            };

            this.module = window.Module = module;

            this.lockPointerOnKey = function (event) {
                if (event.key === 'Escape' || self.attemptPointerLock()) {
                    document.removeEventListener('keydown', self.lockPointerOnKey);
                    module._canLockPointer = true;
                }
            };

            return this.loadScript(this.settings.engineUrl);
        },

        attemptPointerLock: function () {
            var canvas = this.$canvas[0];

            if (document.pointerLockElement === null && canvas.requestPointerLock) {
                var result = canvas.requestPointerLock();

                // Chrome rejects if the document isn't focused. Nothing to do
                // about that but not throw.
                if (result && typeof result.catch === 'function') {
                    result.catch(function () {
                    });
                }
            }

            return document.pointerLockElement !== null;
        },

        /** Injects the Emscripten glue, which reads the global Module assigned above. */
        loadScript: function (url) {
            return new Promise(function (resolve, reject) {
                var script = document.createElement('script');
                script.src = url;
                script.onload = resolve;
                script.onerror = function () {
                    reject(new Error('Could not load ' + url));
                };
                document.body.appendChild(script);
            });
        },

        onReady: function () {
            this.unsaved = true;
            this.$overlay.addClass('daemon-overlay--hidden');
            this.captureInput();
            this.$canvas.trigger('focus');
            this.startStatPoll();
        },

        /**
         * Scopes Craft's shortcuts away with a Garnish UI layer, the mechanism modals
         * use. The capture-phase handler only calls preventDefault: stopPropagation
         * would take the keys from the game as well as from Craft.
         */
        captureInput: function () {
            if (Garnish.uiLayerManager && typeof Garnish.uiLayerManager.addLayer === 'function') {
                Garnish.uiLayerManager.addLayer(this.$container);
                this.layerAdded = true;
            }

            this.keyHandler = this.onKey.bind(this);

            // Keyups need the correction too: SDL matches a release to its press
            // by keycode, and a mismatch leaves the game holding a key down.
            this.keyUpHandler = this.onKeyUp.bind(this);

            this.bindKeys();

            this.unloadHandler = this.onBeforeUnload.bind(this);
            window.addEventListener('beforeunload', this.unloadHandler);
        },

        /** Attaches the two key listeners, if they are not already on. */
        bindKeys: function () {
            if (this.keysBound || !this.keyHandler) {
                return;
            }

            window.addEventListener('keydown', this.keyHandler, true);
            window.addEventListener('keyup', this.keyUpHandler, true);
            this.keysBound = true;
        },

        unbindKeys: function () {
            if (!this.keysBound) {
                return;
            }

            window.removeEventListener('keydown', this.keyHandler, true);
            window.removeEventListener('keyup', this.keyUpHandler, true);
            this.keysBound = false;
        },

        /**
         * Warns before leaving with progress the engine hasn't written down. Doom
         * saves when the player says so and at no other moment, so a closed tab is a
         * lost level.
         */
        onBeforeUnload: function (ev) {
            if (!this.unsaved || this.leaving) {
                return;
            }

            // Both, because which one a browser listens to varies.
            ev.preventDefault();
            ev.returnValue = '';

            return '';
        },

        releaseInput: function () {
            this.unbindKeys();
            this.keyHandler = null;
            this.keyUpHandler = null;

            if (this.unloadHandler) {
                window.removeEventListener('beforeunload', this.unloadHandler);
                this.unloadHandler = null;
            }

            if (this.lockPointerOnKey) {
                document.removeEventListener('keydown', this.lockPointerOnKey);
            }

            if (this.layerAdded) {
                Garnish.uiLayerManager.removeLayer();
                this.layerAdded = false;
            }
        },

        onKey: function (ev) {
            // Before the metaKey check: a chord left alone still reaches the
            // game, and should reach it as the key actually pressed.
            unfakeNumpad(ev);

            if (ev.metaKey || ev.altKey) {
                // Cmd-W is not a weapon switch.
                return;
            }

            if (SWALLOWED_KEYS.indexOf(ev.code) !== -1) {
                ev.preventDefault();
            }
        },

        onKeyUp: function (ev) {
            unfakeNumpad(ev);
        },

        /**
         * Wrapping FS.syncfs hooks the engine's save flow without patching it. The
         * populate call is the restore at startup, every other call is the engine
         * writing something down. Runs in preRun, the only moment guaranteed to be
         * earlier than its first sync.
         */
        watchSaves: function (FS) {
            var self = this;
            var original = FS.syncfs;

            FS.syncfs = function (populate, callback) {
                // Emscripten allows both syncfs(populate, cb) and syncfs(cb).
                var isRestore = typeof populate !== 'function' && !!populate;
                var done = typeof populate === 'function' ? populate : callback;

                var wrapped = function (err) {
                    if (!err) {
                        if (isRestore) {
                            self.onEngineRestored();
                        } else if (!self.writing) {
                            self.onEngineSaved();
                        }
                    }

                    if (done) {
                        done(err);
                    }
                };

                if (typeof populate === 'function') {
                    return original.call(FS, wrapped);
                }

                return original.call(FS, populate, wrapped);
            };
        },

        /**
         * The first moment the save directory means anything. Fills gaps only: a save
         * already in the filesystem is at least as new as the stored copy, and may be
         * one that never finished uploading.
         */
        onEngineRestored: function () {
            var self = this;

            this.baseline = {};

            // Taken even when saves aren't kept: it is what tells the unload
            // warning whether the player has saved.
            if (!this.settings.autosave) {
                this.snapshot();

                return;
            }

            Craft.sendActionRequest('GET', this.settings.saveActions.restore, {
                params: {wad: this.settings.wad},
            }).then(function (response) {
                var saves = (response.data && response.data.saves) || [];
                var written = 0;

                saves.forEach(function (save) {
                    if (self.writeSave(save, false)) {
                        written++;
                    }
                });

                if (written) {
                    self.persist();
                }
            }).catch(function (error) {
                console.warn('[daemon] could not restore saves', error);
            }).then(function () {
                self.snapshot();
            });
        },

        /**
         * The engine has just written something down, a savegame or only its config,
         * so the upload works out which.
         */
        onEngineSaved: function () {
            var self = this;

            // The engine syncs more than once per save, and for its config too,
            // so let it settle and then find out what changed.
            if (this.uploadTimer) {
                clearTimeout(this.uploadTimer);
            }

            this.uploadTimer = setTimeout(function () {
                self.uploadTimer = null;

                var changed = self.collectChanged();

                if (!changed.length) {
                    // A config write, not a save. Progress is still unsaved.
                    return;
                }

                self.unsaved = false;

                if (self.settings.autosave) {
                    // The game's Save Game is the only way saves get here, so
                    // nothing else would say one reached Craft.
                    self.pushChanged(changed, {notify: true});
                }
            }, UPLOAD_DELAY);
        },

        /**
         * Uploads an already-collected set of changed saves.
         */
        pushChanged: function (changed, options) {
            options = options || {};

            if (this.uploading) {
                return Promise.resolve(0);
            }

            var self = this;
            this.uploading = true;

            return Craft.sendActionRequest('POST', this.settings.saveActions.upload, {
                data: {wad: this.settings.wad, saves: changed},
            }).then(function (response) {
                self.commit(changed);
                self.refreshSaveMenu();

                if (options.notify) {
                    Craft.cp.displayNotice(response.data.message);
                }

                return changed.length;
            }).catch(function (error) {
                console.warn('[daemon] could not keep saves', error);

                if (options.notify) {
                    Craft.cp.displayError(Craft.t('daemon', 'Could not keep the saves.'));
                }

                return 0;
            }).then(function (count) {
                self.uploading = false;

                return count;
            });
        },

        /** Every savegame whose bytes differ from the last upload, as {path, data}. */
        collectChanged: function () {
            var FS = this.module.FS;
            var changed = [];
            var self = this;

            this.eachSaveFile(function (path) {
                var bytes;

                try {
                    bytes = FS.readFile(path);
                } catch (e) {
                    return;
                }

                var hash = hashBytes(bytes);
                var relative = path.slice(SAVE_ROOT.length + 1);

                if (self.baseline[relative] === hash) {
                    return;
                }

                changed.push({path: relative, data: toBase64(bytes), hash: hash});
            });

            return changed;
        },

        /**
         * Records an upload as the new baseline, after the response rather than
         * before, so a failed upload is retried on the next save.
         */
        commit: function (changed) {
            var self = this;

            changed.forEach(function (item) {
                self.baseline[item.path] = item.hash;
                delete item.hash;
            });
        },

        /**
         * Takes the save directory as it stands, so the next upload sends only what
         * changed after this moment.
         */
        snapshot: function () {
            if (!this.module) {
                return;
            }

            var FS = this.module.FS;
            var self = this;

            this.eachSaveFile(function (path) {
                try {
                    self.baseline[path.slice(SAVE_ROOT.length + 1)] = hashBytes(FS.readFile(path));
                } catch (e) {
                    // Unreadable now reads as changed later, the safe way round.
                }
            });
        },

        /**
         * Calls back with every savegame under the save root. PrBoom nests them a
         * directory deep, named after a digest of the loaded WADs, unless the player
         * turns that off, so this walks rather than assuming a depth.
         */
        eachSaveFile: function (callback, dir) {
            var FS = this.module.FS;
            var entries;
            dir = dir || SAVE_ROOT;

            try {
                entries = FS.readdir(dir);
            } catch (e) {
                return;
            }

            for (var i = 0; i < entries.length; i++) {
                var name = entries[i];

                if (name === '.' || name === '..') {
                    continue;
                }

                var path = dir + '/' + name;
                var stat;

                try {
                    stat = FS.stat(path);
                } catch (e) {
                    continue;
                }

                if (FS.isDir(stat.mode)) {
                    this.eachSaveFile(callback, path);
                } else if (SAVE_PATTERN.test(name)) {
                    callback(path);
                }
            }
        },

        /**
         * Writes one stored save into the engine's filesystem.
         *
         * @return {boolean} whether anything was written.
         */
        writeSave: function (save, overwrite) {
            var FS = this.module.FS;
            var path = SAVE_ROOT + '/' + save.path;

            if (!overwrite) {
                try {
                    FS.stat(path);

                    return false;
                } catch (e) {
                    // Not there, which is the case worth writing.
                }
            }

            var bytes = fromBase64(save.data);

            try {
                this.makeDirs(path.slice(0, path.lastIndexOf('/')));
                FS.writeFile(path, bytes);
            } catch (e) {
                console.warn('[daemon] could not write ' + path, e);

                return false;
            }

            // Craft has these bytes already, so the baseline must agree or the
            // next sync uploads them straight back.
            this.baseline[save.path] = hashBytes(bytes);

            return true;
        },

        /** mkdir -p, because FS.mkdir makes one level and throws if it is already there. */
        makeDirs: function (dir) {
            var FS = this.module.FS;
            var parts = dir.split('/');
            var path = '';

            for (var i = 0; i < parts.length; i++) {
                if (parts[i] === '') {
                    continue;
                }

                path += '/' + parts[i];

                try {
                    FS.mkdir(path);
                } catch (e) {
                    // Already there.
                }
            }
        },

        /**
         * Pushes the filesystem into IndexedDB, flagged so the wrapper above does not
         * read our own write as the player's.
         */
        persist: function () {
            var self = this;
            this.writing = true;

            this.module.FS.syncfs(false, function () {
                self.writing = false;
            });
        },

        /**
         * Restores a version into the slot it came from and stops there. The engine
         * reads a save when its own Load menu asks for one, and nothing out here can
         * ask for it without a build that exports G_LoadGame.
         */
        onSaveMenuClick: function (ev) {
            var $item = $(ev.target).closest('[data-save-id]');

            if (!$item.length) {
                return;
            }

            ev.preventDefault();

            if (!this.module) {
                Craft.cp.displayNotice(Craft.t('daemon', 'Start the game first.'));

                return;
            }

            var self = this;

            Craft.sendActionRequest('GET', this.settings.saveActions.read, {
                params: {wad: this.settings.wad, id: $item.data('save-id')},
            }).then(function (response) {
                if (!self.writeSave(response.data.save, true)) {
                    throw new Error('write failed');
                }

                self.persist();
                Craft.cp.displayNotice(Craft.t('daemon', 'Restored. Press Esc, then Load Game, to play it.'));
            }).catch(function (error) {
                console.warn('[daemon] could not restore a save', error);
                Craft.cp.displayError(Craft.t('daemon', 'Could not restore that save.'));
            });
        },

        /** Rebuilds the menu next to the breadcrumbs from what Craft is holding. */
        refreshSaveMenu: function () {
            if (!this.$saveMenu.length) {
                return;
            }

            var self = this;

            Craft.sendActionRequest('GET', this.settings.saveActions.list, {
                params: {wad: this.settings.wad},
            }).then(function (response) {
                self.renderSaveMenu(response.data.saves || []);
            }).catch(function (error) {
                console.warn('[daemon] could not list saves', error);
            });
        },

        /**
         * Draws the menu items. Built here rather than fetched as rendered markup
         * because the list changes after every save.
         */
        renderSaveMenu: function (saves) {
            var $list = this.$saveMenu.find('.daemon-saves-list').empty();

            this.$saveMenu.find('.daemon-saves-empty').toggleClass('hidden', saves.length > 0);

            // Shaped like Cp::menuItem()'s markup, utility classes included:
            // the column the description stacks in comes from those.
            saves.forEach(function (save) {
                $('<li/>').append(
                    $('<button/>', {
                        type: 'button',
                        'class': 'menu-item',
                        'data-save-id': save.id,
                    }).append(
                        $('<span/>', {
                            'class': 'menu-item-label inline-flex flex-col items-start gap-2xs',
                            text: save.label,
                        }).append(
                            $('<span/>', {
                                'class': 'menu-item-description mt-2xs smalltext light',
                                text: save.timestamp,
                            })
                        )
                    )
                ).appendTo($list);
            });
        },

        /**
         * Watches stdout for the skill.
         *
         * The engine prints this at every level exit, from a patch this plugin
         * applies to its build: neither -levelstat nor -statdump records the
         * skill, and a board pooling Nightmare with I'm Too Young To Die is not
         * a board. Match this against bin/build-engine.sh, which is the other
         * half of it.
         */
        onEnginePrint: function (text) {
            var match = /^G_DoCompleted: skill (\d+)/.exec(text);

            if (match) {
                this.skills.push(parseInt(match[1], 10));
            }
        },

        startStatPoll: function () {
            if (!this.settings.leaderboard || this.statTimer) {
                return;
            }

            var self = this;

            this.statTimer = setInterval(function () {
                self.pollStats();
            }, STAT_POLL_DELAY);
        },

        stopStatPoll: function () {
            if (this.statTimer) {
                clearInterval(this.statTimer);
                this.statTimer = null;
            }
        },

        /**
         * Sends whatever the engine has written down since the last look.
         *
         * @return {Promise} for the number of levels sent, resolved immediately
         * when there was nothing to send.
         */
        pollStats: function () {
            if (!this.settings.leaderboard || !this.module || this.statsPushing) {
                return Promise.resolve(0);
            }

            var FS = this.module.FS;
            var stat;

            try {
                stat = FS.stat(LEVELSTAT_PATH);
            } catch (e) {
                // Not there yet, which is every poll until the first level ends.
                return Promise.resolve(0);
            }

            var mtime = stat.mtime ? stat.mtime.getTime() : 0;

            if (mtime === this.statMtime) {
                return Promise.resolve(0);
            }

            this.statMtime = mtime;

            var text;

            try {
                text = FS.readFile(LEVELSTAT_PATH, {encoding: 'utf8'});
            } catch (e) {
                return Promise.resolve(0);
            }

            var lines = [];

            text.split(/\r\n|\r|\n/).forEach(function (line) {
                line = line.trim();

                if (line !== '') {
                    lines.push(line);
                }
            });

            // The table only grows within a session: numlevels is a global the
            // engine never resets, and the page load that would reset it takes
            // this counter with it. Clamped anyway, because re-sending a line
            // counts the level as finished twice.
            if (lines.length < this.statsSent) {
                this.statsSent = lines.length;
            }

            if (lines.length === this.statsSent) {
                return Promise.resolve(0);
            }

            var self = this;
            var levels = lines.slice(this.statsSent).map(function (line, i) {
                var skill = self.skills[self.statsSent + i];

                return {
                    line: line,
                    // undefined rather than a guess when the two have drifted
                    // apart, which they should not, but a wrong skill is worse
                    // on this board than a missing one.
                    skill: typeof skill === 'number' ? skill : null,
                };
            });

            return this.pushStats(levels, lines.length);
        },

        /**
         * Uploads an already-collected set of levels.
         */
        pushStats: function (levels, total) {
            var self = this;
            this.statsPushing = true;

            return Craft.sendActionRequest('POST', this.settings.statActions.record, {
                data: {wad: this.settings.wad, levels: levels},
            }).then(function (response) {
                // A refusal the controller handled is a 200 carrying
                // success: false, so it arrives here rather than below. Taking
                // it for a success would advance the counter past levels Craft
                // never stored, and they would never be sent again.
                if (response.data && response.data.success === false) {
                    throw new Error(response.data.message || 'refused');
                }

                // After the response rather than before, so a failed request
                // leaves the counter where it was and the next level sends
                // these lines again.
                self.statsSent = total;
                self.statsFailed = false;

                return levels.length;
            }).catch(function (error) {
                console.warn('[daemon] could not record level stats', error);
                self.statsFailed = true;

                return 0;
            }).then(function (count) {
                self.statsPushing = false;

                return count;
            });
        },

        /**
         * Opens the board. Anything the engine has written but not yet sent goes
         * first, so a level finished seconds ago is on the board being opened.
         */
        openLeaderboard: function (ev) {
            if (ev) {
                ev.preventDefault();
            }

            if (this.slideout) {
                return;
            }

            var self = this;

            this.pollStats().then(function () {
                return Craft.sendActionRequest('GET', self.settings.statActions.board, {
                    params: {wad: self.settings.wad},
                });
            }).then(function (response) {
                self.showLeaderboard(response.data.html);

                // Said here rather than when it happened: a toast in the middle
                // of a firefight is worse than one over the board that is
                // missing the level.
                if (self.statsFailed) {
                    Craft.cp.displayError(Craft.t('daemon', 'Some levels could not be recorded. See the browser console.'));
                }
            }).catch(function (error) {
                console.warn('[daemon] could not open the leaderboard', error);
                Craft.cp.displayError(Craft.t('daemon', 'Could not load the leaderboard.'));
            });
        },

        /**
         * Puts the rendered board in a slideout and holds the game while it is up.
         *
         * suspendGame() runs first on purpose: it pops this screen's UI layer,
         * and the slideout pushes its own in the constructor below. The other way
         * round would pop the slideout's.
         */
        showLeaderboard: function (html) {
            var self = this;

            this.suspendGame();

            this.slideout = new Craft.Slideout(html, {
                containerAttributes: {'class': 'daemon-board-slideout'},
            });

            this.slideout.on('close', function () {
                self.slideout.destroy();
                self.slideout = null;
                self.resumeGame();
            });

            this.addListener(this.slideout.$container.find('[data-daemon-close]'), 'click', function () {
                self.slideout.close();
            });
        },

        /**
         * Holds the game still while the board is open. Three things, each needed.
         *
         * The main loop is paused, or the monsters carry on while you read.
         *
         * This screen's own capture-phase listener comes off, because it calls
         * preventDefault on Space, Tab and Enter, which are how a slideout is
         * used.
         *
         * And keys are stopped from reaching the engine, which is the one place
         * in this file that calls stopPropagation. It is safe here and nowhere
         * else because of where everything listens: SDL registers on window with
         * useCapture 0 (SDL_emscriptenevents.c), so it is last in the bubble
         * chain, while Garnish's shortcut manager is on body and the slideout's
         * focus trap is on its own container. A listener on document sits between
         * them and cuts the engine off without taking a key from anything else.
         * Without it every key typed into the slideout would queue up inside SDL
         * and be played into the game on resume.
         */
        suspendGame: function () {
            if (this.suspended) {
                return;
            }

            this.suspended = true;

            this.keyBlocker = function (ev) {
                ev.stopPropagation();
            };

            document.addEventListener('keydown', this.keyBlocker);
            document.addEventListener('keyup', this.keyBlocker);
            document.addEventListener('keypress', this.keyBlocker);

            this.unbindKeys();

            if (this.layerAdded) {
                Garnish.uiLayerManager.removeLayer();
                this.layerAdded = false;
            }

            if (this.running && this.module && typeof this.module.pauseMainLoop === 'function') {
                this.module.pauseMainLoop();
            }
        },

        /** Hands the game back its keyboard and its main loop. */
        resumeGame: function () {
            if (!this.suspended) {
                return;
            }

            this.suspended = false;

            document.removeEventListener('keydown', this.keyBlocker);
            document.removeEventListener('keyup', this.keyBlocker);
            document.removeEventListener('keypress', this.keyBlocker);
            this.keyBlocker = null;

            // Nothing to give back if the board was opened before pressing Play.
            if (!this.running) {
                return;
            }

            if (this.module && typeof this.module.resumeMainLoop === 'function') {
                this.module.resumeMainLoop();
            }

            if (Garnish.uiLayerManager && typeof Garnish.uiLayerManager.addLayer === 'function') {
                Garnish.uiLayerManager.addLayer(this.$container);
                this.layerAdded = true;
            }

            this.bindKeys();
            this.$canvas.trigger('focus');
        },

        setStatus: function (message) {
            this.$status.text(message);
        },

        fail: function (error) {
            console.error('[daemon]', error);
            this.running = false;
            this.stopStatPoll();
            this.releaseInput();
            this.$overlay
                .removeClass('daemon-overlay--busy daemon-overlay--hidden')
                .addClass('daemon-overlay--error');
            this.setStatus(error && error.message ? error.message : String(error));
        },

        destroy: function () {
            this.stopStatPoll();
            this.resumeGame();
            this.releaseInput();
            this.base();
        },
    }, {
        defaults: {
            engineUrl: null,
            wasmUrl: null,
            dataUrl: null,
            wad: null,
            wadUrls: {},
            wadMenu: null,
            saveActions: {},
            saveMenu: null,
            statActions: {},
            leaderboardButton: null,
            autosave: true,
            leaderboard: false,
            pointerLock: true,
        },
    });

    Craft.Daemon = {
        DaemonHost: DaemonHost,

        boot: function (container, settings) {
            return new DaemonHost(container, settings);
        },
    };
})(jQuery);

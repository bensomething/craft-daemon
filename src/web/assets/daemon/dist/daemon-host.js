/*
 * Host layer for the Doom CP section.
 *
 * The engine is Dwasm (PrBoom+ / PrBoomX compiled to WebAssembly). Everything
 * here is the shim between it and a Craft control panel that has its own
 * opinions about keyboard input, its own web server serving cpresources, and
 * its own ideas about focus.
 *
 * Dwasm normally ships an IWAD baked into index.data at build time. This plugin
 * does not ship a WAD, so the engine is built with only its own resource WAD
 * preloaded and the admin's IWAD is written into the filesystem here, before
 * the engine starts.
 */
(function ($) {
    'use strict';

    /*
     * Keys the browser acts on itself and the game also wants. preventDefault
     * on these while playing; never stopPropagation, because SDL listens on
     * window and stopping propagation takes the input away from the game as
     * well as from Craft.
     */
    var SWALLOWED_KEYS = [
        'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight',
        'Space', 'Tab', 'Enter', 'Backspace',
        'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', 'F11', 'F12',
        'Slash', 'Quote',
    ];

    /*
     * The arrow keys, by the name the browser gives the physical key.
     *
     * ev.code, not ev.key: the fix below has to tell a real numpad press from
     * an arrow key claiming to be one, and only ev.code knows the difference.
     */
    var ARROW_CODES = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'];

    /*
     * Undoes Safari's claim that the arrow keys are on the numeric keypad.
     *
     * macOS sets the numeric-pad flag on the arrow keys, and WebKit passes it
     * through as KeyboardEvent.location 3. SDL believes it: its keycode mapping
     * has a numpad branch that turns SDLK_UP into SDLK_KP_8, SDLK_DOWN into
     * SDLK_KP_2, and left and right into KP_4 and KP_6. PrBoom then receives
     * KEYD_KEYPAD8 where key_menu_up expects KEYD_UPARROW, and nothing at all
     * is bound to the keypad, so in Safari the menu cursor will not move, the
     * option sliders will not slide, and the arrows will not turn the player.
     * Every other key is untouched, which is why WASD, Ctrl, Space and Escape
     * work there and only the arrows appear dead.
     *
     * SDL derives the scancode from ev.code, which is right in every browser,
     * so only the keycode needs correcting. location is a getter on the
     * prototype, and shadowing it with an own property on the event is enough:
     * this runs on a capture-phase window listener, SDL's own listener runs
     * later on the same event object, and it reads the corrected value.
     *
     * A genuine numpad press arrives as Numpad8 rather than ArrowUp, so the
     * keys people actually press on a keypad are left alone.
     */
    function unfakeNumpad(ev) {
        if (ev.location === 0 || ARROW_CODES.indexOf(ev.code) === -1) {
            return;
        }

        try {
            Object.defineProperty(ev, 'location', {value: 0, configurable: true});
        } catch (e) {
            // Some other engine's event object, or a browser that refuses.
            // The key still reaches the game, it is just the wrong one.
        }
    }

    /*
     * Where the admin's IWAD is written before the engine starts.
     *
     * The filename matters. PrBoom keeps savegames in a directory named after
     * an MD5 of the basenames of the loaded WADs, which is what gives each
     * game its own set of save slots. Write every IWAD to one fixed path and
     * every game hashes the same, so a Doom II save lands in the same slot as
     * a Freedoom one and overwrites it. The key is already [a-z0-9-], so it is
     * safe to use as a filename as it stands.
     */
    function wadPathFor(key) {
        return '/' + key + '.wad';
    }

    /*
     * Where Dwasm mounts IDBFS, and therefore where every savegame lives.
     * Hardcoded in the engine as I_DoomExeDir(), so it is hardcoded here too.
     */
    var SAVE_ROOT = '/dwasm';

    /* What the engine calls a savegame. */
    var SAVE_PATTERN = /\.dsg$/i;

    /* How long to wait after an engine sync before uploading. */
    var UPLOAD_DELAY = 800;

    /*
     * FNV-1a over the file's bytes. Only ever compared against another hash of
     * the same function, to answer "did this save change since we last looked",
     * so it needs to be fast and stable, not cryptographic.
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
        },

        /**
         * Switching game.
         *
         * The crumb menu items are links to this page with a different ?wad=,
         * so the switch is an ordinary page load and the server renders the
         * new game. That is not just simpler than swapping in place: the
         * engine could not be swapped anyway once it has started. It takes its
         * IWAD as a command line argument to a main() that has already run,
         * its filesystem is written in preRun, and Emscripten's glue is not
         * built to be instantiated twice in one document.
         *
         * So all that is left for this to do is get out of the way quietly:
         * the player chose to leave this game, and the unsaved warning would
         * be asking them about something they just decided.
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

        /**
         * Everything from the click through to the first frame. Driven by a
         * user gesture because AudioContext will not start without one.
         */
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
         * Fetches a URL as an ArrayBuffer.
         *
         * The .wasm goes through here too, deliberately. instantiateStreaming
         * would be the obvious call, but cpresources is served by the site's
         * own nginx or Apache and plenty of stacks have no application/wasm
         * entry in their MIME map, which makes streaming fail on the customer's
         * server and nowhere else.
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

                // The engine runs main() itself once its dependencies resolve,
                // taking its command line from here.
                arguments: ['-iwad', wadPathFor(settings.wad)],

                // index.wasm and index.data sit next to index.js in cpresources,
                // but the published URLs carry ?v= cache busters, so they have
                // to be handed over rather than derived from a base path.
                locateFile: function (path) {
                    if (path === 'index.wasm') {
                        return settings.wasmUrl;
                    }

                    if (path === 'index.data') {
                        return settings.dataUrl;
                    }

                    return path;
                },

                // Resolves the .wasm ourselves, from an ArrayBuffer, for the
                // MIME-type reason above. Returning {} tells Emscripten the
                // instantiation is asynchronous.
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
                 * The engine asks for pointer lock through this rather than
                 * calling requestPointerLock itself, which is what makes it
                 * safe: the browser only grants a lock from a user gesture, so
                 * a request raised from the game loop is refused. If the
                 * immediate attempt fails, wait for a keypress and try again.
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
                    // Canvas sizing is left to the engine; the stylesheet only
                    // constrains how large it is displayed.
                },

                softExit: function (status) {
                    self.unsaved = false;
                    self.releaseInput();
                    console.log('[daemon] engine exited with status', status);
                },

                print: function (text) {
                    console.log(text);
                },

                printErr: function (text) {
                    console.error(text);
                },

                setStatus: function () {
                    // The overlay covers startup progress; this only exists to
                    // stop the engine's own status handling from throwing.
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

                // Chrome returns a promise here and rejects if the document
                // isn't focused; nothing to do about it but not throw.
                if (result && typeof result.catch === 'function') {
                    result.catch(function () {
                    });
                }
            }

            return document.pointerLockElement !== null;
        },

        /**
         * Injects the Emscripten glue script. It reads the global Module we
         * just assigned, which is why this runs last.
         */
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
        },

        /**
         * Keeps Craft's keyboard shortcuts out of the game.
         *
         * Garnish scopes shortcuts to the topmost UI layer, the same mechanism
         * modals use, so pushing a layer suppresses the CP's own bindings
         * without touching a single listener. The capture-phase handler that
         * follows only calls preventDefault, never stopPropagation: SDL listens
         * on window, so anything that halts propagation disarms the game along
         * with Craft.
         */
        captureInput: function () {
            if (Garnish.uiLayerManager && typeof Garnish.uiLayerManager.addLayer === 'function') {
                Garnish.uiLayerManager.addLayer(this.$container);
                this.layerAdded = true;
            }

            this.keyHandler = this.onKey.bind(this);
            window.addEventListener('keydown', this.keyHandler, true);

            // Keyups only need the numpad correction, never preventDefault:
            // the browser has no default action left to take by then. They do
            // need it though, because SDL matches a release against the press
            // by keycode, and a press that arrived as an arrow and a release
            // that arrived as a keypad key leaves the game holding a key down.
            this.keyUpHandler = this.onKeyUp.bind(this);
            window.addEventListener('keyup', this.keyUpHandler, true);

            this.unloadHandler = this.onBeforeUnload.bind(this);
            window.addEventListener('beforeunload', this.unloadHandler);
        },

        /**
         * Warns before leaving with progress the engine hasn't written down.
         *
         * Doom saves when the player says so and at no other moment, so a
         * closed tab is a lost level. Browsers decide the wording themselves
         * and only honour this at all if the page has been interacted with,
         * which pressing Play guarantees.
         */
        onBeforeUnload: function (ev) {
            if (!this.unsaved || this.leaving) {
                return;
            }

            // Both, because which one a browser listens to depends on the
            // browser, and neither is expensive to set.
            ev.preventDefault();
            ev.returnValue = '';

            return '';
        },

        releaseInput: function () {
            if (this.keyHandler) {
                window.removeEventListener('keydown', this.keyHandler, true);
                this.keyHandler = null;
            }

            if (this.keyUpHandler) {
                window.removeEventListener('keyup', this.keyUpHandler, true);
                this.keyUpHandler = null;
            }

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
            // Before the metaKey check, not after: a chord this code leaves
            // alone still reaches the game, and it should reach it as the key
            // that was actually pressed.
            unfakeNumpad(ev);

            if (ev.metaKey || ev.altKey) {
                // Leave the browser's own chords alone. Cmd-W is not a weapon
                // switch and pretending otherwise only annoys people.
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
         * Watches the engine's own persistence.
         *
         * Dwasm keeps saves in IDBFS and calls FS.syncfs after every one, so
         * wrapping syncfs is a hook into the engine's save flow that needs no
         * change to the engine. The populate call is the restore at startup;
         * every other call is the engine writing something down.
         *
         * This runs in preRun, before the engine has mounted anything, which
         * is the only moment guaranteed to be earlier than its first sync.
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
         * The engine has finished pulling its filesystem out of IndexedDB, so
         * this is the first moment the save directory means anything.
         *
         * Anything Craft holds that the browser does not gets written in. Only
         * the gaps: a save already in the filesystem is at least as new as the
         * stored copy, and overwriting it would lose a save made in a session
         * that never finished uploading.
         */
        onEngineRestored: function () {
            var self = this;

            this.baseline = {};

            // The baseline still gets taken when saves aren't being kept: it
            // is what tells the unload warning whether the player has saved.
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
         * The engine has just written something down. Usually a savegame,
         * sometimes only its config, so the upload works out which.
         */
        onEngineSaved: function () {
            var self = this;

            // The engine syncs more than once around a single save, and syncs
            // for its config as well as for savegames, so wait for it to
            // settle and then find out what actually changed.
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
                    // Notified, because the engine's Save Game is now the only
                    // way saves get here: nothing else would tell the player
                    // their save reached Craft rather than only the browser.
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

        /**
         * Every savegame whose bytes differ from the last upload, as
         * {path, data} ready to post.
         */
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
         * Records an upload as the new baseline. Done after the response
         * rather than before, so a failed upload is retried on the next save.
         */
        commit: function (changed) {
            var self = this;

            changed.forEach(function (item) {
                self.baseline[item.path] = item.hash;
                delete item.hash;
            });
        },

        /**
         * Takes the current state of the save directory as the baseline, so
         * the next upload sends only what changed after this moment.
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
                    // A file that cannot be read now reads as changed later,
                    // which is the safe way round.
                }
            });
        },

        /**
         * Calls back with the absolute path of every savegame under the save
         * root. PrBoom nests them one directory deep, named after a digest of
         * the loaded WADs, unless the player turns that off in its own menu,
         * so this walks rather than assuming a depth.
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

            // Craft already has these bytes, so the baseline has to agree, or
            // the next sync uploads them straight back.
            this.baseline[save.path] = hashBytes(bytes);

            return true;
        },

        /**
         * mkdir -p, because FS.mkdir makes one level and throws if it is
         * already there.
         */
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
         * Pushes the filesystem into IndexedDB, flagged so the wrapper above
         * does not read our own write as the player saving.
         */
        persist: function () {
            var self = this;
            this.writing = true;

            this.module.FS.syncfs(false, function () {
                self.writing = false;
            });
        },

        /**
         * Restores a version from the menu.
         *
         * This writes the file into the slot it came from and stops there. The
         * engine reads a save when its own Load menu asks for one, and there
         * is no way in from out here without a build that exports G_LoadGame,
         * so the player finishes the job from inside the game.
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

        /**
         * Rebuilds the menu next to the breadcrumbs from what Craft is
         * holding.
         */
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
         * Draws the menu items. Built here rather than fetched as rendered
         * markup because the list changes after every save, and a round trip
         * for four elements is a round trip for nothing.
         */
        renderSaveMenu: function (saves) {
            var $list = this.$saveMenu.find('.daemon-saves-list').empty();

            this.$saveMenu.find('.daemon-saves-empty').toggleClass('hidden', saves.length > 0);

            // Shaped like the markup Cp::menuItem() emits, down to the
            // utility classes: the description is nested inside the label
            // rather than beside it, and the column it stacks in comes from
            // those classes, not from anything this plugin ships.
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

        setStatus: function (message) {
            this.$status.text(message);
        },

        fail: function (error) {
            console.error('[daemon]', error);
            this.running = false;
            this.releaseInput();
            this.$overlay
                .removeClass('daemon-overlay--busy daemon-overlay--hidden')
                .addClass('daemon-overlay--error');
            this.setStatus(error && error.message ? error.message : String(error));
        },

        destroy: function () {
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
            autosave: true,
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

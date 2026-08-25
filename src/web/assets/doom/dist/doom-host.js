/*
 * Host layer for the Doom CP section.
 *
 * The compiled engine is upstream's; everything here is the shim between it
 * and a Craft control panel that has its own opinions about keyboard input,
 * its own web server serving cpresources, and its own ideas about focus.
 */
(function ($) {
    'use strict';

    /*
     * Keys the browser acts on itself and the game also wants. preventDefault
     * on these while playing; never stopPropagation, because SDL2 listens on
     * window in the bubble phase and stopping propagation anywhere below that
     * takes the input away from the game as well as from Craft.
     */
    var SWALLOWED_KEYS = [
        'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight',
        'Space', 'Tab', 'Enter', 'Backspace',
        'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', 'F11', 'F12',
        'Slash', 'Quote',
    ];

    /*
     * Where the game's writable state lives. Mounted from IndexedDB, so
     * savegames and the config survive a page load without a server round trip.
     */
    var PERSIST_DIR = '/persist';

    /*
     * Written into the Emscripten filesystem before the game starts. The bytes
     * come from a permission-gated Craft action, not from a static URL.
     */
    var WAD_PATH = '/doom.wad';

    var DoomHost = Garnish.Base.extend({
        $container: null,
        $canvas: null,
        $overlay: null,
        $status: null,

        module: null,
        running: false,
        layerAdded: false,

        init: function (container, settings) {
            this.setSettings(settings, DoomHost.defaults);

            this.$container = $(container);
            this.$canvas = this.$container.find('.doom-canvas');
            this.$overlay = this.$container.find('.doom-overlay');
            this.$status = this.$container.find('.doom-status');

            // A canvas with a border or padding reports the wrong mouse
            // coordinates to SDL. The stylesheet keeps it bare; this is the
            // reminder for anyone tempted to restyle it.
            this.addListener(this.$overlay.find('.doom-start'), 'click', 'start');

            this.addListener(this.$canvas, 'contextmenu', function (ev) {
                ev.preventDefault();
            });
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
            this.$overlay.addClass('doom-overlay--busy');

            var self = this;

            this.setStatus(Craft.t('doom', 'Loading WAD…'));

            this.fetchBuffer(this.settings.wadUrl)
                .then(function (wad) {
                    self.setStatus(Craft.t('doom', 'Loading engine…'));
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
            var canvas = this.$canvas[0];

            canvas.addEventListener('webglcontextlost', function (ev) {
                ev.preventDefault();
                self.fail(new Error(Craft.t('doom', 'The WebGL context was lost. Reload the page to play again.')));
            }, false);

            var module = {
                canvas: canvas,
                noInitialRun: true,
                arguments: [],

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
                    self.mountPersistence(module);
                    module.FS.writeFile(WAD_PATH, assets.wad);
                }],

                onRuntimeInitialized: function () {
                    self.onReady(module);
                },

                onAbort: function (reason) {
                    self.fail(new Error(String(reason)));
                },

                print: function (text) {
                    console.log(text);
                },

                printErr: function (text) {
                    console.error(text);
                },

                setStatus: function () {
                    // Upstream's index.html routes progress here; the overlay
                    // covers it, so this only exists to keep the glue quiet.
                },

                monitorRunDependencies: function () {
                },
            };

            this.module = window.Module = module;

            return this.loadScript(this.settings.engineUrl);
        },

        /**
         * Mounts IndexedDB at PERSIST_DIR so savegames and default.cfg survive.
         *
         * IDBFS is only present if the engine was linked with -lidbfs.js.
         * bin/build-engine.sh adds that flag; a hand-dropped upstream build
         * won't have it, so this degrades to a warning rather than an abort.
         */
        mountPersistence: function (module) {
            var FS = module.FS;

            if (!FS.filesystems || !FS.filesystems.IDBFS) {
                console.warn('[doom] Engine built without IDBFS. Saves will not persist across page loads.');
                FS.mkdir(PERSIST_DIR);
                return;
            }

            module.addRunDependency('doom-persist');

            FS.mkdir(PERSIST_DIR);
            FS.mount(FS.filesystems.IDBFS, {}, PERSIST_DIR);

            // true = populate the in-memory filesystem from IndexedDB.
            FS.syncfs(true, function (error) {
                if (error) {
                    console.warn('[doom] Could not restore saved games:', error);
                }

                module.removeRunDependency('doom-persist');
            });
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

        onReady: function (module) {
            this.$overlay.addClass('doom-overlay--hidden');
            this.captureInput();

            var callMain = module.callMain || window.callMain;

            if (typeof callMain !== 'function') {
                this.fail(new Error('The engine exposes no callMain(). Rebuild with callMain in EXPORTED_RUNTIME_METHODS.'));
                return;
            }

            callMain(this.gameArgs());

            this.$canvas.trigger('focus');
        },

        /**
         * -nogui and -nomusic match upstream's own launcher: the setup GUI has
         * nowhere to run, and music needs a soundfont we don't ship.
         */
        gameArgs: function () {
            return [
                '-iwad', WAD_PATH,
                '-savedir', PERSIST_DIR + '/savegames',
                '-config', PERSIST_DIR + '/default.cfg',
                '-window',
                '-nogui',
                '-nomusic',
            ];
        },

        /**
         * Keeps Craft's keyboard shortcuts out of the game.
         *
         * Garnish scopes shortcuts to the topmost UI layer, the same mechanism
         * modals use, so pushing a layer suppresses the CP's own bindings
         * without touching a single listener. The capture-phase handler that
         * follows only calls preventDefault, never stopPropagation: SDL2
         * listens on window in the bubble phase, so anything that halts
         * propagation disarms the game along with Craft.
         */
        captureInput: function () {
            if (Garnish.uiLayerManager && typeof Garnish.uiLayerManager.addLayer === 'function') {
                Garnish.uiLayerManager.addLayer(this.$container);
                this.layerAdded = true;
            }

            this.keyHandler = this.onKey.bind(this);
            window.addEventListener('keydown', this.keyHandler, true);

            if (this.settings.pointerLock) {
                this.addListener(this.$canvas, 'click', 'requestPointerLock');
            }
        },

        releaseInput: function () {
            if (this.keyHandler) {
                window.removeEventListener('keydown', this.keyHandler, true);
                this.keyHandler = null;
            }

            if (this.layerAdded) {
                Garnish.uiLayerManager.removeLayer();
                this.layerAdded = false;
            }
        },

        onKey: function (ev) {
            if (ev.metaKey || ev.altKey) {
                // Leave the browser's own chords alone. Cmd-W is not a weapon
                // switch and pretending otherwise only annoys people.
                return;
            }

            if (SWALLOWED_KEYS.indexOf(ev.code) !== -1) {
                ev.preventDefault();
            }
        },

        requestPointerLock: function () {
            var canvas = this.$canvas[0];

            if (canvas.requestPointerLock) {
                // Chrome returns a promise here and rejects if the document
                // isn't focused; nothing to do about it but not throw.
                var result = canvas.requestPointerLock();

                if (result && typeof result.catch === 'function') {
                    result.catch(function () {
                    });
                }
            }
        },

        setStatus: function (message) {
            this.$status.text(message);
        },

        fail: function (error) {
            console.error('[doom]', error);
            this.running = false;
            this.releaseInput();
            this.$overlay
                .removeClass('doom-overlay--busy doom-overlay--hidden')
                .addClass('doom-overlay--error');
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
            wadUrl: null,
            pointerLock: true,
        },
    });

    Craft.Doom = {
        DoomHost: DoomHost,

        boot: function (container, settings) {
            return new DoomHost(container, settings);
        },
    };
})(jQuery);

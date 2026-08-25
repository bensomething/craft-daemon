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

    /* Where the admin's IWAD is written before the engine starts. */
    var WAD_PATH = '/iwad.wad';

    var DaemonHost = Garnish.Base.extend({
        $container: null,
        $canvas: null,
        $overlay: null,
        $status: null,

        module: null,
        running: false,
        layerAdded: false,
        keyHandler: null,

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
            this.$overlay.addClass('daemon-overlay--busy');

            var self = this;

            this.setStatus(Craft.t('daemon', 'Loading WAD…'));

            this.fetchBuffer(this.settings.wadUrl)
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
                arguments: ['-iwad', WAD_PATH],

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
                    module.FS.writeFile(WAD_PATH, assets.wad);
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
        },

        releaseInput: function () {
            if (this.keyHandler) {
                window.removeEventListener('keydown', this.keyHandler, true);
                this.keyHandler = null;
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
            if (ev.metaKey || ev.altKey) {
                // Leave the browser's own chords alone. Cmd-W is not a weapon
                // switch and pretending otherwise only annoys people.
                return;
            }

            if (SWALLOWED_KEYS.indexOf(ev.code) !== -1) {
                ev.preventDefault();
            }
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
            wadUrl: null,
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

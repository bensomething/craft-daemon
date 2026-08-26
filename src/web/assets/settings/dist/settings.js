/*
 * The WAD download buttons on the settings screen.
 *
 * The request doing the work can't report its own progress, so it lives in two:
 * the POST downloads and writes percentages to the cache, and daemon/wad/progress
 * is polled alongside to read them back.
 */
(function ($) {
    'use strict';

    var POLL_INTERVAL = 500;

    var DaemonWadDownloader = Garnish.Base.extend({
        $container: null,
        $buttons: null,
        $status: null,
        $progress: null,

        $list: null,
        $pressed: null,

        progressBar: null,
        pollTimeout: null,
        running: false,
        action: null,

        init: function (container) {
            this.$container = $(container);
            this.$buttons = this.$container.find('.daemon-fetch-btn');
            this.$status = this.$container.find('.daemon-fetch-status');
            this.$progress = this.$container.find('.daemon-fetch-progress');

            // Outside the fetch container, which must survive the swap.
            this.$list = this.$container.closest('form').find('.daemon-wad-list');

            this.progressBar = new Craft.ProgressBar(this.$progress);

            this.addListener(this.$buttons, 'click', 'fetch');
        },

        /**
         * Every button is disabled, not just the one pressed: the downloads
         * share one progress key and would overwrite each other.
         */
        fetch: function (ev) {
            if (this.running) {
                return;
            }

            var action = $(ev.currentTarget).data('daemon-action');

            if (!action) {
                return;
            }

            this.running = true;
            this.action = action;
            this.$pressed = $(ev.currentTarget);
            this.$container.addClass('daemon-fetch--busy');
            this.$buttons.addClass('disabled').attr('aria-disabled', 'true');
            this.setStatus(Craft.t('daemon', 'Starting download…'));

            this.progressBar.setProgressPercentage(0);
            this.progressBar.showProgressBar();

            this.poll();

            var self = this;

            Craft.sendActionRequest('POST', action)
                .then(function (response) {
                    self.succeed(response.data);
                })
                .catch(function (error) {
                    // Craft puts the failure message on the response body.
                    var data = (error && error.response && error.response.data) || {};
                    self.fail(data.message || (error && error.message));
                });
        },

        /**
         * Rescheduled from the response rather than on a fixed interval, so a
         * slow reply can't stack requests behind itself.
         */
        poll: function () {
            var self = this;

            Craft.sendActionRequest('GET', 'daemon/wad/progress')
                .then(function (response) {
                    var data = response.data || {};

                    if (data.status === 'downloading' && data.total > 0) {
                        var percent = Math.round((data.downloaded / data.total) * 100);
                        self.progressBar.setProgressPercentage(percent);
                        self.setStatus(Craft.t('daemon', 'Downloading: {percent}%', {percent: percent}));
                    }
                })
                .catch(function () {
                    // A dropped poll is not a failed download.
                })
                .then(function () {
                    if (self.running) {
                        self.pollTimeout = setTimeout($.proxy(self, 'poll'), POLL_INTERVAL);
                    }
                });
        },

        /**
         * Shows the new WAD without reloading. The page carries
         * data-confirm-unload, so a reload mid-edit would either discard names
         * being typed or stop to ask about them.
         */
        succeed: function (data) {
            this.stop();
            this.progressBar.setProgressPercentage(100);
            this.setStatus(this.installedMessage());
            Craft.cp.displayNotice(data.message || Craft.t('daemon', 'Installed.'));

            // Nothing left to download, so it offers the download again.
            if (this.$pressed && this.$pressed.data('daemon-again-label')) {
                this.$pressed.text(this.$pressed.data('daemon-again-label'));
            }

            var self = this;

            if (!this.$list.length) {
                // The Utilities screen has the buttons but no list to update.
                this.release();

                return;
            }

            Craft.sendActionRequest('GET', 'daemon/wad/list')
                .then(function (response) {
                    self.replaceList(response.data.html);
                })
                .catch(function () {
                    // The download worked; only the list is stale.
                    self.setStatus(self.installedMessage(true));
                })
                .then(function () {
                    self.release();
                });
        },

        /**
         * Names the game that was installed, from the button that asked for it.
         */
        installedMessage: function (stale) {
            var game = this.$pressed && this.$pressed.data('daemon-game');

            if (!game) {
                return Craft.t('daemon', 'Installed.');
            }

            return stale
                ? Craft.t('daemon', '{game} installed. Reload to see it listed.', {game: game})
                : Craft.t('daemon', '{game} installed.', {game: game});
        },

        /**
         * Swaps in the re-rendered list. The markup holds what was last saved,
         * so current values are carried across and a half finished rename does
         * not disappear because somebody pressed Download.
         */
        replaceList: function (html) {
            if (!this.$list.length) {
                return;
            }

            var typed = {};

            this.$list.find('textarea[name]').each(function () {
                typed[this.name] = this.value;
            });

            var $form = this.$container.closest('form');
            var clean = this.isFormClean($form);

            this.$list.html(html);

            this.$list.find('textarea[name]').each(function () {
                if (typed[this.name] !== undefined) {
                    this.value = typed[this.name];
                }
            });

            // A WAD appearing is the server's news, not an edit. Left alone,
            // the new rows would read as unsaved changes.
            if (clean) {
                this.markFormClean($form);
            }
        },

        /**
         * Craft compares the form against the serialization it took on load,
         * through a serializer the form may have replaced.
         */
        isFormClean: function ($form) {
            if (!$form.length || !$form.data('initialSerializedValue')) {
                return false;
            }

            return $form.data('initialSerializedValue') === this.serializeForm($form);
        },

        markFormClean: function ($form) {
            $form.data('initialSerializedValue', this.serializeForm($form));
        },

        serializeForm: function ($form) {
            var serializer = $form.data('serializer');

            return typeof serializer === 'function' ? serializer() : $form.serialize();
        },

        /** Hands the buttons back, however the download ended. */
        release: function () {
            this.progressBar.hideProgressBar();
            this.$container.removeClass('daemon-fetch--busy');
            this.$buttons.removeClass('disabled').removeAttr('aria-disabled');
        },

        fail: function (message) {
            this.stop();
            this.release();
            this.setStatus(message || Craft.t('daemon', 'The download failed.'), true);
            Craft.cp.displayError(message || Craft.t('daemon', 'The download failed.'));
        },

        stop: function () {
            this.running = false;

            if (this.pollTimeout) {
                clearTimeout(this.pollTimeout);
                this.pollTimeout = null;
            }
        },

        setStatus: function (message, isError) {
            this.$status.text(message).toggleClass('error', !!isError);
        },

        destroy: function () {
            this.stop();
            this.base();
        },
    });

    Craft.DaemonWadDownloader = DaemonWadDownloader;
})(jQuery);

/*
 * The Freedoom download button on the settings screen.
 *
 * The request that does the downloading can't report its own progress, so the
 * work and the progress live in two requests: POST daemon/wad/fetch does the
 * download and writes percentages to the cache, and daemon/wad/progress is polled
 * alongside it to read them back.
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

            // Outside the fetch container, so it is found from the form
            // rather than from within: the progress bar and status line live
            // in here and must survive a swap of the list.
            this.$list = this.$container.closest('form').find('.daemon-wad-list');

            this.progressBar = new Craft.ProgressBar(this.$progress);

            this.addListener(this.$buttons, 'click', 'fetch');
        },

        /**
         * Starts whichever download was asked for. Every button is disabled
         * for the duration, not just the one pressed: the two downloads share
         * one progress key, so running both at once would have them overwrite
         * each other's percentages.
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
                    // Craft puts the controller's failure message on the
                    // response body; the bare Error message is just the status.
                    var data = (error && error.response && error.response.data) || {};
                    self.fail(data.message || (error && error.message));
                });
        },

        /**
         * Polls until the fetch request settles. Rescheduled from the response
         * rather than on a fixed interval, so a slow reply can't stack up
         * requests behind itself.
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
                    // A dropped poll is not a failed download. The fetch
                    // request is the one that decides the outcome.
                })
                .then(function () {
                    if (self.running) {
                        self.pollTimeout = setTimeout($.proxy(self, 'poll'), POLL_INTERVAL);
                    }
                });
        },

        /**
         * Shows the new WAD without reloading.
         *
         * The page is a full page form carrying data-confirm-unload, so a
         * reload part way through editing either discards the names the admin
         * has typed or stops to ask them about it. Neither is a reasonable
         * answer to pressing Download, so the list is re-rendered by the
         * server and swapped in instead.
         */
        succeed: function (data) {
            this.stop();
            this.progressBar.setProgressPercentage(100);
            this.setStatus(Craft.t('daemon', 'Installed.'));
            Craft.cp.displayNotice(data.message || Craft.t('daemon', 'Installed.'));

            // The button offered a download and there is now nothing left to
            // download, so it offers the download again instead.
            if (this.$pressed && this.$pressed.data('daemon-again-label')) {
                this.$pressed.text(this.$pressed.data('daemon-again-label'));
            }

            var self = this;

            Craft.sendActionRequest('GET', 'daemon/wad/list')
                .then(function (response) {
                    self.replaceList(response.data.html);
                })
                .catch(function () {
                    // The download itself worked, which is what the notice
                    // said. Only the list on screen is stale, and saying so is
                    // better than undoing a notice that was true.
                    self.setStatus(Craft.t('daemon', 'Installed. Reload to see it listed.'));
                })
                .then(function () {
                    self.release();
                });
        },

        /**
         * Swaps in the re-rendered list, keeping anything typed but not saved.
         *
         * The markup comes back as the server would have rendered it, which
         * means the name fields hold what was last saved rather than what is
         * on screen. Carrying the current values across keeps a half finished
         * rename from disappearing because somebody pressed Download.
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

            // A form that had no unsaved changes before the swap still has
            // none: a WAD appearing is the server's news, not the admin's
            // edit. Left alone, the new rows would read as changes and the
            // page would ask about them on the way out.
            if (clean) {
                this.markFormClean($form);
            }
        },

        /**
         * Craft decides whether to warn on leaving by comparing the form
         * against the serialization it took on load, through a serializer the
         * form may have replaced. Both halves of that are read here rather
         * than assumed.
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

        /**
         * Hands the buttons back and clears the bar, however the download
         * ended.
         */
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

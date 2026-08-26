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

        progressBar: null,
        pollTimeout: null,
        running: false,
        action: null,

        init: function (container) {
            this.$container = $(container);
            this.$buttons = this.$container.find('.daemon-fetch-btn');
            this.$status = this.$container.find('.daemon-fetch-status');
            this.$progress = this.$container.find('.daemon-fetch-progress');

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

        succeed: function (data) {
            this.stop();
            this.progressBar.setProgressPercentage(100);
            this.setStatus(Craft.t('daemon', 'Installed. Reloading…'));
            Craft.cp.displayNotice(data.message || Craft.t('daemon', 'Installed.'));

            // The WAD list and the "no WADs" message are both rendered server
            // side, so a reload is the honest way to show the new state.
            setTimeout(function () {
                window.location.reload();
            }, 600);
        },

        fail: function (message) {
            this.stop();
            this.progressBar.hideProgressBar();
            this.$container.removeClass('daemon-fetch--busy');
            this.$buttons.removeClass('disabled').removeAttr('aria-disabled');
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

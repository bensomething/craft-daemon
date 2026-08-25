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
        $button: null,
        $status: null,
        $progress: null,

        progressBar: null,
        pollTimeout: null,
        running: false,

        init: function (container) {
            this.$container = $(container);
            this.$button = this.$container.find('.daemon-fetch-btn');
            this.$status = this.$container.find('.daemon-fetch-status');
            this.$progress = this.$container.find('.daemon-fetch-progress');

            this.progressBar = new Craft.ProgressBar(this.$progress);

            this.addListener(this.$button, 'click', 'fetch');
        },

        fetch: function () {
            if (this.running) {
                return;
            }

            this.running = true;
            this.$button.addClass('disabled').attr('aria-disabled', 'true');
            this.setStatus(Craft.t('daemon', 'Starting download…'));

            this.progressBar.setProgressPercentage(0);
            this.progressBar.showProgressBar();

            this.poll();

            var self = this;

            Craft.sendActionRequest('POST', 'daemon/wad/fetch')
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
                        self.setStatus(Craft.t('daemon', 'Downloading Freedoom: {percent}%', {percent: percent}));
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
            this.setStatus(Craft.t('daemon', 'Freedoom installed. Reloading…'));
            Craft.cp.displayNotice(Craft.t('daemon', 'Freedoom installed.'));

            // The WAD list and the "no WADs" message are both rendered server
            // side, so a reload is the honest way to show the new state.
            setTimeout(function () {
                window.location.reload();
            }, 600);
        },

        fail: function (message) {
            this.stop();
            this.progressBar.hideProgressBar();
            this.$button.removeClass('disabled').removeAttr('aria-disabled');
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

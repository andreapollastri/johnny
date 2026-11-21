/* Johnny panel — Transfers: the connection form follows the provider, and running transfers
   are followed live (list and detail). Needs app.js. */
(function () {
    'use strict';

    var doc = document;
    var Johnny = window.Johnny;
    if (!Johnny) return;

    // ===== Connection form: placeholders and hints per provider =================
    doc.querySelectorAll('[data-provider-form]').forEach(function (form) {
        var providers = {};
        try {
            providers = JSON.parse(form.getAttribute('data-provider-form')) || {};
        } catch (e) {
            return;
        }

        var select = form.querySelector('select[name="provider"]');
        var endpoint = form.querySelector('input[name="endpoint"]');
        var region = form.querySelector('input[name="region"]');
        var pathStyle = form.querySelector('input[name="path_style"]');
        var note = form.querySelector('[data-provider-note]');
        var endpointHint = form.querySelector('[data-endpoint-hint]');
        var touchedPathStyle = false;

        if (pathStyle) pathStyle.addEventListener('change', function () { touchedPathStyle = true; });

        function apply(initial) {
            var provider = providers[select.value];
            if (!provider) return;
            endpoint.placeholder = provider.endpoint || 'Not needed';
            endpoint.required = !!provider.needs_endpoint;
            region.placeholder = provider.region || provider.default_region || '';
            if (endpointHint) {
                endpointHint.textContent = provider.needs_endpoint
                    ? 'The S3 URL of the provider, without the bucket name.'
                    : 'Leave empty: AWS picks the endpoint from the region.';
            }
            if (note) note.textContent = provider.note || '';
            if (pathStyle && !touchedPathStyle && !initial) pathStyle.checked = !!provider.path_style;
        }

        select.addEventListener('change', function () { apply(false); });
        apply(true);
    });

    // ===== New transfer form ======================================================
    var transferForm = doc.querySelector('[data-transfer-form]');
    if (transferForm) {
        var showFor = function () {
            var direction = transferForm.querySelector('input[name="direction"]:checked');
            var mode = transferForm.querySelector('input[name="mode"]:checked');
            transferForm.querySelectorAll('[data-when-direction]').forEach(function (node) {
                node.hidden = !direction || node.getAttribute('data-when-direction') !== direction.value;
            });
            transferForm.querySelectorAll('[data-when-mode]').forEach(function (node) {
                node.hidden = !mode || node.getAttribute('data-when-mode') !== mode.value;
            });
        };
        transferForm.addEventListener('change', function (event) {
            // The buckets on offer belong to the connection: load the form again for another one.
            if (event.target.name === 'connection_id') {
                var direction = transferForm.querySelector('input[name="direction"]:checked');
                var url = transferForm.getAttribute('data-form-url');
                window.location.assign(url + (url.indexOf('?') === -1 ? '?' : '&')
                    + 'connection=' + encodeURIComponent(event.target.value)
                    + '&direction=' + encodeURIComponent(direction ? direction.value : 'import'));

                return;
            }
            showFor();
        });
        showFor();
    }

    // ===== "Select all" on the list of remote buckets ============================
    doc.addEventListener('change', function (event) {
        var all = event.target.closest('[data-check-all]');
        if (all) {
            doc.querySelectorAll(all.getAttribute('data-check-all')).forEach(function (box) { box.checked = all.checked; });
        }
        var counter = doc.querySelector('[data-checked-count]');
        if (counter) {
            var boxes = doc.querySelectorAll(counter.getAttribute('data-checked-count'));
            var checked = Array.prototype.filter.call(boxes, function (box) { return box.checked; }).length;
            counter.textContent = checked === 0 ? 'Import selected' : 'Import ' + checked + (checked === 1 ? ' bucket' : ' buckets');
            counter.disabled = checked === 0;
        }
    });

    // ===== Live progress ==========================================================
    function paint(row, status) {
        var set = function (name, text) {
            var node = row.querySelector('[data-t="' + name + '"]');
            if (node && text !== undefined) node.textContent = text === null ? '' : text;

            return node;
        };
        set('label', status.label);
        set('progress', status.progress);
        set('message', status.message);
        set('speed', status.speed || '—');
        set('eta', status.eta || '—');
        set('errors', String(status.errors));
        set('percent', status.percent === null ? '—' : status.percent + '%');

        var meta = [];
        if (status.speed) meta.push(status.speed);
        if (status.eta) meta.push(status.eta + ' left');
        set('meta', meta.join(', '));

        var bar = row.querySelector('[data-t="bar"]');
        var fill = row.querySelector('[data-t="fill"]');
        if (bar && fill) {
            bar.classList.toggle('is-indeterminate', status.active && status.percent === null);
            fill.style.width = status.percent === null ? '' : status.percent + '%';
        }
    }

    // onData returns false to stop, or how long to wait before asking again (default 3 s).
    function follow(url, onData) {
        (function poll() {
            var wait = 3000;
            Johnny.request(url).then(function (data) {
                var next = onData(data);
                wait = next === false ? 0 : (typeof next === 'number' ? next : 3000);
            }).catch(function () { /* the server is busy or restarting: try again */ }).then(function () {
                if (wait) setTimeout(poll, wait);
            });
        })();
    }

    var list = doc.querySelector('[data-transfers-live]');
    if (list && list.getAttribute('data-active') === '1') {
        var activeIds = null;
        follow(list.getAttribute('data-transfers-live'), function (data) {
            var ids = [];
            (data.transfers || []).forEach(function (status) {
                if (status.active) ids.push(status.id);
                var row = list.querySelector('[data-transfer="' + status.id + '"]');
                if (row) paint(row, status);
            });
            var key = ids.join(',');
            // A transfer started or finished: load the page again for its new actions and badges.
            if (activeIds !== null && key !== activeIds) {
                window.location.assign(window.location.href);

                return false;
            }
            activeIds = key;

            // Nothing is moving: only scheduled syncs can start something, so ask less often.
            return data.active ? true : 15000;
        });
    }

    var detail = doc.querySelector('[data-transfer-live]');
    if (detail && detail.getAttribute('data-active') === '1') {
        var log = detail.querySelector('[data-t="log"]');
        follow(detail.getAttribute('data-transfer-live'), function (status) {
            paint(detail, status);
            if (log && status.log) {
                var atBottom = log.scrollTop + log.clientHeight >= log.scrollHeight - 30;
                log.textContent = status.log.length ? status.log.join('\n') : 'Waiting for rclone…';
                if (atBottom) log.scrollTop = log.scrollHeight;
            }
            if (!status.active) {
                window.location.assign(window.location.href);

                return false;
            }

            return true;
        });
    }
})();

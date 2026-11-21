/* Johnny panel — behaviour shared by every page. No dependencies, no build step. */
(function () {
    'use strict';

    var doc = document;
    var root = doc.documentElement;
    var csrfMeta = doc.querySelector('meta[name="csrf-token"]');

    var Johnny = window.Johnny = {
        csrf: csrfMeta ? csrfMeta.getAttribute('content') : '',

        /** fetch() for the panel's own JSON endpoints: same-origin, CSRF token, JSON in and out. */
        request: function (url, options) {
            options = options || {};
            var headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': Johnny.csrf, 'X-Requested-With': 'XMLHttpRequest' };
            if (options.json !== undefined) {
                headers['Content-Type'] = 'application/json';
            }

            return fetch(url, {
                method: options.method || (options.json !== undefined ? 'POST' : 'GET'),
                headers: headers,
                credentials: 'same-origin',
                cache: 'no-store',
                body: options.json !== undefined ? JSON.stringify(options.json) : undefined
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    if (!response.ok) {
                        var error = new Error(Johnny.errorMessage(response.status, data));
                        error.status = response.status;
                        throw error;
                    }

                    return data;
                });
            });
        },

        errorMessage: function (status, data) {
            if (status === 419) return 'Your session expired. Reload the page and sign in again.';
            if (status === 413) return 'The server refused the request: it is too large.';
            if (data && data.errors) {
                var first = Object.keys(data.errors)[0];
                if (first && data.errors[first][0]) return data.errors[first][0];
            }

            return (data && data.message) || ('The server answered ' + status + '.');
        },

        toast: function (message, isError) {
            var box = doc.getElementById('toasts');
            if (!box) return;
            var toast = doc.createElement('div');
            toast.className = 'toast' + (isError ? ' is-error' : '');
            toast.setAttribute('role', isError ? 'alert' : 'status');
            toast.textContent = message;
            box.appendChild(toast);
            setTimeout(function () { toast.remove(); }, isError ? 8000 : 4000);
        },

        copy: function (text, button) {
            if (!navigator.clipboard) {
                Johnny.toast('Copying is not available here: select the text instead.', true);

                return;
            }
            navigator.clipboard.writeText(text).then(function () {
                if (!button) return;
                var label = button.textContent;
                button.textContent = 'Copied';
                button.classList.add('copied');
                setTimeout(function () { button.textContent = label; button.classList.remove('copied'); }, 1600);
            });
        }
    };

    // --- Theme ---------------------------------------------------------------
    doc.addEventListener('click', function (event) {
        if (!event.target.closest('[data-theme-toggle]')) return;
        var current = root.getAttribute('data-theme');
        var next = current === 'dark' ? 'light'
            : current === 'light' ? 'dark'
                : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'light' : 'dark');
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('theme', next); } catch (e) { /* private mode */ }
    });

    // --- Navigation on small screens: the pages drop under the bar ------------
    var app = doc.getElementById('app');
    var navScrim = doc.getElementById('nav-scrim');
    var navToggle = doc.querySelector('[data-nav-toggle]');
    function setNav(open) {
        if (!app) return;
        app.classList.toggle('is-nav-open', open);
        if (navScrim) navScrim.hidden = !open;
        if (navToggle) navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    doc.addEventListener('click', function (event) {
        if (event.target.closest('[data-nav-toggle]')) setNav(!app.classList.contains('is-nav-open'));
        else if (event.target === navScrim) setNav(false);

        // The account menu is a <details>: close it when the click lands elsewhere.
        doc.querySelectorAll('details.menu[open]').forEach(function (menu) {
            if (!menu.contains(event.target)) menu.removeAttribute('open');
        });
    });
    doc.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        setNav(false);
        doc.querySelectorAll('details.menu[open]').forEach(function (menu) { menu.removeAttribute('open'); });
    });

    // --- Copy buttons: <button data-copy="text"> or data-copy-from="#id" -------
    doc.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy], [data-copy-from]');
        if (!button) return;
        var text = button.getAttribute('data-copy');
        if (text === null) {
            var source = doc.querySelector(button.getAttribute('data-copy-from'));
            text = !source ? '' : (/^(INPUT|TEXTAREA)$/.test(source.tagName) ? source.value : source.textContent.trim());
        }
        Johnny.copy(text, button);
    });

    // --- Dialogs: data-dialog-open="#id", data-dialog-close --------------------
    doc.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-dialog-open]');
        if (opener) {
            var dialog = doc.querySelector(opener.getAttribute('data-dialog-open'));
            if (dialog && dialog.showModal) dialog.showModal();

            return;
        }
        var closer = event.target.closest('[data-dialog-close]');
        if (closer) {
            var parent = closer.closest('dialog');
            if (parent) parent.close();
        }
    });
    // A click on the backdrop closes a dialog.
    doc.addEventListener('mousedown', function (event) {
        if (event.target.tagName === 'DIALOG' && event.target.open) {
            var box = event.target.getBoundingClientRect();
            var inside = event.clientX >= box.left && event.clientX <= box.right && event.clientY >= box.top && event.clientY <= box.bottom;
            if (!inside) event.target.close();
        }
    });

    // --- Confirmation: <form data-confirm="message"> ---------------------------
    // The message is read from the DOM, never interpolated into JavaScript.
    var confirmDialog = doc.getElementById('confirm-dialog');
    var pendingForm = null;
    var pendingSubmitter = null;

    doc.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute && form.getAttribute('data-confirm');
        if (!message) return;
        if (form.dataset.confirmed === '1') {
            delete form.dataset.confirmed;

            return;
        }
        if (!confirmDialog || !confirmDialog.showModal) {
            if (!window.confirm(message)) event.preventDefault();

            return;
        }

        event.preventDefault();
        pendingForm = form;
        pendingSubmitter = event.submitter || null;
        var label = form.getAttribute('data-confirm-label') || (pendingSubmitter && pendingSubmitter.textContent.trim()) || 'Confirm';
        var ok = confirmDialog.querySelector('[data-confirm-ok]');
        var danger = form.hasAttribute('data-confirm-danger') || (pendingSubmitter && pendingSubmitter.classList.contains('btn-danger'));
        confirmDialog.querySelector('[data-confirm-title]').textContent = form.getAttribute('data-confirm-title') || label;
        confirmDialog.querySelector('[data-confirm-message]').textContent = message;
        ok.textContent = label;
        ok.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
        confirmDialog.showModal();
        confirmDialog.querySelector('[data-dialog-close]').focus();
    }, true);

    if (confirmDialog) {
        confirmDialog.querySelector('[data-confirm-ok]').addEventListener('click', function () {
            var form = pendingForm;
            var submitter = pendingSubmitter;
            pendingForm = pendingSubmitter = null;
            confirmDialog.close();
            if (!form) return;
            form.dataset.confirmed = '1';
            if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
            else form.submit();
        });
    }

    // --- Filter a table as you type: <input data-filter-table="#table"> --------
    doc.querySelectorAll('[data-filter-table]').forEach(function (input) {
        var rows = doc.querySelectorAll(input.getAttribute('data-filter-table') + ' tbody tr');
        input.addEventListener('input', function () {
            var query = input.value.trim().toLowerCase();
            rows.forEach(function (row) {
                var value = (row.getAttribute('data-filter-value') || '').toLowerCase();
                row.hidden = query !== '' && value.indexOf(query) === -1;
            });
        });
    });

    // --- Snippet tabs -----------------------------------------------------------
    doc.addEventListener('click', function (event) {
        var tab = event.target.closest('.snippet-tab');
        if (!tab) return;
        var snippet = tab.closest('.snippet');
        snippet.querySelectorAll('.snippet-tab').forEach(function (other) {
            other.setAttribute('aria-selected', other === tab ? 'true' : 'false');
        });
        snippet.querySelectorAll('.snippet-pane').forEach(function (pane) {
            pane.hidden = pane.id !== tab.getAttribute('aria-controls');
        });
    });

    // --- Reveal: <button data-toggle="#id"> shows or hides an element ----------
    doc.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-toggle]');
        if (!toggle) return;
        var target = doc.querySelector(toggle.getAttribute('data-toggle'));
        if (!target) return;
        target.hidden = !target.hidden;
        toggle.setAttribute('aria-expanded', target.hidden ? 'false' : 'true');
        if (!target.hidden) {
            var field = target.querySelector('input:not([type="hidden"]), select, textarea');
            if (field) field.focus();
        }
    });

    // --- Conditional fields: <div data-show-if="access=all,buckets"> --------------
    // Shown while the field of that name, in the same form, has one of the values.
    // Hidden parts are disabled too, so they are neither validated nor submitted.
    function fieldValue(form, name) {
        var checked = form.querySelector('[name="' + name + '"]:checked');
        if (checked) return checked.value;
        var field = form.querySelector('select[name="' + name + '"], input[name="' + name + '"]:not([type="radio"]):not([type="checkbox"])');
        return field ? field.value : '';
    }

    function applyConditions(form) {
        var nodes = form.querySelectorAll('[data-show-if]');
        nodes.forEach(function (node) {
            var rule = node.getAttribute('data-show-if').split('=');
            node.hidden = (rule[1] || '').split(',').indexOf(fieldValue(form, rule[0])) === -1;
        });
        // Conditions nest: a field is off while any part around it is hidden.
        nodes.forEach(function (node) {
            node.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.disabled = !!field.closest('[data-show-if][hidden]');
            });
        });
    }

    doc.querySelectorAll('form').forEach(function (form) {
        if (form.querySelector('[data-show-if]')) applyConditions(form);
    });
    doc.addEventListener('change', function (event) {
        var form = event.target.closest('form');
        if (form && form.querySelector('[data-show-if]')) applyConditions(form);
    });

    // --- Command palette (Ctrl/⌘ K) ---------------------------------------------
    var palette = doc.getElementById('palette');
    if (palette && palette.showModal) {
        var input = palette.querySelector('.palette-input');
        var list = palette.querySelector('.palette-list');
        var items = [];
        var shown = [];
        var current = 0;
        var bucketsLoaded = false;

        try {
            items = JSON.parse(doc.getElementById('palette-data').textContent) || [];
        } catch (e) {
            items = [];
        }

        var bucketUrl = palette.getAttribute('data-bucket-url');

        function loadBuckets() {
            if (bucketsLoaded) return;
            bucketsLoaded = true;
            Johnny.request(palette.getAttribute('data-buckets-url')).then(function (data) {
                (data.buckets || []).forEach(function (name) {
                    items.push({ label: name, kind: 'Bucket', url: bucketUrl.replace('__bucket__', encodeURIComponent(name)) });
                });
                render();
            }).catch(function () { /* the palette still lists the pages */ });
        }

        function render() {
            var query = input.value.trim().toLowerCase();
            shown = items.filter(function (item) {
                return query === '' ? item.kind !== 'Bucket' : item.label.toLowerCase().indexOf(query) !== -1;
            });
            shown.sort(function (a, b) {
                return (a.label.toLowerCase().indexOf(query) === 0 ? 0 : 1) - (b.label.toLowerCase().indexOf(query) === 0 ? 0 : 1);
            });
            shown = shown.slice(0, 40);
            current = Math.min(current, Math.max(0, shown.length - 1));

            list.textContent = '';
            if (shown.length === 0) {
                var empty = doc.createElement('li');
                empty.className = 'palette-empty';
                empty.textContent = 'Nothing matches “' + input.value.trim() + '”.';
                list.appendChild(empty);

                return;
            }
            shown.forEach(function (item, index) {
                var row = doc.createElement('li');
                row.className = 'palette-item' + (index === current ? ' is-current' : '');
                row.setAttribute('role', 'option');
                row.setAttribute('aria-selected', index === current ? 'true' : 'false');
                var label = doc.createElement('span');
                label.className = 'label';
                label.textContent = item.label;
                var kind = doc.createElement('span');
                kind.className = 'kind';
                kind.textContent = item.kind;
                row.appendChild(label);
                row.appendChild(kind);
                row.addEventListener('click', function () { go(item); });
                row.addEventListener('mousemove', function () {
                    if (current !== index) { current = index; render(); }
                });
                list.appendChild(row);
            });
            var active = list.querySelector('.is-current');
            if (active && active.scrollIntoView) active.scrollIntoView({ block: 'nearest' });
        }

        function go(item) {
            if (!item) return;
            palette.close();
            if (item.action === 'theme') {
                doc.querySelector('[data-theme-toggle]').click();
            } else if (item.url) {
                window.location.assign(item.url);
            }
        }

        function open() {
            if (palette.open) return;
            input.value = '';
            current = 0;
            render();
            palette.showModal();
            input.focus();
            loadBuckets();
        }

        input.addEventListener('input', function () { current = 0; render(); });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') { current = Math.min(current + 1, shown.length - 1); render(); event.preventDefault(); }
            else if (event.key === 'ArrowUp') { current = Math.max(current - 1, 0); render(); event.preventDefault(); }
            else if (event.key === 'Enter') { go(shown[current]); event.preventDefault(); }
        });

        doc.addEventListener('click', function (event) {
            if (event.target.closest('[data-palette-open]')) open();
        });
        doc.addEventListener('keydown', function (event) {
            var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(event.target.tagName) || event.target.isContentEditable;
            if ((event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                open();
            } else if (event.key === '/' && !typing && !doc.querySelector('dialog[open]')) {
                event.preventDefault();
                open();
            }
        });
    }
})();

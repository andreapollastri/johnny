/* Johnny panel — the file browser of a bucket: chunked uploads (drag and drop), selection,
   the details panel with its preview, share links, rename/move. Needs app.js. */
(function () {
    'use strict';

    var doc = document;
    var fm = doc.querySelector('[data-fm]');
    if (!fm || !window.Johnny) return;

    var Johnny = window.Johnny;
    var prefix = fm.getAttribute('data-prefix') || '';
    var bucket = fm.getAttribute('data-bucket') || '';

    function el(tag, className, text) {
        var node = doc.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;

        return node;
    }

    function formatBytes(bytes) {
        if (!bytes) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        var value = bytes / Math.pow(1024, i);

        return (i === 0 ? String(Math.round(value)) : value.toFixed(value >= 100 ? 0 : 1)) + ' ' + units[i];
    }

    // ===== Uploads =============================================================
    var chunkUrl = fm.getAttribute('data-chunk-url');
    var chunkBytes = parseInt(fm.getAttribute('data-chunk-bytes'), 10) || 1048576;
    var queueBox = doc.getElementById('fm-queue');
    var queueList = doc.getElementById('fm-queue-list');
    var queueTitle = doc.getElementById('fm-queue-title');
    var queueDone = doc.getElementById('fm-queue-done');
    var dropHint = doc.getElementById('fm-drop');
    var fileInput = doc.getElementById('fm-files');
    var queue = [];
    var uploading = false;
    var failed = 0;
    var finished = 0;

    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();

        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;

            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    function enqueue(file, path) {
        var row = el('li', 'fm-queue-item');
        var name = el('span', 'name', path);
        var state = el('span', 'state', 'waiting');
        var bar = el('span', 'progress');
        var fill = el('span', 'progress-fill');
        name.title = prefix + path;
        bar.appendChild(fill);
        row.appendChild(name);
        row.appendChild(state);
        row.appendChild(bar);
        queueList.appendChild(row);
        queue.push({ file: file, path: path, row: row, state: state, bar: bar, fill: fill });
    }

    function addFiles(entries) {
        if (!entries.length || !chunkUrl) return;
        entries.forEach(function (entry) { enqueue(entry.file, entry.path); });
        queueBox.hidden = false;
        queueDone.hidden = true;
        updateTitle();
        if (!uploading) next();
    }

    function updateTitle() {
        var total = finished + failed + queue.length + (uploading ? 1 : 0);
        var done = finished + failed;
        queueTitle.textContent = queue.length || uploading
            ? 'Uploading ' + Math.min(done + 1, total) + ' of ' + total + ' to ' + (prefix ? prefix : bucket + '/')
            : (failed ? finished + ' uploaded, ' + failed + ' failed' : finished + (finished === 1 ? ' file uploaded' : ' files uploaded'));
    }

    function sendChunk(form, onProgress) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', chunkUrl);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', Johnny.csrf);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.addEventListener('progress', function (event) {
                if (event.lengthComputable) onProgress(event.loaded);
            });
            xhr.addEventListener('load', function () {
                var data = {};
                try { data = JSON.parse(xhr.responseText); } catch (e) { /* not JSON */ }
                if (xhr.status >= 200 && xhr.status < 300) resolve(data);
                else reject(new Error(Johnny.errorMessage(xhr.status, data)));
            });
            xhr.addEventListener('error', function () { reject(new Error('The connection was lost.')); });
            xhr.send(form);
        });
    }

    function upload(item) {
        var file = item.file;
        var total = Math.max(1, Math.ceil(file.size / chunkBytes));
        var id = uuid();
        var index = 0;
        var offset = 0;

        function step() {
            var blob = file.slice(offset, Math.min(file.size, offset + chunkBytes));
            var form = new FormData();
            form.append('upload_id', id);
            form.append('index', String(index));
            form.append('total', String(total));
            form.append('offset', String(offset));
            form.append('size', String(file.size));
            form.append('name', item.path);
            form.append('prefix', prefix);
            if (/^[\w.+-]+\/[\w.+-]+$/.test(file.type)) form.append('type', file.type);
            form.append('chunk', blob, 'chunk');

            return sendChunk(form, function (loaded) {
                show(item, offset + Math.min(loaded, blob.size));
            }).then(function () {
                offset += blob.size;
                index += 1;
                show(item, offset);

                return index < total ? step() : null;
            });
        }

        return step();
    }

    function show(item, sent) {
        var percent = item.file.size ? Math.min(100, Math.round(sent / item.file.size * 100)) : 100;
        item.fill.style.width = percent + '%';
        item.state.textContent = percent + '% of ' + formatBytes(item.file.size);
    }

    function next() {
        var item = queue.shift();
        if (!item) {
            uploading = false;
            updateTitle();
            queueDone.hidden = false;
            if (failed === 0 && finished > 0) {
                // Show the new files: back to the first page of this folder.
                setTimeout(function () { window.location.assign(fm.getAttribute('data-folder-url')); }, 600);
            }

            return;
        }

        uploading = true;
        updateTitle();
        item.state.textContent = 'starting';
        upload(item).then(function () {
            finished += 1;
            item.row.classList.add('is-done');
            item.bar.classList.add('is-done');
            item.fill.style.width = '100%';
            item.state.textContent = 'uploaded';
        }).catch(function (error) {
            failed += 1;
            item.row.classList.add('is-error');
            item.bar.classList.add('is-failed');
            item.state.textContent = error.message;
        }).then(next);
    }

    if (fileInput) {
        doc.addEventListener('click', function (event) {
            if (event.target.closest('[data-fm-upload]')) fileInput.click();
        });
        fileInput.addEventListener('change', function () {
            addFiles(Array.prototype.map.call(fileInput.files, function (file) {
                return { file: file, path: file.webkitRelativePath || file.name };
            }));
            fileInput.value = '';
        });
    }

    // Dropped folders are walked with the entries API, so their structure is kept.
    function readEntry(entry, path, out) {
        return new Promise(function (resolve) {
            if (entry.isFile) {
                entry.file(function (file) { out.push({ file: file, path: path + file.name }); resolve(); }, function () { resolve(); });
            } else if (entry.isDirectory) {
                var reader = entry.createReader();
                var all = [];
                (function read() {
                    reader.readEntries(function (batch) {
                        if (!batch.length) {
                            Promise.all(all.map(function (child) { return readEntry(child, path + entry.name + '/', out); })).then(resolve);

                            return;
                        }
                        all = all.concat(Array.prototype.slice.call(batch));
                        read();
                    }, function () { resolve(); });
                })();
            } else {
                resolve();
            }
        });
    }

    var dragDepth = 0;
    function hasFiles(event) {
        return event.dataTransfer && Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') !== -1;
    }

    if (chunkUrl && dropHint) {
        fm.addEventListener('dragenter', function (event) {
            if (!hasFiles(event)) return;
            event.preventDefault();
            dragDepth += 1;
            dropHint.hidden = false;
        });
        fm.addEventListener('dragover', function (event) {
            if (hasFiles(event)) event.preventDefault();
        });
        fm.addEventListener('dragleave', function (event) {
            if (!hasFiles(event)) return;
            dragDepth = Math.max(0, dragDepth - 1);
            if (dragDepth === 0) dropHint.hidden = true;
        });
        fm.addEventListener('drop', function (event) {
            if (!hasFiles(event)) return;
            event.preventDefault();
            dragDepth = 0;
            dropHint.hidden = true;

            var items = event.dataTransfer.items;
            var entries = [];
            if (items && items.length && items[0].webkitGetAsEntry) {
                var out = [];
                Array.prototype.forEach.call(items, function (item) {
                    var entry = item.webkitGetAsEntry();
                    if (entry) entries.push(readEntry(entry, '', out));
                });
                Promise.all(entries).then(function () { addFiles(out); });
            } else {
                addFiles(Array.prototype.map.call(event.dataTransfer.files, function (file) {
                    return { file: file, path: file.name };
                }));
            }
        });
        window.addEventListener('beforeunload', function (event) {
            if (uploading) { event.preventDefault(); event.returnValue = ''; }
        });
    }

    // ===== Selection ===========================================================
    var bulkForm = doc.getElementById('fm-bulk');
    var selectBar = doc.getElementById('fm-select-bar');
    var selectCount = doc.getElementById('fm-select-count');
    var checkAll = doc.querySelector('[data-fm-check-all]');

    function checks() {
        return Array.prototype.slice.call(doc.querySelectorAll('.fm-check'));
    }

    function refreshSelection() {
        var all = checks();
        var selected = all.filter(function (box) { return box.checked; });
        all.forEach(function (box) { box.closest('tr').classList.toggle('is-selected', box.checked); });
        if (selectBar) selectBar.hidden = selected.length === 0;
        if (selectCount) selectCount.textContent = selected.length + ' selected';
        if (checkAll) {
            checkAll.checked = all.length > 0 && selected.length === all.length;
            checkAll.indeterminate = selected.length > 0 && selected.length < all.length;
        }
        if (bulkForm) {
            var folders = selected.filter(function (box) { return box.name === 'folders[]'; }).length;
            bulkForm.setAttribute('data-confirm', 'Delete ' + selected.length + (selected.length === 1 ? ' item' : ' items') + ' from ' + bucket + '?'
                + (folders ? ' Folders are deleted with everything inside them.' : '') + ' This cannot be undone.');
        }
    }

    if (bulkForm) {
        bulkForm.addEventListener('change', function (event) {
            if (event.target === checkAll) checks().forEach(function (box) { box.checked = checkAll.checked; });
            refreshSelection();
        });
        doc.addEventListener('click', function (event) {
            if (!event.target.closest('[data-fm-clear]')) return;
            checks().forEach(function (box) { box.checked = false; });
            refreshSelection();
        });
        refreshSelection();
    }

    // ===== Details panel =======================================================
    var drawer = doc.getElementById('fm-drawer');
    var drawerScrim = doc.getElementById('fm-scrim');
    var infoUrl = fm.getAttribute('data-info-url');
    var openKey = null;
    var lastFocus = null;

    function field(name) {
        return drawer.querySelector('[data-d="' + name + '"]');
    }

    function closeDrawer() {
        if (!drawer || drawer.hidden) return;
        drawer.hidden = true;
        drawerScrim.hidden = true;
        field('preview').textContent = '';
        openKey = null;
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    function renderPreview(info) {
        var box = field('preview');
        box.textContent = '';
        box.hidden = !info.kind;
        if (!info.kind) return;

        if (info.kind === 'image') {
            var img = el('img');
            img.alt = info.name;
            img.src = info.preview_url;
            box.appendChild(img);
        } else if (info.kind === 'video' || info.kind === 'audio') {
            var media = el(info.kind);
            media.controls = true;
            media.preload = 'metadata';
            media.src = info.preview_url;
            box.appendChild(media);
        } else if (info.kind === 'pdf') {
            var frame = el('iframe');
            frame.title = info.name;
            frame.src = info.preview_url;
            box.appendChild(frame);
        } else if (info.kind === 'text') {
            var pre = el('pre', '', 'Loading…');
            box.appendChild(pre);
            fetch(info.preview_url, { credentials: 'same-origin' })
                .then(function (response) { return response.ok ? response.text() : Promise.reject(); })
                .then(function (text) {
                    if (openKey !== info.key) return;
                    pre.textContent = text + (info.truncated ? '\n… (beginning of the file only)' : '');
                })
                .catch(function () { pre.textContent = 'The preview could not be loaded.'; });
        }
    }

    function openDrawer(key, trigger) {
        if (!drawer || !infoUrl) return;
        lastFocus = trigger || null;
        openKey = key;
        drawer.hidden = false;
        drawerScrim.hidden = false;
        field('name').textContent = key.split('/').pop();
        field('preview').hidden = true;
        ['key', 'size', 'modified', 'type', 'etag', 'uri'].forEach(function (name) { field(name).textContent = '…'; });
        drawer.querySelector('[data-drawer-close]').focus();

        Johnny.request(infoUrl + (infoUrl.indexOf('?') === -1 ? '?' : '&') + 'key=' + encodeURIComponent(key)).then(function (info) {
            if (openKey !== key) return;
            field('name').textContent = info.name;
            field('key').textContent = info.key;
            field('size').textContent = info.size_label + (info.size >= 1024 ? ' (' + info.size.toLocaleString('en') + ' bytes)' : '');
            field('modified').textContent = info.last_modified;
            field('type').textContent = info.content_type || '—';
            field('etag').textContent = info.etag || '—';
            field('uri').textContent = info.s3_uri;
            field('download').href = info.download_url;
            drawer.querySelectorAll('[data-d-key]').forEach(function (input) { input.value = info.key; });
            renderPreview(info);
        }).catch(function (error) {
            Johnny.toast(error.message, true);
            closeDrawer();
        });
    }

    if (drawer) {
        doc.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-fm-open]');
            if (trigger) {
                openDrawer(trigger.getAttribute('data-fm-open'), trigger);

                return;
            }
            if (event.target.closest('[data-drawer-close]') || event.target === drawerScrim) closeDrawer();
        });
        doc.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !doc.querySelector('dialog[open]')) closeDrawer();
        });
    }

    // ===== Share link ==========================================================
    var shareDialog = doc.getElementById('fm-share');
    if (shareDialog && shareDialog.showModal) {
        var shareResult = shareDialog.querySelector('[data-share-result]');
        var shareUrl = shareDialog.querySelector('[data-share-url]');
        var shareNote = shareDialog.querySelector('[data-share-note]');
        var shareCreate = shareDialog.querySelector('[data-share-create]');

        doc.addEventListener('click', function (event) {
            if (!event.target.closest('[data-fm-share]') || !openKey) return;
            shareDialog.querySelector('[data-share-name]').textContent = openKey.split('/').pop();
            shareResult.hidden = true;
            shareDialog.showModal();
        });

        shareCreate.addEventListener('click', function () {
            if (!openKey) return;
            shareCreate.disabled = true;
            Johnny.request(fm.getAttribute('data-share-url'), {
                json: { key: openKey, expires: parseInt(shareDialog.querySelector('select').value, 10) }
            }).then(function (data) {
                shareUrl.value = data.url;
                shareNote.textContent = 'Anyone with this link can download the file until ' + data.expires_at + '.';
                shareResult.hidden = false;
                shareUrl.focus();
                shareUrl.select();
            }).catch(function (error) {
                Johnny.toast(error.message, true);
            }).then(function () { shareCreate.disabled = false; });
        });
    }

    // ===== Rename or move ======================================================
    var moveDialog = doc.getElementById('fm-move');
    if (moveDialog && moveDialog.showModal) {
        doc.addEventListener('click', function (event) {
            if (!event.target.closest('[data-fm-move]') || !openKey) return;
            var to = moveDialog.querySelector('input[name="to"]');
            moveDialog.querySelector('input[name="key"]').value = openKey;
            to.value = openKey;
            moveDialog.showModal();
            to.focus();
            // Select the file name without its extension: the usual thing to change.
            var start = openKey.lastIndexOf('/') + 1;
            var dot = openKey.lastIndexOf('.');
            to.setSelectionRange(start, dot > start ? dot : openKey.length);
        });
    }
})();

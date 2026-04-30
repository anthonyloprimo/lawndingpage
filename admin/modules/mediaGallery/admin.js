// Media gallery admin interactions.

$(document).ready(function() {
    const $panes = $('.mediaGalleryPane');
    if (!$panes.length) {
        return;
    }
    if (window.__mediaGalleryAdminInitialized) {
        return;
    }
    window.__mediaGalleryAdminInitialized = true;

    let gdNoticeShown = false;

    const basePath = lpGetBasePath();
    const csrfToken = window.appConfig && window.appConfig.csrfToken ? window.appConfig.csrfToken : '';

    function buildUrl(file) {
        return basePath ? `${basePath}/res/scr/${file}` : `/res/scr/${file}`;
    }

    function makeAssetUrl(path) {
        if (!path) {
            return '';
        }
        if (/^https?:\/\//i.test(path)) {
            return path;
        }
        if (basePath && path.startsWith(basePath + '/')) {
            return path;
        }
        if (path.startsWith('/res/')) {
            return basePath + path;
        }
        if (path.startsWith('res/')) {
            return basePath ? `${basePath}/${path}` : `/${path}`;
        }
        if (path.startsWith('public/res/')) {
            const trimmed = path.slice('public/'.length);
            return basePath ? `${basePath}/${trimmed}` : `/${trimmed}`;
        }
        return path;
    }

    function addNotice(type, text) {
        if (typeof window.addAdminNotice === 'function') {
            window.addAdminNotice(type, text);
            return;
        }
        alert(text);
    }

    function parseApiResponse(response) {
        const contentType = (response.headers.get('content-type') || '').toLowerCase();
        if (contentType.indexOf('application/json') !== -1) {
            return response.json()
                .then((data) => ({ ok: response.ok, status: response.status, data, raw: '' }))
                .catch(() => ({ ok: response.ok, status: response.status, data: null, raw: '' }));
        }
        return response.text()
            .then((raw) => {
                let data = null;
                if (raw) {
                    try {
                        data = JSON.parse(raw);
                    } catch (err) {
                        data = null;
                    }
                }
                return { ok: response.ok, status: response.status, data, raw };
            })
            .catch(() => ({ ok: response.ok, status: response.status, data: null, raw: '' }));
    }

    function summarizeRawError(raw) {
        if (!raw || typeof raw !== 'string') {
            return '';
        }
        const stripped = raw.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        if (!stripped) {
            return '';
        }
        return stripped.length > 160 ? `${stripped.slice(0, 157)}...` : stripped;
    }

    function showSaving() {
        window.showSavingOverlay();
    }

    function hideSaving() {
        window.hideSavingOverlay();
    }

    function normalizeItems(items) {
        if (!Array.isArray(items)) {
            return [];
        }
        return items.map((item) => {
            const safe = item && typeof item === 'object' ? item : {};
            return {
                id: String(safe.id || ''),
                type: safe.type === 'video' ? 'video' : 'image',
                file: String(safe.file || ''),
                thumb: String(safe.thumb || ''),
                title: String(safe.title || ''),
                order: Number.isFinite(Number(safe.order)) ? Number(safe.order) : 0,
                original_size: parseInt(safe.original_size, 10) || 0,
                saved_size:    parseInt(safe.saved_size,    10) || 0,
                uploaded_at:           String(safe.uploaded_at || ''),
                uploaded_by:           String(safe.uploaded_by || ''),
                uploaded_by_display:   String(safe.uploaded_by_display || safe.uploaded_by || '')
            };
        }).filter((item) => item.id !== '');
    }

    function cloneItems(items) {
        return JSON.parse(JSON.stringify(items || []));
    }

    function sortItems(items) {
        return items.slice().sort((a, b) => a.order - b.order);
    }

    function reindexOrders(items) {
        const sorted = sortItems(items);
        sorted.forEach((item, index) => {
            item.order = index + 1;
        });
    }

    function getThumbUrl(item) {
        // Prefer the server-built display* URLs when available -- they
        // carry mtime-based ?v= cache busting so freshly regenerated
        // thumbs (after focal save, replace, etc.) render the new bytes
        // instead of the stale cached version. Fall back to the raw
        // path when the server hasn't supplied a display URL.
        if (item.displayThumb) {
            return item.displayThumb;
        }
        if (item.thumb) {
            return makeAssetUrl(item.thumb);
        }
        if (item.type === 'image') {
            if (item.displayFile) {
                return item.displayFile;
            }
            return makeAssetUrl(item.file);
        }
        return '';
    }

    function renderGrid(state) {
        const items = sortItems(state.items);
        state.$grid.empty();
        if (!items.length) {
            state.$grid.append('<div class="mediaGalleryEmpty">No media yet. Click Add new media to upload.</div>');
            return;
        }
        items.forEach((item, index) => {
            const $item = $('<div class="mediaGalleryItem"></div>');
            if (item.type === 'video') {
                $item.addClass('isVideo');
            }
            $item.attr('data-item-id', item.id)
                .attr('data-item-type', item.type)
                .attr('data-item-order', item.order)
                .attr('data-item-file', item.file)
                .attr('data-item-thumb', item.thumb)
                .attr('data-item-title', item.title);

            const $thumb = $('<button class="mediaGalleryThumbButton" type="button" aria-label="Edit media"></button>');
            const thumbUrl = getThumbUrl(item);
            if (thumbUrl) {
                $thumb.append($('<img class="mediaGalleryThumb">').attr({
                    src: thumbUrl,
                    alt: item.title || '',
                    loading: 'lazy',
                    decoding: 'async'
                }));
            }
            if (item.type === 'image' && item.original_size > 0) {
                const sizeLabel = 'Original: ' + lpFormatBytes(item.original_size)
                    + '\nResized:  ' + lpFormatBytes(item.saved_size);
                $thumb.attr('data-size-info', sizeLabel);
            }
            const $actions = $(
                '<div class="mediaGalleryItemActions">'
                + '<button class="mediaGalleryMoveUp iconButton" type="button" title="Move up" aria-label="Move up"></button>'
                + '<button class="mediaGalleryMoveDown iconButton" type="button" title="Move down" aria-label="Move down"></button>'
                + '</div>'
            );
            $actions.find('.mediaGalleryMoveUp').append(state.moveUpIcon);
            $actions.find('.mediaGalleryMoveDown').append(state.moveDownIcon);

            if (index === 0) {
                $actions.find('.mediaGalleryMoveUp').prop('disabled', true);
            }
            if (index === items.length - 1) {
                $actions.find('.mediaGalleryMoveDown').prop('disabled', true);
            }

            $item.append($thumb, $actions);
            state.$grid.append($item);
        });
    }

    function computeChanges(state) {
        const updates = [];
        const initialById = {};
        state.initialItems.forEach((item) => {
            initialById[item.id] = item;
        });
        state.items.forEach((item) => {
            const initial = initialById[item.id];
            if (!initial) {
                return;
            }
            const update = { id: item.id };
            let changed = false;
            if ((item.title || '') !== (initial.title || '')) {
                update.title = item.title || '';
                changed = true;
            }
            if (Number(item.order) !== Number(initial.order)) {
                update.order = Number(item.order) || 0;
                changed = true;
            }
            if (changed) {
                updates.push(update);
            }
        });
        return updates;
    }

    function updateChangesField(state) {
        const updates = computeChanges(state);
        if (!updates.length) {
            state.$changes.val('');
            return;
        }
        state.$changes.val(JSON.stringify({ updates }));
    }

    function setItemsFromPayload(state, items) {
        state.items = normalizeItems(items);
        reindexOrders(state.items);
        state.initialItems = cloneItems(state.items);
        state.$changes.val('');
        renderGrid(state);
    }

    function updateItemTitle(state, itemId, title) {
        const item = state.items.find((entry) => entry.id === itemId);
        if (!item) {
            return;
        }
        item.title = title;
        updateChangesField(state);
    }

    function moveItem(state, itemId, direction) {
        const sorted = sortItems(state.items);
        const index = sorted.findIndex((item) => item.id === itemId);
        if (index < 0) {
            return;
        }
        const targetIndex = direction === 'up' ? index - 1 : index + 1;
        if (targetIndex < 0 || targetIndex >= sorted.length) {
            return;
        }
        const temp = sorted[index];
        sorted[index] = sorted[targetIndex];
        sorted[targetIndex] = temp;
        sorted.forEach((item, idx) => {
            const target = state.items.find((entry) => entry.id === item.id);
            if (target) {
                target.order = idx + 1;
            }
        });
        renderGrid(state);
        updateChangesField(state);
    }

    function openModal(state, itemId) {
        const item = state.items.find((entry) => entry.id === itemId);
        if (!item) {
            return;
        }
        state.activeItemId = itemId;
        const $modal = state.$modal;
        $modal.toggleClass('isVideo', item.type === 'video');
        $modal.removeClass('isConfirmingDelete');
        state.modalOriginalTitle = item.title || '';
        $modal.find('.mediaGalleryCaptionInput').val(item.title || '');
        $modal.find('.mediaGalleryFocalMarker').attr('hidden', '');
        state.modalNaturalDims = null;
        state.pendingFocal = null;
        state.pendingThumbClear = false;

        const fileUrl = makeAssetUrl(item.file);
        const $image = $modal.find('.mediaGalleryModalImage');
        const $video = $modal.find('.mediaGalleryModalVideo');
        if (item.type === 'video') {
            $image.css('background-image', 'none');
            $video.attr('src', fileUrl).prop('currentTime', 0);
            $video.get(0).load();
        } else {
            $video.get(0).pause();
            $video.removeAttr('src');
            $video.get(0).load();
            $image.css('background-image', fileUrl ? `url('${fileUrl}')` : 'none');
            loadModalNaturalDims(state, fileUrl, itemId, function() {
                positionFocalMarker(state);
            });
        }

        updateSaveEnabled(state);
        populateImageInfo($modal, item);

        if (typeof window.openAdminModal === 'function') {
            window.openAdminModal($modal);
        } else {
            $modal.addClass('isOpen').attr('aria-hidden', 'false');
        }
    }

    function populateImageInfo($modal, item) {
        const fmt = window.lpFormatBytes || function (b) { return b ? b + ' B' : '—'; };
        const orig = item.original_size > 0 ? fmt(item.original_size) : '—';
        const saved = item.saved_size > 0 ? fmt(item.saved_size) : '—';
        let when = '—';
        if (item.uploaded_at) {
            const d = new Date(item.uploaded_at);
            when = isNaN(d.getTime()) ? item.uploaded_at : d.toLocaleString();
        }
        const who = item.uploaded_by_display || item.uploaded_by || '—';
        $modal.find('.mediaGalleryInfoOriginalSize').text(orig);
        $modal.find('.mediaGalleryInfoSavedSize').text(saved);
        $modal.find('.mediaGalleryInfoUploadedAt').text(when);
        $modal.find('.mediaGalleryInfoUploadedBy').text(who);
    }

    function closeModal(state) {
        const $modal = state.$modal;
        $modal.removeClass('isConfirmingDelete');
        const $video = $modal.find('.mediaGalleryModalVideo');
        if ($video.length) {
            $video.get(0).pause();
            $video.removeAttr('src');
            $video.get(0).load();
        }
        if (typeof window.closeAdminModal === 'function') {
            window.closeAdminModal($modal);
        } else {
            $modal.removeClass('isOpen').attr('aria-hidden', 'true');
        }
        state.activeItemId = null;
        state.pendingFocal = null;
        state.pendingThumbClear = false;
        state.modalOriginalTitle = '';
        updateSaveEnabled(state);
    }

    // Compute the on-screen bounding rect of the displayed image inside
    // a container with object-fit/background-size: contain semantics.
    // Returns { left, top, width, height } in container coords, or null
    // if naturalDims are unavailable.
    function computeImageDisplayBounds(rect, naturalDims) {
        if (!naturalDims || !naturalDims.w || !naturalDims.h || rect.width <= 0 || rect.height <= 0) {
            return null;
        }
        const containerAspect = rect.width / rect.height;
        const naturalAspect = naturalDims.w / naturalDims.h;
        if (containerAspect > naturalAspect) {
            const imgH = rect.height;
            const imgW = imgH * naturalAspect;
            return { left: (rect.width - imgW) / 2, top: 0, width: imgW, height: imgH };
        }
        const imgW = rect.width;
        const imgH = imgW / naturalAspect;
        return { left: 0, top: (rect.height - imgH) / 2, width: imgW, height: imgH };
    }

    // Read whichever focal value should currently drive the marker:
    // pendingFocal (in-flight, unsaved click/arrow change) wins; falls
    // back to the saved item.focal_x / focal_y. pendingFocal is cleared
    // by openModal/closeModal/saveFocalToServer so it never leaks across
    // sessions.
    function getDisplayedFocal(state, item) {
        if (state.pendingFocal) {
            return state.pendingFocal;
        }
        return { focal_x: item.focal_x, focal_y: item.focal_y };
    }

    function positionFocalMarker(state) {
        const $marker = state.$modal.find('.mediaGalleryFocalMarker');
        const item = state.items.find((entry) => entry.id === state.activeItemId);
        if (!item || item.type === 'video') {
            $marker.attr('hidden', '');
            return;
        }
        const focal = getDisplayedFocal(state, item);
        if (focal.focal_x == null || focal.focal_y == null) {
            $marker.attr('hidden', '');
            return;
        }
        const $modalImage = state.$modal.find('.mediaGalleryModalImage');
        const naturalDims = state.modalNaturalDims;
        if (!naturalDims) {
            $marker.attr('hidden', '');
            return;
        }
        const rect = $modalImage[0].getBoundingClientRect();
        const bounds = computeImageDisplayBounds(rect, naturalDims);
        if (!bounds) {
            $marker.attr('hidden', '');
            return;
        }
        const left = bounds.left + bounds.width * focal.focal_x;
        const top = bounds.top + bounds.height * focal.focal_y;
        $marker.css({ left: left + 'px', top: top + 'px' }).removeAttr('hidden');
    }

    function isCaptionDirty(state) {
        const $input = state.$modal.find('.mediaGalleryCaptionInput');
        if (!$input.length) {
            return false;
        }
        const current = $input.val() || '';
        const baseline = typeof state.modalOriginalTitle === 'string' ? state.modalOriginalTitle : '';
        return current !== baseline;
    }

    function updateSaveEnabled(state) {
        const hasChanges = state.pendingFocal !== null
            || state.pendingThumbClear === true
            || isCaptionDirty(state);
        state.$modal.find('.mediaGalleryFocalSave').prop('disabled', !hasChanges);
    }

    function setPendingFocal(state, focalX, focalY) {
        state.pendingFocal = { focal_x: focalX, focal_y: focalY };
        positionFocalMarker(state);
        updateSaveEnabled(state);
    }

    // itemId acts as a stale-request token: if the user navigates to a
    // different item before this load completes, the result is dropped
    // instead of stomping the current item's modalNaturalDims.
    function loadModalNaturalDims(state, src, itemId, callback) {
        if (!src) {
            if (state.activeItemId === itemId) {
                state.modalNaturalDims = null;
                callback();
            }
            return;
        }
        const img = new Image();
        img.onload = function() {
            if (state.activeItemId !== itemId) {
                return;
            }
            state.modalNaturalDims = { w: img.naturalWidth, h: img.naturalHeight };
            callback();
        };
        img.onerror = function() {
            if (state.activeItemId !== itemId) {
                return;
            }
            state.modalNaturalDims = null;
            callback();
        };
        img.src = src;
    }

    function saveCaptionToServer(state, itemId, title, onSuccess) {
        const formData = new URLSearchParams();
        formData.append('module', 'mediaGallery');
        formData.append('endpoint', 'caption');
        formData.append('paneId', state.paneId);
        formData.append('itemId', itemId);
        formData.append('title', title);
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }
        fetch(buildUrl('module-endpoint.php'), {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok || !data || data.error) {
                    if (typeof window.addAdminNotice === 'function') {
                        window.addAdminNotice('danger', (data && data.error) || 'Failed to save caption.');
                    }
                    return;
                }
                if (Array.isArray(data.items)) {
                    state.items = data.items;
                    state.initialItems = JSON.parse(JSON.stringify(data.items));
                    renderGrid(state);
                }
                if (typeof onSuccess === 'function') {
                    onSuccess();
                }
            })
            .catch(() => {
                if (typeof window.addAdminNotice === 'function') {
                    window.addAdminNotice('danger', 'Failed to save caption.');
                }
            });
    }

    function saveFocalToServer(state, itemId, focalX, focalY, onSuccess) {
        const formData = new URLSearchParams();
        formData.append('module', 'mediaGallery');
        formData.append('endpoint', 'focal');
        formData.append('paneId', state.paneId);
        formData.append('itemId', itemId);
        formData.append('focal_x', focalX === null ? '' : String(focalX));
        formData.append('focal_y', focalY === null ? '' : String(focalY));
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }
        fetch(buildUrl('module-endpoint.php'), {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok || !data || data.error) {
                    if (typeof window.addAdminNotice === 'function') {
                        window.addAdminNotice('danger', (data && data.error) || 'Failed to save focal point.');
                    }
                    return;
                }
                if (Array.isArray(data.items)) {
                    state.items = data.items;
                    state.initialItems = JSON.parse(JSON.stringify(data.items));
                    renderGrid(state);
                }
                if (typeof onSuccess === 'function') {
                    onSuccess();
                }
            })
            .catch(() => {
                if (typeof window.addAdminNotice === 'function') {
                    window.addAdminNotice('danger', 'Failed to save focal point.');
                }
            });
    }

    function refreshFromServer(state) {
        const formData = new FormData();
        formData.append('paneId', state.paneId);
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }
        return fetch(buildUrl('media-gallery-list.php'), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok || !data) {
                    return;
                }
                setItemsFromPayload(state, (data && data.items) ? data.items : []);
            })
            .catch(() => {});
    }

    // Run smartcrop.js (vendored at v2.0.5) on a picked file to suggest
    // a focal point before upload. Resolves to {focal_x, focal_y} on
    // success, or null on any failure (smartcrop missing, image decode
    // failed, non-image file). Failure-graceful by design: a null
    // resolution just means the upload posts without focal coords and
    // the server falls back to centered crop -- same as today's
    // behavior with no focal hint, never worse.
    //
    // The focal point is computed as the center of smartcrop's chosen
    // 400x400 crop window, normalized to [0, 1] of source pixel
    // dimensions. That's the same shape the server expects from a
    // human-set focal-point picker click.
    function smartcropFocalForFile(file) {
        return new Promise(function(resolve) {
            if (typeof window.smartcrop === 'undefined') {
                resolve(null);
                return;
            }
            if (!file || !file.type || file.type.indexOf('image/') !== 0) {
                resolve(null);
                return;
            }
            let blobUrl;
            try {
                blobUrl = URL.createObjectURL(file);
            } catch (e) {
                resolve(null);
                return;
            }
            const img = new Image();
            const cleanup = function() {
                try { URL.revokeObjectURL(blobUrl); } catch (e) { /* ignore */ }
            };
            img.onload = function() {
                if (!img.naturalWidth || !img.naturalHeight) {
                    cleanup();
                    resolve(null);
                    return;
                }
                let cropPromise;
                try {
                    cropPromise = window.smartcrop.crop(img, { width: 400, height: 400 });
                } catch (e) {
                    cleanup();
                    resolve(null);
                    return;
                }
                cropPromise.then(function(result) {
                    cleanup();
                    const tc = result && result.topCrop;
                    if (!tc || typeof tc.x !== 'number' || typeof tc.y !== 'number'
                        || typeof tc.width !== 'number' || typeof tc.height !== 'number') {
                        resolve(null);
                        return;
                    }
                    const focalX = (tc.x + tc.width / 2) / img.naturalWidth;
                    const focalY = (tc.y + tc.height / 2) / img.naturalHeight;
                    resolve({
                        focal_x: Math.max(0, Math.min(1, focalX)),
                        focal_y: Math.max(0, Math.min(1, focalY)),
                    });
                }).catch(function() {
                    cleanup();
                    resolve(null);
                });
            };
            img.onerror = function() {
                cleanup();
                resolve(null);
            };
            img.src = blobUrl;
        });
    }

    // Smartcrop the file (best-effort) then hand off to uploadMedia
    // with the resulting focal coords merged into options.focal.
    // Wrapper exists so callers (single + batch) share the same
    // smartcrop-then-upload control flow without duplicating it.
    function smartcropAndUpload(state, file, options) {
        smartcropFocalForFile(file).then(function(focal) {
            const merged = Object.assign({}, options || {});
            if (focal) {
                merged.focal = focal;
            }
            uploadMedia(state, file, merged);
        });
    }

    // options.focal                  -- {focal_x, focal_y} pre-computed
    //                                   before upload (smartcrop or
    //                                   future hooks). Posted alongside
    //                                   the file so the server stores
    //                                   them on the new item and uses
    //                                   them in derive_thumb.
    // options.suppressSuccessNotice  -- caller will surface a batch
    //                                   summary instead of per-file
    //                                   notices.
    // options.onComplete(ok)         -- called after the upload
    //                                   resolves (success or failure).
    //                                   Lets the batch driver chain
    //                                   the next upload once this one
    //                                   has fully settled, including
    //                                   the items-payload sync.
    function uploadMedia(state, file, options) {
        options = options || {};
        if (!file) {
            if (typeof options.onComplete === 'function') {
                options.onComplete(false);
            }
            return;
        }
        const formData = new FormData();
        formData.append('paneId', state.paneId);
        formData.append('mediaFile', file);
        if (options.focal
            && typeof options.focal.focal_x === 'number'
            && typeof options.focal.focal_y === 'number') {
            formData.append('focal_x', String(options.focal.focal_x));
            formData.append('focal_y', String(options.focal.focal_y));
        }
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }
        showSaving();
        fetch(buildUrl('media-gallery-upload.php'), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then((response) => parseApiResponse(response))
            .then(({ ok, status, data, raw }) => {
                if (!ok) {
                    let message = data && data.error ? data.error : '';
                    if (!message) {
                        const rawSummary = summarizeRawError(raw);
                        message = rawSummary || `Upload failed (HTTP ${status}).`;
                    }
                    addNotice(status === 413 ? 'warning' : 'danger', message);
                    hideSaving();
                    if (typeof options.onComplete === 'function') {
                        options.onComplete(false);
                    }
                    return;
                }
                setItemsFromPayload(state, data.items || []);
                if (!options.suppressSuccessNotice) {
                    addNotice('ok', 'Media uploaded.');
                }
                if (data.gd_unavailable && !gdNoticeShown) {
                    gdNoticeShown = true;
                    addNotice('ok', 'For better performance, install the PHP GD extension on your server.');
                }
                hideSaving();
                if (typeof options.onComplete === 'function') {
                    options.onComplete(true);
                }
            })
            .catch(() => {
                addNotice('danger', 'Upload failed. Please try again.');
                hideSaving();
                if (typeof options.onComplete === 'function') {
                    options.onComplete(false);
                }
            });
    }

    function replaceMedia(state, itemId, file) {
        if (!file) {
            return;
        }
        const formData = new FormData();
        formData.append('paneId', state.paneId);
        formData.append('itemId', itemId);
        formData.append('mediaFile', file);
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }
        showSaving();
        fetch(buildUrl('media-gallery-replace.php'), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, status: response.status, data })))
            .then(({ ok, status, data }) => {
                if (!ok) {
                    const message = data && data.error ? data.error : 'Upload failed.';
                    addNotice(status === 413 ? 'warning' : 'danger', message);
                    hideSaving();
                    return;
                }
                setItemsFromPayload(state, data.items || []);
                openModal(state, itemId);
                addNotice('ok', 'Media updated.');
                if (data.gd_unavailable && !gdNoticeShown) {
                    gdNoticeShown = true;
                    addNotice('ok', 'For better performance, install the PHP GD extension on your server.');
                }
                hideSaving();
            })
            .catch(() => {
                addNotice('danger', 'Upload failed. Please try again.');
                hideSaving();
            });
    }

    function setThumbnail(state, itemId, file) {
        if (!file) {
            return;
        }
        const formData = new FormData();
        formData.append('paneId', state.paneId);
        formData.append('itemId', itemId);
        formData.append('thumbFile', file);
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }
        showSaving();
        fetch(buildUrl('media-gallery-thumb.php'), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, status: response.status, data })))
            .then(({ ok, status, data }) => {
                if (!ok) {
                    const message = data && data.error ? data.error : 'Thumbnail upload failed.';
                    addNotice(status === 413 ? 'warning' : 'danger', message);
                    hideSaving();
                    return;
                }
                setItemsFromPayload(state, data.items || []);
                openModal(state, itemId);
                addNotice('ok', 'Thumbnail updated.');
                hideSaving();
            })
            .catch(() => {
                addNotice('danger', 'Thumbnail upload failed. Please try again.');
                hideSaving();
            });
    }

    // Called from the Save flow after the user has clicked "Use default
    // thumbnail" (which only marks state.pendingThumbClear). Posts the
    // clear to the server and yields to onSuccess so the Save handler
    // can chain into a focal save if one is also pending. Doesn't re-
    // open the modal -- the Save handler closes it after all chained
    // commits finish.
    function clearThumbnail(state, itemId, onSuccess) {
        const formData = new FormData();
        formData.append('paneId', state.paneId);
        formData.append('itemId', itemId);
        formData.append('clear', '1');
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }
        showSaving();
        fetch(buildUrl('media-gallery-thumb.php'), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    const message = data && data.error ? data.error : 'Thumbnail update failed.';
                    addNotice('danger', message);
                    hideSaving();
                    return;
                }
                setItemsFromPayload(state, data.items || []);
                hideSaving();
                if (typeof onSuccess === 'function') {
                    onSuccess();
                }
            })
            .catch(() => {
                addNotice('danger', 'Thumbnail update failed. Please try again.');
                hideSaving();
            });
    }

    function deleteMedia(state, itemId) {
        const formData = new FormData();
        formData.append('paneId', state.paneId);
        formData.append('itemId', itemId);
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }
        showSaving();
        fetch(buildUrl('media-gallery-delete.php'), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    const message = data && data.error ? data.error : 'Delete failed.';
                    addNotice('danger', message);
                    hideSaving();
                    return;
                }
                setItemsFromPayload(state, data.items || []);
                closeModal(state);
                addNotice('ok', 'Media deleted.');
                hideSaving();
            })
            .catch(() => {
                addNotice('danger', 'Delete failed. Please try again.');
                hideSaving();
            });
    }

    const paneStates = [];

    $panes.each(function() {
        const $pane = $(this);
        const paneId = $pane.data('pane-id') || $pane.attr('id') || '';
        const $grid = $pane.find('.mediaGalleryGrid');
        const $changes = $pane.find('.mediaGalleryChanges');
        const $dataScript = $pane.find('.mediaGalleryData');
        const $modal = $pane.find('.mediaGalleryModal');
        if ($modal.length && !$modal.parent().is('body')) {
            $('body').append($modal);
        }

        const $settingsModal = $pane.find('.mediaGallerySettingsModal');
        if ($settingsModal.length && !$settingsModal.parent().is('body')) {
            $('body').append($settingsModal);
        }

        let payload = {};
        if ($dataScript.length) {
            try {
                payload = JSON.parse($dataScript.text() || '{}');
            } catch (err) {
                payload = {};
            }
        }

        const items = normalizeItems(payload.items || []);
        reindexOrders(items);

        const state = {
            paneId,
            items,
            initialItems: cloneItems(items),
            $pane,
            $grid,
            $changes,
            $modal,
            activeItemId: null,
            moveUpIcon: $pane.find('.mediaGalleryMoveUp').first().html() || '',
            moveDownIcon: $pane.find('.mediaGalleryMoveDown').first().html() || ''
        };

        if (!state.moveUpIcon) {
            state.moveUpIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M13,20H11V8L5.5,13.5L4.08,12.08L12,4.16L19.92,12.08L18.5,13.5L13,8V20Z" /></svg>';
        }
        if (!state.moveDownIcon) {
            state.moveDownIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M11,4H13V16L18.5,10.5L19.92,11.92L12,19.84L4.08,11.92L5.5,10.5L11,16V4Z" /></svg>';
        }

        renderGrid(state);
        paneStates.push(state);

        $pane.on('click', '.mediaGalleryThumbButton', function() {
            const itemId = $(this).closest('.mediaGalleryItem').data('item-id') || '';
            if (itemId) {
                openModal(state, String(itemId));
            }
        });

        $pane.on('click', '.mediaGallerySettingsButton', function() {
            if (typeof window.openAdminModal === 'function') {
                window.openAdminModal($settingsModal);
            } else {
                $settingsModal.addClass('isOpen').attr('aria-hidden', 'false');
            }
        });

        function closeSettingsModal() {
            if (typeof window.closeAdminModal === 'function') {
                window.closeAdminModal($settingsModal);
            } else {
                $settingsModal.removeClass('isOpen').attr('aria-hidden', 'true');
            }
        }

        $settingsModal.on('click', '.userModalClose, .mediaGallerySettingsCancel', function() {
            closeSettingsModal();
        });
        $settingsModal.on('click', function(event) {
            if ($(event.target).is('.mediaGallerySettingsModal')) {
                closeSettingsModal();
            }
        });

        // "Use site defaults" toggles whether the override fieldset is editable.
        // Disabled fieldset means even if a value is checked, it won't post.
        $settingsModal.on('change', '.mediaGallerySettingsUseDefaultsInput', function() {
            const useDefaults = $(this).is(':checked');
            $settingsModal.find('.mediaGallerySettingsOverrides').prop('disabled', useDefaults);
        });

        $settingsModal.on('click', '.mediaGallerySettingsSave', function() {
            const useDefaults = $settingsModal.find('.mediaGallerySettingsUseDefaultsInput').is(':checked');
            const $overrides = $settingsModal.find('.mediaGallerySettingsOverrides');
            const formData = new URLSearchParams();
            formData.append('module', 'mediaGallery');
            formData.append('endpoint', 'settings');
            formData.append('paneId', state.paneId);
            formData.append('useSiteDefaults', useDefaults ? '1' : '0');
            if (!useDefaults) {
                if ($overrides.find('input[name="customThumbs"]').is(':checked')) {
                    formData.append('customThumbs', '1');
                }
                if ($overrides.find('input[name="changeMedia"]').is(':checked')) {
                    formData.append('changeMedia', '1');
                }
            }
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }
            showSaving();
            fetch(buildUrl('module-endpoint.php'), {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
                .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    hideSaving();
                    if (!ok || !data || data.error) {
                        addNotice('danger', (data && data.error) || 'Failed to save settings.');
                        return;
                    }
                    addNotice('ok', 'Settings saved. Reload to see changes.');
                    closeSettingsModal();
                })
                .catch(() => {
                    hideSaving();
                    addNotice('danger', 'Failed to save settings.');
                });
        });

        $pane.on('click', '.mediaGalleryMoveUp', function() {
            const itemId = $(this).closest('.mediaGalleryItem').data('item-id') || '';
            if (itemId) {
                moveItem(state, String(itemId), 'up');
            }
        });

        $pane.on('click', '.mediaGalleryMoveDown', function() {
            const itemId = $(this).closest('.mediaGalleryItem').data('item-id') || '';
            if (itemId) {
                moveItem(state, String(itemId), 'down');
            }
        });

        $pane.find('.mediaGalleryAddButton').on('click', function() {
            $pane.find('.mediaGalleryUploadInput').trigger('click');
        });

        $pane.find('.mediaGalleryUploadInput').on('change', function() {
            const files = this.files ? Array.from(this.files) : [];
            this.value = '';
            if (files.length === 0) {
                return;
            }
            if (files.length === 1) {
                smartcropAndUpload(state, files[0]);
                return;
            }

            // Sequential batch upload. media-gallery-upload.php does a
            // read-modify-write on the gallery's JSON file -- two
            // concurrent uploads would race and the second writer's
            // append could clobber the first's. Chaining via the
            // onComplete callback guarantees each request reads the
            // post-previous-write state.
            const total = files.length;
            let succeeded = 0;
            let failed = 0;
            const next = function(index) {
                if (index >= total) {
                    if (succeeded > 0 && failed === 0) {
                        addNotice('ok', succeeded + ' files uploaded.');
                    } else if (succeeded > 0 && failed > 0) {
                        addNotice('warning', succeeded + ' uploaded, ' + failed + ' failed.');
                    }
                    // If all failed, the per-file danger notices already
                    // surfaced the reasons.
                    return;
                }
                smartcropAndUpload(state, files[index], {
                    suppressSuccessNotice: true,
                    onComplete: function(ok) {
                        if (ok) {
                            succeeded++;
                        } else {
                            failed++;
                        }
                        next(index + 1);
                    },
                });
            };
            next(0);
        });

        $modal.on('click', '.userModalClose', function() {
            closeModal(state);
        });

        $modal.on('click', function(event) {
            if ($(event.target).is('.mediaGalleryModal')) {
                closeModal(state);
            }
        });

        $modal.on('input', '.mediaGalleryCaptionInput', function() {
            const itemId = state.activeItemId;
            if (!itemId) {
                return;
            }
            updateItemTitle(state, itemId, $(this).val() || '');
            updateSaveEnabled(state);
        });

        $modal.on('click', '.mediaGalleryChangeButton', function() {
            $modal.find('.mediaGalleryChangeInput').trigger('click');
        });

        $modal.on('change', '.mediaGalleryChangeInput', function() {
            const file = this.files && this.files[0] ? this.files[0] : null;
            this.value = '';
            const itemId = state.activeItemId;
            if (file && itemId) {
                replaceMedia(state, itemId, file);
            }
        });

        $modal.on('click', '.mediaGalleryThumbButtonAction', function() {
            $modal.find('.mediaGalleryThumbInput').trigger('click');
        });

        $modal.on('change', '.mediaGalleryThumbInput', function() {
            const file = this.files && this.files[0] ? this.files[0] : null;
            this.value = '';
            const itemId = state.activeItemId;
            if (file && itemId) {
                setThumbnail(state, itemId, file);
            }
        });

        $modal.on('click', '.mediaGalleryThumbClear', function() {
            const itemId = state.activeItemId;
            if (!itemId) {
                return;
            }
            // Defer the actual clear until Save commits. Marks pending,
            // enables the Save button.
            state.pendingThumbClear = true;
            updateSaveEnabled(state);
        });

        $modal.on('click', '.mediaGalleryModalImage', function(event) {
            const itemId = state.activeItemId;
            if (!itemId) {
                return;
            }
            const item = state.items.find((entry) => entry.id === itemId);
            if (!item || item.type === 'video' || !state.modalNaturalDims) {
                return;
            }
            const $modalImage = $(this);
            const rect = $modalImage[0].getBoundingClientRect();
            const bounds = computeImageDisplayBounds(rect, state.modalNaturalDims);
            if (!bounds) {
                return;
            }
            const clickX = event.clientX - rect.left - bounds.left;
            const clickY = event.clientY - rect.top - bounds.top;
            // Click on the letterbox padding (outside the displayed image
            // area) is the reset gesture: pendingFocal cleared to nulls,
            // marker hides, Save button stays enabled so the reset can be
            // committed.
            if (clickX < 0 || clickX > bounds.width || clickY < 0 || clickY > bounds.height) {
                setPendingFocal(state, null, null);
                return;
            }
            const focalX = Math.max(0, Math.min(1, clickX / bounds.width));
            const focalY = Math.max(0, Math.min(1, clickY / bounds.height));
            setPendingFocal(state, focalX, focalY);
        });

        $modal.on('keydown', '.mediaGalleryModalImage', function(event) {
            const itemId = state.activeItemId;
            if (!itemId) {
                return;
            }
            const item = state.items.find((entry) => entry.id === itemId);
            if (!item || item.type === 'video') {
                return;
            }
            const STEP = 0.05;
            let dx = 0;
            let dy = 0;
            switch (event.key) {
                case 'ArrowLeft':  dx = -STEP; break;
                case 'ArrowRight': dx = STEP;  break;
                case 'ArrowUp':    dy = -STEP; break;
                case 'ArrowDown':  dy = STEP;  break;
                default: return;
            }
            event.preventDefault();
            const displayed = getDisplayedFocal(state, item);
            const baseX = displayed.focal_x == null ? 0.5 : displayed.focal_x;
            const baseY = displayed.focal_y == null ? 0.5 : displayed.focal_y;
            const newFocalX = Math.max(0, Math.min(1, baseX + dx));
            const newFocalY = Math.max(0, Math.min(1, baseY + dy));
            setPendingFocal(state, newFocalX, newFocalY);
        });

        $modal.on('click', '.mediaGalleryFocalSave', function() {
            const itemId = state.activeItemId;
            if (!itemId) {
                return;
            }

            const finishSave = function() {
                state.pendingFocal = null;
                state.pendingThumbClear = false;
                state.modalOriginalTitle = '';
                closeModal(state);
            };

            // Order matters across the chained commits:
            // 1. Caption FIRST -- thumb-clear and focal-save endpoints
            //    each return a refreshed items payload that would
            //    overwrite our local item.title mutation if caption ran
            //    later.
            // 2. Thumb-clear NEXT -- focal-save preserves an existing
            //    custom thumb and only re-derives the auto-thumb when
            //    item.thumb isn't custom. Clearing first makes item.
            //    thumb empty so focal-save's re-derive fires correctly.
            // 3. Focal-save LAST -- consumes whatever item state the
            //    earlier saves produced.
            const commitFocalIfPending = function() {
                if (state.pendingFocal !== null) {
                    saveFocalToServer(
                        state,
                        itemId,
                        state.pendingFocal.focal_x,
                        state.pendingFocal.focal_y,
                        finishSave
                    );
                } else {
                    finishSave();
                }
            };

            const commitThumbIfPending = function() {
                if (state.pendingThumbClear) {
                    clearThumbnail(state, itemId, commitFocalIfPending);
                } else {
                    commitFocalIfPending();
                }
            };

            if (isCaptionDirty(state)) {
                const newTitle = state.$modal.find('.mediaGalleryCaptionInput').val() || '';
                saveCaptionToServer(state, itemId, newTitle, commitThumbIfPending);
            } else {
                commitThumbIfPending();
            }
        });

        $(window).on('resize', function() {
            if (state.activeItemId && state.$modal.hasClass('isOpen')) {
                positionFocalMarker(state);
            }
        });

        $modal.on('click', '.mediaGalleryRemoveButton', function() {
            const itemId = state.activeItemId;
            if (!itemId) {
                return;
            }
            $modal.addClass('isConfirmingDelete');
            // Move focus to the No button so the default Enter / Esc
            // path is "back out" rather than "confirm delete." The Yes
            // button explicitly receives danger styling but isn't the
            // default focus.
            $modal.find('.mediaGalleryConfirmDeleteNo').trigger('focus');
        });

        $modal.on('click', '.mediaGalleryConfirmDeleteYes', function() {
            const itemId = state.activeItemId;
            if (!itemId) {
                return;
            }
            $modal.removeClass('isConfirmingDelete');
            deleteMedia(state, itemId);
        });

        $modal.on('click', '.mediaGalleryConfirmDeleteNo', function() {
            $modal.removeClass('isConfirmingDelete');
        });
    });

    window.refreshMediaGalleryUIs = function() {
        return Promise.all(paneStates.map((state) => refreshFromServer(state)));
    };
});

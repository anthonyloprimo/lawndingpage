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

    const basePath = window.appConfig && typeof window.appConfig.basePath === 'string'
        ? window.appConfig.basePath.replace(/\/$/, '')
        : '';
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
        if (typeof window.showSavingOverlay === 'function') {
            window.showSavingOverlay();
        } else {
            $('#savingOverlay').addClass('isActive').attr('aria-hidden', 'false');
        }
    }

    function hideSaving() {
        if (typeof window.hideSavingOverlay === 'function') {
            window.hideSavingOverlay();
        } else {
            $('#savingOverlay').removeClass('isActive').attr('aria-hidden', 'true');
        }
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
                order: Number.isFinite(Number(safe.order)) ? Number(safe.order) : 0
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
        if (item.thumb) {
            return makeAssetUrl(item.thumb);
        }
        if (item.type === 'image') {
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
                $thumb.css('background-image', `url('${thumbUrl}')`);
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
        $modal.find('.mediaGalleryCaptionInput').val(item.title || '');

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
        }

        if (typeof window.openAdminModal === 'function') {
            window.openAdminModal($modal);
        } else {
            $modal.addClass('isOpen').attr('aria-hidden', 'false');
        }
    }

    function closeModal(state) {
        const $modal = state.$modal;
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
    }

    function refreshFromServer(state) {
        const url = buildUrl(`media-gallery-list.php?paneId=${encodeURIComponent(state.paneId)}`);
        return fetch(url, { credentials: 'same-origin' })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok || !data) {
                    return;
                }
                setItemsFromPayload(state, (data && data.items) ? data.items : []);
            })
            .catch(() => {});
    }

    function uploadMedia(state, file) {
        if (!file) {
            return;
        }
        const formData = new FormData();
        formData.append('paneId', state.paneId);
        formData.append('mediaFile', file);
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
                    addNotice('danger', message);
                    hideSaving();
                    return;
                }
                setItemsFromPayload(state, data.items || []);
                addNotice('ok', 'Media uploaded.');
                hideSaving();
            })
            .catch(() => {
                addNotice('danger', 'Upload failed. Please try again.');
                hideSaving();
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
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    const message = data && data.error ? data.error : 'Upload failed.';
                    addNotice('danger', message);
                    hideSaving();
                    return;
                }
                setItemsFromPayload(state, data.items || []);
                openModal(state, itemId);
                addNotice('ok', 'Media updated.');
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
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    const message = data && data.error ? data.error : 'Thumbnail upload failed.';
                    addNotice('danger', message);
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

    function clearThumbnail(state, itemId) {
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
                openModal(state, itemId);
                addNotice('ok', 'Thumbnail cleared.');
                hideSaving();
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
                addNotice('ok', 'Media removed.');
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
            const file = this.files && this.files[0] ? this.files[0] : null;
            this.value = '';
            if (file) {
                uploadMedia(state, file);
            }
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
            if (itemId) {
                clearThumbnail(state, itemId);
            }
        });

        $modal.on('click', '.mediaGalleryRemoveButton', function() {
            const itemId = state.activeItemId;
            if (itemId) {
                deleteMedia(state, itemId);
            }
        });
    });

    window.refreshMediaGalleryUIs = function() {
        return Promise.all(paneStates.map((state) => refreshFromServer(state)));
    };
});

// Config page tutorial and interactions (UI only, no persistence).

$(document).ready(function() {
    const steps = buildTutorialSteps();
    let currentStep = 0;
    let pendingLogoFile = null;
    let linkCounter = $('.linksConfigCard').length;
    let initialSnapshot = null;
    let pendingBgDelete = null;
    const csrfToken = window.appConfig && window.appConfig.csrfToken ? window.appConfig.csrfToken : '';
    let activeModal = null;
    let lastFocusedElement = null;
    const modalStack = [];
    const modalBackgroundSelectors = ['#header', '#container', 'nav', '.adminNotices'];

    function appendCsrf(formData) {
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }
    }

    function setModalBackgroundState(isOpen) {
        modalBackgroundSelectors.forEach((selector) => {
            document.querySelectorAll(selector).forEach((node) => {
                if (isOpen) {
                    node.setAttribute('inert', '');
                    node.setAttribute('aria-hidden', 'true');
                } else {
                    node.removeAttribute('inert');
                    node.removeAttribute('aria-hidden');
                }
            });
        });
    }

    function getFocusableElements($modal) {
        return $modal
            .find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
            .filter(':visible')
            .filter(function() {
                return !this.disabled;
            });
    }

    function focusModal($modal) {
        const $focusable = getFocusableElements($modal);
        const $autoFocus = $modal.find('[autofocus]').filter(':visible').first();
        const $dialog = $modal.find('.userModal').first();
        if ($autoFocus.length) {
            $autoFocus.focus();
            return;
        }
        if ($focusable.length) {
            $focusable.first().focus();
            return;
        }
        if ($dialog.length) {
            $dialog.attr('tabindex', '-1').focus();
        }
    }

    function findModalConfirm($modal) {
        const selectors = [
            '[data-modal-confirm]',
            'button[type="submit"]',
            'button:not(.userModalClose)'
        ];
        for (let i = 0; i < selectors.length; i += 1) {
            const $candidate = $modal.find(selectors[i]).filter(':visible').filter(function() {
                return !this.disabled;
            });
            if ($candidate.length) {
                return $candidate.first();
            }
        }
        return $();
    }

    function handleModalKeydown(event) {
        if (!activeModal) {
            return;
        }

        const $modal = activeModal;
        if (event.key === 'Escape') {
            event.preventDefault();
            const $cancel = $modal.find('.userModalClose, [data-modal-cancel]').filter(':visible').first();
            if ($cancel.length) {
                $cancel.trigger('click');
            } else {
                closeAdminModal($modal);
            }
            return;
        }

        if (event.key === 'Enter' && !event.isComposing) {
            const target = event.target;
            if (target && (target.tagName === 'TEXTAREA' || target.isContentEditable)) {
                return;
            }
            if (target && target.closest && target.closest('button, a[href], input[type="submit"], input[type="button"]')) {
                return;
            }
            const $confirm = findModalConfirm($modal);
            if ($confirm.length) {
                event.preventDefault();
                $confirm.trigger('click');
            }
            return;
        }

        if (event.key === 'Tab') {
            const $focusable = getFocusableElements($modal);
            if (!$focusable.length) {
                event.preventDefault();
                return;
            }
            const first = $focusable.first().get(0);
            const last = $focusable.last().get(0);
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    }

    function openAdminModal($modal) {
        if (!$modal || !$modal.length) {
            return;
        }
        const modalEl = $modal.get(0);
        if (modalStack.length === 0) {
            lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        }
        if (!modalStack.includes(modalEl)) {
            modalStack.push(modalEl);
        }
        activeModal = $modal;
        $modal.addClass('isOpen').attr('aria-hidden', 'false');
        if (modalStack.length === 1) {
            setModalBackgroundState(true);
        }
        focusModal($modal);
        $(document).off('keydown.adminModal').on('keydown.adminModal', handleModalKeydown);
    }

    function closeAdminModal($modal) {
        const $target = $modal && $modal.length ? $modal : activeModal;
        if (!$target || !$target.length) {
            return;
        }
        const targetEl = $target.get(0);
        $target.removeClass('isOpen').attr('aria-hidden', 'true');
        const index = modalStack.indexOf(targetEl);
        if (index !== -1) {
            modalStack.splice(index, 1);
        }
        if (modalStack.length === 0) {
            activeModal = null;
            setModalBackgroundState(false);
            $(document).off('keydown.adminModal');
            if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
                lastFocusedElement.focus();
            }
            lastFocusedElement = null;
        } else {
            const nextModalEl = modalStack[modalStack.length - 1];
            activeModal = $(nextModalEl);
            focusModal(activeModal);
        }
    }

    // Bind Help button
    $('.helpTutorial').on('click', function() {
        startTutorial();
    });

    bindLinksControls();
    bindBackgroundControls();
    bindSaveHandler();
    bindUserActions();
    bindPaneManagement();
    bindMigrationFlow();
    bindEventListEditors();
    bindMarkdownToolbars();
    bindHeadlineEditingMode();
    bindBackgroundEditingMode();
    applySiteEditPermissions();
    bindAdminNotices();
    $('.userModalOverlay.isOpen').each(function() {
        openAdminModal($(this));
    });

    // Bind tutorial controls
    $('.tutorialNext').on('click', function() {
        goToStep(currentStep + 1);
    });
    $('.tutorialPrev').on('click', function() {
        goToStep(currentStep - 1);
    });
    $('.tutorialClose').on('click', function() {
        endTutorial();
    });

    bindLogoUploader();
    initialSnapshot = captureSnapshot();
    refreshBackgrounds(true);

    function startTutorial() {
        currentStep = 0;
        $('#tutorialOverlay').removeClass('hidden');
        goToStep(currentStep);
    }

    function endTutorial() {
        if (currentStep !== null && steps[currentStep] && typeof steps[currentStep].onAfter === 'function') {
            steps[currentStep].onAfter();
        }
        $('#tutorialOverlay').addClass('hidden');
        resetMask();
    }

    function goToStep(index) {
        // Allow a step to clean up before switching away (e.g., close modals).
        if (currentStep !== null && steps[currentStep] && typeof steps[currentStep].onAfter === 'function') {
            steps[currentStep].onAfter();
        }
        if (index < 0 || index >= steps.length) {
            endTutorial();
            return;
        }
        currentStep = index;
        const step = steps[currentStep];

        // Run any step-specific setup (like switching panes) before highlighting.
        if (typeof step.onBefore === 'function') {
            step.onBefore();
        }

        // Update popover content before positioning so size is accurate.
        updatePopover(step.text);

        const $target = $(step.selector).first();
        if ($target.length === 0) {
            // If target missing, skip to next.
            goToStep(currentStep + 1);
            return;
        }

        const rect = highlightTarget($target);
        updateControls();
        positionPopover(rect);
    }

    function updatePopover(text) {
        $('.tutorialText').text(text);
    }

    function updateControls() {
        $('.tutorialPrev').prop('disabled', currentStep === 0);
        $('.tutorialNext').text(currentStep === steps.length - 1 ? 'Finish' : 'Next');
    }

    function highlightTarget($el) {
        const rect = $el[0].getBoundingClientRect();
        const padding = 8;
        const top = rect.top + window.scrollY - padding;
        const left = rect.left + window.scrollX - padding;
        const width = rect.width + padding * 2;
        const height = rect.height + padding * 2;
        const right = left + width;
        const bottom = top + height;

        positionMask(top, left, right, bottom);
        return rect;
    }

    function positionMask(top, left, right, bottom) {
        const docHeight = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
        const docWidth = Math.max(document.body.scrollWidth, document.documentElement.scrollWidth);

        $('#mask-top').css({ top: 0, left: 0, width: '100%', height: top });
        $('#mask-left').css({ top: top, left: 0, width: left, height: bottom - top });
        $('#mask-right').css({ top: top, left: right, width: docWidth - right, height: bottom - top });
        $('#mask-bottom').css({ top: bottom, left: 0, width: '100%', height: docHeight - bottom });
    }

    function positionPopover(rect) {
        const $popover = $('#tutorialPopover');
        const margin = 12;
        const viewportWidth = $(window).width();
        const viewportHeight = $(window).height();

        let top = rect.bottom + margin + window.scrollY;
        let left = rect.left + window.scrollX;

        if (top + $popover.outerHeight() > viewportHeight + window.scrollY) {
            top = rect.top + window.scrollY - $popover.outerHeight() - margin;
        }
        if (left + $popover.outerWidth() > viewportWidth + window.scrollX) {
            left = viewportWidth + window.scrollX - $popover.outerWidth() - margin;
        }

        // Prevent overflow beyond the viewport bounds.
        if (top < window.scrollY + margin) {
            top = window.scrollY + margin;
        }
        if (left < window.scrollX + margin) {
            left = window.scrollX + margin;
        }
        const maxTop = window.scrollY + viewportHeight - $popover.outerHeight() - margin;
        if (top > maxTop) {
            top = Math.max(window.scrollY + margin, maxTop);
        }
        const maxLeft = window.scrollX + viewportWidth - $popover.outerWidth() - margin;
        if (left > maxLeft) {
            left = Math.max(window.scrollX + margin, maxLeft);
        }

        $popover.css({ top: top, left: left });
    }

    function resetMask() {
        $('#mask-top, #mask-left, #mask-right, #mask-bottom').attr('style', '');
        $('#tutorialPopover').attr('style', '');
    }

    // Link list interactions (add, delete, reorder)
    function bindLinksControls() {
        const $list = $('.linksConfigList');

        // Move up
        $list.on('click', '.moveUpLink', function() {
            const $card = $(this).closest('.linksConfigCard');
            const $prev = $card.prev('.linksConfigCard');
            if ($prev.length) {
                $card.insertBefore($prev);
                refreshLinkControls();
            }
        });

        // Move down
        $list.on('click', '.moveDownLink', function() {
            const $card = $(this).closest('.linksConfigCard');
            const $next = $card.next('.linksConfigCard');
            if ($next.length) {
                $card.insertAfter($next);
                refreshLinkControls();
            }
        });

        // Delete
        $list.on('click', '.deleteLink', function() {
            $(this).closest('.linksConfigCard').remove();
            refreshLinkControls();
        });

        // Add link
        $('.addLink').on('click', function() {
            const $newCard = $(createLinkCard());
            $list.append($newCard);
            updateLinkIdForCard($newCard);
            refreshLinkControls();
            scrollListToBottom($list);
        });

        // Add separator
        $('.addSeparator').on('click', function() {
            const $newCard = $(createSeparatorCard());
            $list.append($newCard);
            refreshLinkControls();
            scrollListToBottom($list);
        });

        // Initial state
        refreshLinkControls();

        $list.on('input', 'input[name="linkText[]"]', function() {
            updateLinkIdForCard($(this).closest('.linksConfigCard'));
        });

        $list.find('.linksConfigCard').not('.linksConfigSeparator').each(function() {
            updateLinkIdForCard($(this));
        });

        bindLinksEditingMode();
    }

    function refreshLinkControls() {
        const $cards = $('.linksConfigCard');
        $cards.find('.moveUpLink, .moveDownLink').prop('disabled', false);
        if ($cards.length === 0) {
            return;
        }
        $cards.first().find('.moveUpLink').prop('disabled', true);
        $cards.last().find('.moveDownLink').prop('disabled', true);
        if ($cards.length === 1) {
            $cards.first().find('.moveDownLink').prop('disabled', true);
        }
    }

    function buildLinkIdFromText(text) {
        const words = (text || '').match(/[a-z0-9]+/gi) || [];
        if (!words.length) {
            return '';
        }
        const pascal = words
            .map((word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
            .join('');
        return `link${pascal}`;
    }

    function updateLinkIdForCard($card) {
        if (!$card.length || $card.hasClass('linksConfigSeparator')) {
            return;
        }
        const $textInput = $card.find('input[name="linkText[]"]');
        const $idInput = $card.find('input[name="linkId[]"]');
        const $idValue = $card.find('.linksConfigIdValue');
        const textValue = $textInput.val() || '';
        const generated = buildLinkIdFromText(textValue);
        const nextId = generated || ($idInput.val() || '');
        $idInput.val(nextId);
        $idValue.text(`#${nextId}`);
        $idValue.attr('aria-label', `ID ${nextId}`);
        updateReservedIdState($idInput);
    }

    function bindLinksEditingMode() {
        const selector = '.linksConfigInput[name="linkText[]"], .linksConfigInput[name="linkUrl[]"], .linksConfigInput[name="linkTitle[]"], .linksConfigIdValue';
        $(document).on('focus', selector, function() {
            const $input = $(this);
            const $row = $input.closest('.linksConfigRow');
            const $field = $input.closest('.linksConfigField');
            $row.addClass('isEditing');
            $row.find('.linksConfigField').removeClass('isEditing');
            $field.addClass('isEditing');
        });
        $(document).on('blur', selector, function() {
            const $input = $(this);
            const $row = $input.closest('.linksConfigRow');
            setTimeout(function() {
                if (!$row.find(selector).is(':focus')) {
                    $row.removeClass('isEditing');
                    $row.find('.linksConfigField').removeClass('isEditing');
                }
            }, 0);
        });
    }

    function createLinkCard() {
        linkCounter += 1;
        const uniqueId = `link${linkCounter}`;
        return `
            <div class="linksConfigCard">
                <div class="linksConfigRow">
                    <label class="linksConfigField" title="The label that is displayed for each link."><span class="linksConfigLabelText">Name</span>
                        <input class="linksConfigInput" type="text" name="linkText[]" value="" placeholder="Display text" title="The label that is displayed for each link.">
                    </label>
                    <div class="linksConfigField linksConfigIdField" title="The internal HTML ID of the link.  Make it unique.">
                        <span class="linksConfigLabelText">ID</span>
                        <span class="linksConfigIdValue" tabindex="0" aria-label="ID ${uniqueId}">#${uniqueId}</span>
                        <input class="linksConfigIdInput" type="hidden" name="linkId[]" value="${uniqueId}">
                    </div>
                </div>
                <div class="linksConfigRow">
                    <label class="linksConfigField" title="The full URL (https: and all) to link to."><span class="linksConfigLabelText">URL</span>
                        <input class="linksConfigInput" type="text" name="linkUrl[]" value="" placeholder="Link URL" title="The full URL (https: and all) to link to.">
                    </label>
                    <label class="linksConfigField" title="The text that appears when the user hovers over a link."><span class="linksConfigLabelText">Tooltip</span>
                        <input class="linksConfigInput" type="text" name="linkTitle[]" value="" placeholder="Title attribute" title="The text that appears when the user hovers over a link.">
                    </label>
                </div>
                <div class="linksConfigRow linksConfigToggles">
                    <label class="linksConfigCheckbox" title="If checked, the link takes up the full width of the links pane.  Otherwise, it'll take up half of the width.">
                        <input type="checkbox" name="linkFullWidth[]" title="If checked, the link takes up the full width of the links pane.  Otherwise, it'll take up half of the width.">
                        Full width
                    </label>
                    <label class="linksConfigCheckbox" title="AKA Call to Action.  If checked, the link appears more prominently than the others.  Ideally, you will only want to use one, but you can set multiple links as a CTA button.">
                        <input type="checkbox" name="linkCta[]" title="AKA Call to Action.  If checked, the link appears more prominently than the others.  Ideally, you will only want to use one, but you can set multiple links as a CTA button.">
                        CTA
                    </label>
                    <span class="linksConfigSpacer"></span>
                    <button class="moveUpLink iconButton" type="button" title="Move this entry up in the list." aria-label="Move this entry up in the list.">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M13,20H11V8L5.5,13.5L4.08,12.08L12,4.16L19.92,12.08L18.5,13.5L13,8V20Z" /></svg>
                    </button>
                    <button class="moveDownLink iconButton" type="button" title="Move this entry down in the list." aria-label="Move this entry down in the list.">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M11,4H13V16L18.5,10.5L19.92,11.92L12,19.84L4.08,11.92L5.5,10.5L11,16V4Z" /></svg>
                    </button>
                    <button class="deleteLink usersDanger iconButton" type="button" title="Removes this entry from the list." aria-label="Remove this entry from the list.">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z" /></svg>
                    </button>
                </div>
            </div>
        `;
    }

    function createSeparatorCard() {
        return `
            <div class="linksConfigCard linksConfigSeparator">
                <div class="linksConfigRow">
                    <span class="linksConfigLabel">Separator</span>
                    <span class="linksConfigSpacer"></span>
                    <button class="moveUpLink iconButton" type="button" title="Move this entry up in the list." aria-label="Move this entry up in the list.">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M13,20H11V8L5.5,13.5L4.08,12.08L12,4.16L19.92,12.08L18.5,13.5L13,8V20Z" /></svg>
                    </button>
                    <button class="moveDownLink iconButton" type="button" title="Move this entry down in the list." aria-label="Move this entry down in the list.">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M11,4H13V16L18.5,10.5L19.92,11.92L12,19.84L4.08,11.92L5.5,10.5L11,16V4Z" /></svg>
                    </button>
                    <button class="deleteLink usersDanger iconButton" type="button" title="Removes this entry from the list." aria-label="Remove this entry from the list.">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z" /></svg>
                    </button>
                </div>
            </div>
        `;
    }

    function scrollListToBottom($list) {
        $list.scrollTop($list[0].scrollHeight);
    }

    // Logo upload/preview logic (in-memory until saved).
    function bindLogoUploader() {
        const $logo = $('#logo');
        const $logoInput = $('#logoFileInput');

        $('.logoChange').on('click', function() {
            $logoInput.trigger('click');
        });

        $logoInput.on('change', function() {
            const file = this.files && this.files[0] ? this.files[0] : null;
            if (file) {
                previewLogoFile(file);
            }
            // Reset input so selecting the same file again still triggers change.
            $(this).val('');
        });

        $logo.on('dragover dragenter', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $logo.addClass('dragOver');
        });

        $logo.on('dragleave dragend', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $logo.removeClass('dragOver');
        });

        $logo.on('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $logo.removeClass('dragOver');
            const file = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files
                ? e.originalEvent.dataTransfer.files[0]
                : null;
            if (file) {
                previewLogoFile(file);
            }
        });
    }

    function previewLogoFile(file) {
        if (!file || !(file.type || '').startsWith('image/')) {
            return;
        }
        pendingLogoFile = file;

        const reader = new FileReader();
        reader.onload = function(ev) {
            $('#logo').css('background-image', `url('${ev.target.result}')`);
        };
        reader.readAsDataURL(file);
    }

    // Background image interactions (change, add, delete)
    function bindBackgroundControls() {
        const $bgList = $('#bgConfig');
        const $bgFileInput = $('#bgFileInput');

        // Change button
        $bgList.on('click', '.bgChange', function() {
            $bgFileInput.trigger('click');
        });

        // File input change
        $bgFileInput.on('change', function() {
            const file = this.files && this.files[0] ? this.files[0] : null;
            if (file) {
                uploadBackgroundFile(file);
            }
            $(this).val('');
        });

        // Drag/drop
        $bgList.on('dragover dragenter', '.bgThumbWrap', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('dragOver');
        });
        $bgList.on('dragleave dragend', '.bgThumbWrap', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragOver');
        });
        $bgList.on('drop', '.bgThumbWrap', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragOver');
            const file = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files
                ? e.originalEvent.dataTransfer.files[0]
                : null;
            if (file) {
                uploadBackgroundFile(file);
            }
        });

        // Delete
        $bgList.on('click', '.deleteBackground', function() {
            const $row = $(this).closest('.bgConfigRow');
            const url = $row.data('current-url') || '';
            const index = Number($row.data('index'));
            if (!url) {
                return;
            }
            pendingBgDelete = {
                url,
                index: Number.isFinite(index) ? index : null
            };
            openBgDeleteModal();
        });

        // Add new background
        $('.addBackground').on('click', function() {
            $bgFileInput.trigger('click');
        });

        $(document).on('click', '#bgDeleteConfirm', function() {
            if (pendingBgDelete) {
                deleteBackground(pendingBgDelete.url, pendingBgDelete.index);
            }
            pendingBgDelete = null;
            closeBgDeleteModal();
        });

        $(document).on('click', '#bgDeleteModal .userModalClose', function() {
            pendingBgDelete = null;
            closeBgDeleteModal();
        });
    }

    function uploadBackgroundFile(file) {
        if (!file || !(file.type || '').startsWith('image/')) {
            return;
        }
        const uploadUrl = buildUrl('backgrounds-upload.php');
        const formData = new FormData();
        formData.append('bgFile', file);
        appendCsrf(formData);

        showSavingOverlay();
        fetch(uploadUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, status: response.status, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    const message = data && data.error ? data.error : 'Background upload failed.';
                    addAdminNotice('danger', message);
                    hideSavingOverlay();
                    return;
                }
                renderBackgrounds(data.backgrounds || []);
                initialSnapshot.backgrounds = getBackgroundsData();
                addAdminNotice('ok', 'Background uploaded.');
                hideSavingOverlay();
            })
            .catch((error) => {
                console.error('Background upload failed', error);
                addAdminNotice('danger', 'Background upload failed. Please try again.');
                hideSavingOverlay();
            });
    }

    function scrollBgListToBottom() {
        const $bgList = $('#bgConfig');
        $bgList.scrollTop($bgList[0].scrollHeight);
    }

    // Save handler: gather data and POST to save endpoint.
    function bindSaveHandler() {
        const basePath = window.appConfig && typeof window.appConfig.basePath === 'string'
            ? window.appConfig.basePath.replace(/\/$/, '')
            : '';
        const saveUrl = basePath ? `${basePath}/res/scr/save-config.php` : '/res/scr/save-config.php';

        $('.saveChanges').on('click', function() {
            const formData = new FormData();
            appendCsrf(formData);
            const currentSnapshot = captureSnapshot();
            let hasChanges = false;
            const reservedIds = findReservedLinkIds();
            if (reservedIds.length > 0) {
                addAdminNotice('danger', `Error: ID cannot be ${reservedIds.join(', ')}. Please change them to different IDs.`);
                return;
            }

            // Header text
            if (!isEqualSnapshot(currentSnapshot.header, initialSnapshot.header)) {
                formData.append('siteTitle', currentSnapshot.header.title || '');
                formData.append('siteSubtitle', currentSnapshot.header.subtitle || '');
                hasChanges = true;
            }

            // Logo file (optional)
            if (pendingLogoFile) {
                formData.append('logoFile', pendingLogoFile);
                hasChanges = true;
            }

            // Links
            if (!isEqualSnapshot(currentSnapshot.links, initialSnapshot.links)) {
                formData.append('links', JSON.stringify(currentSnapshot.links));
                hasChanges = true;
            }

            // Backgrounds
            const authorChanges = getBackgroundAuthorChanges(currentSnapshot.backgrounds, initialSnapshot.backgrounds);
            if (authorChanges.length > 0) {
                formData.append('backgroundAuthors', JSON.stringify(authorChanges));
                hasChanges = true;
            }

            // Pane data (save_map entries)
            if (appendPaneChanges(formData, currentSnapshot.panes, initialSnapshot.panes)) {
                hasChanges = true;
            }

            if (!hasChanges) {
                addAdminNotice('warning', 'No changes to save.');
                return;
            }

            if (!validateEventLists()) {
                addAdminNotice('danger', 'Please fix event list errors before saving.');
                return;
            }

            showSavingOverlay();
            $.ajax({
                url: saveUrl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(resp) {
                    console.log('Save successful', resp);
                    addAdminNotice('ok', 'Changes saved.');
                    initialSnapshot = captureSnapshot();
                    if (typeof window.refreshEventListUIs === 'function') {
                        window.refreshEventListUIs();
                        addAdminNotice('ok', 'Event list re-sorted.');
                    }
                    pendingLogoFile = null;
                    hideSavingOverlay();
                },
                error: function(xhr) {
                    const responseText = xhr && xhr.responseText ? xhr.responseText : '';
                    if (xhr && xhr.status === 403) {
                        addAdminNotice('danger', 'You do not have permission to edit site content.');
                        hideSavingOverlay();
                        return;
                    }
                    let message = 'Save failed. Please try again.';
                    if (responseText) {
                        try {
                            const parsed = JSON.parse(responseText);
                            if (parsed && parsed.error) {
                                message = parsed.error;
                            }
                        } catch (err) {
                            message = responseText;
                        }
                    }
                    console.error('Save failed', responseText);
                    addAdminNotice('danger', message);
                    hideSavingOverlay();
                }
            });
        });
    }

    function getHeaderData() {
        return {
            title: $('.headlineInput[name="siteTitle"]').val() || '',
            subtitle: $('.headlineInput[name="siteSubtitle"]').val() || ''
        };
    }

    function getLinksData() {
        const links = [];
        $('.linksConfigCard').each(function() {
            const $card = $(this);
            if ($card.hasClass('linksConfigSeparator')) {
                links.push({ type: 'separator' });
                return;
            }
            const id = $card.find('input[name="linkId[]"]').val() || '';
            const href = $card.find('input[name="linkUrl[]"]').val() || '';
            const text = $card.find('input[name="linkText[]"]').val() || '';
            const title = $card.find('input[name="linkTitle[]"]').val() || '';
            const fullWidth = $card.find('input[name="linkFullWidth[]"]').is(':checked');
            const cta = $card.find('input[name="linkCta[]"]').is(':checked');
            links.push({
                type: 'link',
                id,
                href,
                text,
                title,
                fullWidth,
                cta
            });
        });
        return links;
    }

    function getBackgroundsData() {
        const backgrounds = [];
        $('.bgConfigRow').not('.bgConfigHeader').each(function() {
            const $row = $(this);
            const author = $row.find('.bgAuthorInput').val() || '';
            const authorUrl = $row.find('.bgAuthorUrlInput').val() || '';
            const currentUrl = $row.data('current-url') || $row.find('.bgThumb').attr('src') || '';
            const index = Number($row.data('index'));
            if (currentUrl) {
                backgrounds.push({
                    url: currentUrl,
                    author,
                    authorUrl: authorUrl || '',
                    index: Number.isFinite(index) ? index : backgrounds.length
                });
            }
        });
        return backgrounds;
    }

    // Collect pane inputs for Save All using pane[<id>][<key>] naming.
    function getPaneSaveData() {
        const data = {};
        $('#container')
            .find('input, textarea, select, button')
            .each(function() {
                const $field = $(this);
                const name = $field.attr('name') || '';
                if (!name || name.indexOf('pane[') !== 0) {
                    return;
                }
                const inner = name.slice(5, -1);
                const parts = inner.split('][');
                if (parts.length !== 2) {
                    return;
                }
                const paneId = parts[0];
                const key = parts[1];
                if (!paneId || !key) {
                    return;
                }
                if (!data[paneId]) {
                    data[paneId] = {};
                }
                let value = $field.val();
                if ($field.is(':checkbox')) {
                    value = $field.is(':checked') ? '1' : '';
                }
                data[paneId][key] = value == null ? '' : value;
            });
        return data;
    }

    function appendPaneChanges(formData, current, initial) {
        let changed = false;
        const paneIds = new Set(Object.keys(current || {}));
        for (const paneId of paneIds) {
            const currentKeys = current[paneId] || {};
            const initialKeys = (initial && initial[paneId]) ? initial[paneId] : {};
            for (const key of Object.keys(currentKeys)) {
                const currentValue = currentKeys[key];
                const initialValue = initialKeys[key];
                if (currentValue !== initialValue) {
                    formData.append(`pane[${paneId}][${key}]`, currentValue == null ? '' : currentValue);
                    changed = true;
                }
            }
        }
        return changed;
    }

    function captureSnapshot() {
        return {
            header: getHeaderData(),
            links: getLinksData(),
            backgrounds: getBackgroundsData(),
            panes: getPaneSaveData()
        };
    }

    function isEndBeforeStart(startDate, startTime, endDate, endTime) {
        if (!startDate || !startTime || !endDate || !endTime) {
            return false;
        }
        const startKey = `${startDate} ${startTime}`;
        const endKey = `${endDate} ${endTime}`;
        return endKey < startKey;
    }

    // Validate all event list panes and show inline errors.
    function validateEventLists() {
        let isValid = true;
        $('[data-pane-type="eventList"]').each(function() {
            const $pane = $(this);
            const paneId = $pane.data('pane-id') || $pane.attr('id') || '';
            const payloadField = $pane.find('.eventListPayload');
            if (!payloadField.length) {
                return;
            }
            let parsed = null;
            try {
                parsed = JSON.parse(payloadField.val() || '{}');
            } catch (err) {
                isValid = false;
                return;
            }
            const events = Array.isArray(parsed.events) ? parsed.events : [];
            const seen = new Set();
            events.forEach((event, idx) => {
                const $card = $pane.find('.eventCard').eq(idx);
                const $message = $card.find('.eventValidation');
                if (!$message.length) {
                    return;
                }
                $message.text('');
                if (!event || typeof event !== 'object') {
                    $message.text('Invalid event data.');
                    isValid = false;
                    return;
                }
                const errors = [];
                if (!event.name) {
                    errors.push('Name is required.');
                }
                if (!event.startDate) {
                    errors.push('Start date is required.');
                }
                if (!event.startTime) {
                    errors.push('Start time is required.');
                }
                if ((event.endDate && !event.endTime) || (!event.endDate && event.endTime)) {
                    errors.push('End date and time must both be set or both be blank.');
                }
                if (event.endDate && event.endTime && isEndBeforeStart(event.startDate, event.startTime, event.endDate, event.endTime)) {
                    errors.push('End date/time cannot be earlier than start date/time.');
                }
                if (!event.address) {
                    errors.push('Address is required.');
                }
                if (!event.description) {
                    errors.push('Description is required.');
                }
                const dedupeKey = `${String(event.name || '').toLowerCase()}|${event.startDate || ''}|${event.startTime || ''}`;
                if (event.name && event.startDate && event.startTime) {
                    if (seen.has(dedupeKey)) {
                        errors.push('Duplicate event (name + date + time).');
                    } else {
                        seen.add(dedupeKey);
                    }
                }
                if (errors.length) {
                    $message.text(errors.join(' '));
                    isValid = false;
                }
            });
        });
        return isValid;
    }

    function isEqualSnapshot(a, b) {
        return JSON.stringify(a) === JSON.stringify(b);
    }

    function findReservedLinkIds() {
        const reservedIds = getReservedIdSet();
        const ids = [];
        $('.linksConfigCard').not('.linksConfigSeparator').each(function() {
            const value = $(this).find('input[name="linkId[]"]').val() || '';
            const trimmed = value.trim();
            if (trimmed) {
                ids.push(trimmed);
            }
        });

        const offenders = [];
        const seen = new Set();
        for (const id of ids) {
            const lowered = id.toLowerCase();
            if (reservedIds.has(lowered) && !seen.has(lowered)) {
                offenders.push(id);
                seen.add(lowered);
            }
        }
        return offenders;
    }

    function updateReservedIdState($input) {
        const value = ($input.val() || '').trim().toLowerCase();
        const $card = $input.closest('.linksConfigCard');
        const $display = $card.find('.linksConfigIdValue');
        if (!value) {
            $input.removeClass('isReserved');
            $display.removeClass('isReserved');
            return;
        }
        const reservedIds = getReservedIdSet();
        if (reservedIds.has(value)) {
            $input.addClass('isReserved');
            $display.addClass('isReserved');
            return;
        }
        $input.removeClass('isReserved');
        $display.removeClass('isReserved');
    }

    function getReservedIdSet() {
        const staticIds = [
            'adminnotices',
            'bg',
            'bgconfig',
            'bgdeleteconfirm',
            'bgdeletemodal',
            'bgfileinput',
            'container',
            'header',
            'links',
            'linksconfig',
            'linklist',
            'logo',
            'logofileinput',
            'mask-bottom',
            'mask-left',
            'mask-right',
            'mask-top',
            'nojswarning',
            'navbar',
            'permissionsmodal',
            'permissionsform',
            'permissionsselfconfirmmodal',
            'permissionsselfconfirmyes',
            'permissionsusername',
            'removeusermodal',
            'removeuserwarning',
            'removeusername',
            'resetconfirmmodal',
            'resetconfirmmessage',
            'resetconfirmyes',
            'resetpasswordmodal',
            'savingoverlay',
            'tutorialoverlay',
            'tutorialpopover',
            'users'
        ];
        const paneIds = window.appConfig && Array.isArray(window.appConfig.paneIds)
            ? window.appConfig.paneIds
            : [];
        const combined = staticIds.concat(paneIds.map((value) => String(value).toLowerCase()));
        return new Set(combined);
    }

    function getBackgroundAuthorChanges(current, initial) {
        const changes = [];
        if (!Array.isArray(current) || !Array.isArray(initial)) {
            return changes;
        }
        current.forEach((bg, idx) => {
            const initialBg = initial[idx];
            if (!initialBg) {
                return;
            }
            const url = bg.url || '';
            const author = bg.author || '';
            const authorUrl = bg.authorUrl || '';
            if (!url || url !== initialBg.url) {
                return;
            }
            if (author !== (initialBg.author || '') || authorUrl !== (initialBg.authorUrl || '')) {
                changes.push({ url, author, authorUrl, index: idx });
            }
        });
        return changes;
    }

    function buildUrl(fileName) {
        const basePath = window.appConfig && typeof window.appConfig.basePath === 'string'
            ? window.appConfig.basePath.replace(/\/$/, '')
            : '';
        return basePath ? `${basePath}/res/scr/${fileName}` : `/res/scr/${fileName}`;
    }

    function refreshBackgrounds(updateSnapshot) {
        const listUrl = buildUrl('backgrounds-list.php');
        fetch(listUrl, {
            method: 'GET',
            credentials: 'same-origin'
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    return;
                }
                renderBackgrounds(data.backgrounds || []);
                if (updateSnapshot) {
                    initialSnapshot.backgrounds = getBackgroundsData();
                }
            })
            .catch((error) => {
                console.error('Failed to load backgrounds', error);
            });
    }

    function renderBackgrounds(backgrounds) {
        const $bgList = $('#bgConfig');
        const $actions = $bgList.find('.bgConfigActions');
        $bgList.find('.bgConfigRow').not('.bgConfigHeader').remove();

        if (!Array.isArray(backgrounds)) {
            return;
        }

        backgrounds.forEach((bg, index) => {
            const url = bg && typeof bg.url === 'string' ? bg.url : '';
            const displayUrl = bg && typeof bg.displayUrl === 'string' ? bg.displayUrl : url;
            const author = bg && typeof bg.author === 'string' ? bg.author : '';
            const authorUrl = bg && typeof bg.authorUrl === 'string' ? bg.authorUrl : '';
            const isEmpty = !displayUrl;
            const row = `
                <div class="bgConfigRow" data-current-url="${escapeHtml(url)}" data-author-url="${escapeHtml(authorUrl)}" data-index="${index}">
                    <div class="bgThumbWrap ${isEmpty ? 'empty' : ''}">
                        <img class="bgThumb" src="${escapeHtml(displayUrl)}" alt="Background preview">
                        <button class="bgChange" type="button">Change</button>
                    </div>
                    <input class="bgAuthorInput" type="text" name="bgAuthor[]" value="${escapeHtml(author)}" placeholder="Author">
                    <input class="bgAuthorUrlInput" type="text" name="bgAuthorUrl[]" value="${escapeHtml(authorUrl)}" placeholder="URL">
                    <button class="deleteBackground usersDanger iconButton" type="button" aria-label="Delete background" title="Remove this background">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z" /></svg>
                    </button>
                </div>
            `;
            $(row).insertBefore($actions);
        });
    }

    function deleteBackground(url, index) {
        const deleteUrl = buildUrl('backgrounds-delete.php');
        const formData = new FormData();
        formData.append('url', url);
        if (index !== null) {
            formData.append('index', String(index));
        }
        appendCsrf(formData);
        showSavingOverlay();
        fetch(deleteUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    const message = data && data.error ? data.error : 'Background delete failed.';
                    addAdminNotice('danger', message);
                    hideSavingOverlay();
                    return;
                }
                renderBackgrounds(data.backgrounds || []);
                initialSnapshot.backgrounds = getBackgroundsData();
                addAdminNotice('ok', 'Background deleted.');
                hideSavingOverlay();
            })
            .catch((error) => {
                console.error('Background delete failed', error);
                addAdminNotice('danger', 'Background delete failed. Please try again.');
                hideSavingOverlay();
            });
    }

    function openBgDeleteModal() {
        openAdminModal($('#bgDeleteModal'));
    }

    function closeBgDeleteModal() {
        closeAdminModal($('#bgDeleteModal'));
    }

    function bindUserActions() {
        let pendingResetForm = null;
        let pendingPermissionsForm = null;

        $(document).on('click', '.usersPermissionsButton', function() {
            const $row = $(this).closest('.usersRow');
            const username = $row.data('username') || '';
            const permissionsRaw = $row.data('permissions') || '';
            const permissions = permissionsRaw ? permissionsRaw.split(',') : [];

            const $permissionsModal = $('#permissionsModal');
            $permissionsModal.data('current-permissions', permissions);
            $('#permissionsUsername').val(username);
            $permissionsModal.find('input[type="checkbox"]').prop('checked', false);
            permissions.forEach(function(permission) {
                $permissionsModal.find(`input[type="checkbox"][value="${permission}"]`).prop('checked', true);
            });
            applyFullAdminState($permissionsModal);

            openAdminModal($permissionsModal);
        });

        $(document).on('click', '.usersRemoveButton', function() {
            const $row = $(this).closest('.usersRow');
            const username = $row.data('username') || '';
            $('#removeUsername').val(username);
            const $removeModal = $('#removeUserModal');
            const currentUser = window.appConfig && window.appConfig.currentUser ? window.appConfig.currentUser : '';
            const warningBase = 'WARNING: Clicking Delete will permanently remove this account. This cannot be reversed!';
            const warningSuffix = username && currentUser && username === currentUser
                ? ' You will be logged out.'
                : '';
            $('#removeUserWarning').text(warningBase + warningSuffix);
            openAdminModal($removeModal);
        });

        $(document).on('click', '.userModalClose', function() {
            closeAdminModal($(this).closest('.userModalOverlay'));
        });

        $(document).on('change', '#permissionsModal input[type="checkbox"][value="full_admin"]', function() {
            applyFullAdminState($('#permissionsModal'));
        });

        $(document).on('submit', '.usersCreateForm, #permissionsForm, #removeUserForm', function(event) {
            if (this.id === 'permissionsForm') {
                const currentUser = window.appConfig && window.appConfig.currentUser ? window.appConfig.currentUser : '';
                const targetUser = $('#permissionsUsername').val() || '';
                if (currentUser && targetUser && currentUser === targetUser) {
                    const currentPerms = $('#permissionsModal').data('current-permissions') || [];
                    const selectedPerms = $('#permissionsModal').find('input[type="checkbox"]:checked').map(function() {
                        return $(this).val();
                    }).get();
                    const added = selectedPerms.filter(function(permission) {
                        return !currentPerms.includes(permission);
                    });
                    const removed = currentPerms.filter(function(permission) {
                        return !selectedPerms.includes(permission);
                    });
                    const canEditUsers = $('.usersActions .usersPermissionsButton').is(':enabled');
                    if (added.length > 0 && !canEditUsers) {
                        event.preventDefault();
                        addAdminNotice('danger', 'You cannot add your own permissions.');
                        return;
                    }
                    if (removed.length > 0) {
                        event.preventDefault();
                        pendingPermissionsForm = this;
                        openAdminModal($('#permissionsSelfConfirmModal'));
                        return;
                    }
                }
            }
            event.preventDefault();
            submitUsersForm(this);
        });

        $(document).on('submit', '.usersResetForm', function(event) {
            const $form = $(this);
            const targetUser = $form.data('username') || '';
            const currentUser = window.appConfig && window.appConfig.currentUser ? window.appConfig.currentUser : '';
            event.preventDefault();
            pendingResetForm = this;
            const baseMessage = targetUser
                ? `Are you sure you want to reset the password for ${targetUser}?`
                : 'Are you sure you want to reset this password?';
            const logoutMessage = targetUser && currentUser && targetUser === currentUser
                ? ' This will log you out.'
                : '';
            $('#resetConfirmMessage').text(baseMessage + logoutMessage);
            openAdminModal($('#resetConfirmModal'));
        });

        $(document).on('click', '#resetConfirmYes', function() {
            if (pendingResetForm) {
                submitUsersForm(pendingResetForm);
                pendingResetForm = null;
            }
            closeAdminModal($('#resetConfirmModal'));
        });

        $(document).on('click', '#resetConfirmModal .userModalClose', function() {
            pendingResetForm = null;
            closeAdminModal($('#resetConfirmModal'));
        });

        $(document).on('click', '#permissionsSelfConfirmYes', function() {
            if (pendingPermissionsForm) {
                submitUsersForm(pendingPermissionsForm);
                pendingPermissionsForm = null;
            }
            closeAdminModal($('#permissionsSelfConfirmModal'));
        });

        $(document).on('click', '#permissionsSelfConfirmModal .userModalClose', function() {
            pendingPermissionsForm = null;
            closeAdminModal($('#permissionsSelfConfirmModal'));
        });
    }

    function applyFullAdminState($permissionsModal) {
        const $fullAdmin = $permissionsModal.find('input[type="checkbox"][value="full_admin"]');
        if ($fullAdmin.prop('disabled')) {
            return;
        }
        const isFullAdmin = $fullAdmin.is(':checked');
        const $otherItems = $permissionsModal.find('.permissionsItem').filter(function() {
            return $(this).find('input[type="checkbox"]').val() !== 'full_admin';
        });
        const $otherCheckboxes = $otherItems.find('input[type="checkbox"]');

        if (isFullAdmin) {
            $otherCheckboxes.prop('checked', true).prop('disabled', true);
            $otherItems.addClass('isDisabled');
        } else {
            $otherCheckboxes.prop('disabled', false);
            $otherItems.removeClass('isDisabled');
        }
    }

    function submitUsersForm(form) {
        const formData = new FormData(form);
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then((response) => response.text().then((text) => ({ text, url: response.url, status: response.status })))
            .then(({ text, url, status }) => {
                const doc = new DOMParser().parseFromString(text, 'text/html');
                const usersPane = doc.querySelector('#users');
                const resetModal = doc.querySelector('#resetPasswordModal');
                const permissionsModal = doc.querySelector('#permissionsModal');
                const removeModal = doc.querySelector('#removeUserModal');
                const resetConfirmModal = doc.querySelector('#resetConfirmModal');
                const permissionsSelfConfirmModal = doc.querySelector('#permissionsSelfConfirmModal');
                const notices = doc.querySelector('#adminNotices');

                if (!usersPane) {
                    window.location.href = url;
                    return;
                }

                const currentUsersPane = document.querySelector('#users');
                if (currentUsersPane) {
                    currentUsersPane.replaceWith(usersPane);
                }
                if (notices) {
                    const currentNotices = document.querySelector('#adminNotices');
                    if (currentNotices) {
                        currentNotices.replaceWith(notices);
                    }
                }
                if (resetModal) {
                    $('#resetPasswordModal').replaceWith(resetModal);
                }
                if (permissionsModal) {
                    $('#permissionsModal').replaceWith(permissionsModal);
                }
                if (removeModal) {
                    $('#removeUserModal').replaceWith(removeModal);
                }
                if (resetConfirmModal) {
                    $('#resetConfirmModal').replaceWith(resetConfirmModal);
                }
                if (permissionsSelfConfirmModal) {
                    $('#permissionsSelfConfirmModal').replaceWith(permissionsSelfConfirmModal);
                }

                bindAdminNotices();

                const $newPermissionsModal = $('#permissionsModal');
                applyFullAdminState($newPermissionsModal);
                updateEditSitePermissionFromUsers();
                applySiteEditPermissions();
                $('#permissionsModal').removeClass('isOpen').attr('aria-hidden', 'true');
                $('#removeUserModal').removeClass('isOpen').attr('aria-hidden', 'true');
                $('#resetConfirmModal').removeClass('isOpen').attr('aria-hidden', 'true');
                $('#permissionsSelfConfirmModal').removeClass('isOpen').attr('aria-hidden', 'true');

                if (status === 401 || status === 403) {
                    if (!document.querySelector('#adminNotices .adminNotice--danger')) {
                        addAdminNotice('danger', 'You do not have permission to perform this action.');
                    }
                }
            })
            .catch((error) => {
                console.error('User action failed', error);
                addAdminNotice('danger', 'Action failed. Please try again.');
        });
    }

    // Pane management UI: reorder, add, rename, delete, change type, and edit icons.
    function bindPaneManagement() {
        const $manageModal = $('#paneManagementModal');
        if (!$manageModal.length) {
            return;
        }
        const moduleCatalog = Array.isArray(window.appConfig && window.appConfig.modules)
            ? window.appConfig.modules
            : [];
        const moduleById = new Map(moduleCatalog.map((module) => [module.id, module]));
        const initialPanes = Array.isArray(window.appConfig && window.appConfig.panes)
            ? window.appConfig.panes
            : [];
        let panesState = initialPanes.map((pane) => ({
            id: pane.id || '',
            name: pane.name || '',
            module: pane.module || '',
            icon: pane.icon || { type: 'none', value: '' },
            previousId: pane.id || '',
            previousModule: pane.module || '',
            iconFile: null
        }));
        let activeIconPaneId = null;
        let pendingTypeIndex = null;
        let pendingDeletePaneId = null;
        let pendingAddPane = false;
        let activeIconMode = 'svg';

        const $paneList = $('#paneManageList');
        const $paneTypeModal = $('#paneTypeModal');
        const $paneIconModal = $('#paneIconModal');
        const $paneDeleteModal = $('#paneDeleteConfirmModal');

        // Normalize pane names into camelCase ids (alphanumeric only).
        function normalizePaneId(value) {
            const trimmed = (value || '').trim();
            if (!trimmed) {
                return '';
            }
            const cleaned = trimmed.replace(/[^a-z0-9]+/gi, ' ').trim();
            const parts = cleaned.split(/\s+/).filter(Boolean);
            if (!parts.length) {
                return '';
            }
            const first = parts[0].toLowerCase();
            const rest = parts.slice(1).map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase());
            return [first].concat(rest).join('');
        }

        // Ensure pane IDs are unique by suffixing a numeric counter.
        function uniquePaneId(baseId) {
            if (!baseId) {
                return '';
            }
            let candidate = baseId;
            let counter = 2;
            const existing = new Set(panesState.map((pane) => pane.id));
            while (existing.has(candidate)) {
                candidate = `${baseId}${counter}`;
                counter += 1;
            }
            return candidate;
        }

        function getModuleName(moduleId) {
            const module = moduleById.get(moduleId);
            return module && module.name ? module.name : moduleId;
        }

        // Build HTML for icon preview (SVG string or uploaded file).
        function renderIconPreview(icon) {
            if (!icon || typeof icon !== 'object') {
                return '<span class="paneIconFallback">Icon</span>';
            }
            if (icon.type === 'svg' && icon.value) {
                return icon.value;
            }
            if (icon.type === 'file' && icon.value) {
                const basePath = window.appConfig && typeof window.appConfig.basePath === 'string'
                    ? window.appConfig.basePath.replace(/\/$/, '')
                    : '';
                const src = basePath ? `${basePath}/res/img/panes/${icon.value}` : `/res/img/panes/${icon.value}`;
                return `<img src="${src}" alt="">`;
            }
            return '<span class="paneIconFallback">Icon</span>';
        }

        // Render the editable pane list inside the management modal.
        function renderPaneList() {
            $paneList.empty();
            const moveUpIcon = $('.linksConfig .moveUpLink').first().html() || 'Up';
            const moveDownIcon = $('.linksConfig .moveDownLink').first().html() || 'Down';
            const deleteIcon = $('.linksConfig .deleteLink').first().html() || 'Delete';

            panesState.forEach((pane, index) => {
                const $row = $(`
                    <div class="paneManageRow" data-pane-index="${index}">
                        <div class="paneManageTop">
                            <button class="paneManageIconButton paneIconButton" type="button" aria-label="Edit pane icon"></button>
                            <input class="paneManageName" type="text" value="">
                        </div>
                        <div class="paneManageBottom">
                            <button class="paneManageTypeButton usersButton" type="button"></button>
                            <div class="paneManageRowActions">
                                <button class="moveUpLink iconButton" type="button" title="Move up" aria-label="Move up">${moveUpIcon}</button>
                                <button class="moveDownLink iconButton" type="button" title="Move down" aria-label="Move down">${moveDownIcon}</button>
                                <button class="deleteLink paneManageDelete iconButton" type="button" title="Remove pane" aria-label="Remove pane">${deleteIcon}</button>
                            </div>
                        </div>
                    </div>
                `);
                $row.find('.paneManageIconButton').html(`<span class="paneIconPreview">${renderIconPreview(pane.icon)}</span>`);
                $row.find('.paneManageName').val(pane.name || '');
                const moduleName = getModuleName(pane.module) || 'Pane Type';
                $row.find('.paneManageTypeButton').text(`${moduleName} (Change)`);
                $paneList.append($row);
            });
            validatePaneList();
        }

        // Validate pane names/ids against duplicates and reserved IDs.
        function validatePaneList() {
            const reserved = getReservedIdSet();
            panesState.forEach((pane) => {
                if (pane.id) {
                    reserved.delete(pane.id.toLowerCase());
                }
            });
            const ids = panesState.map((pane) => pane.id);
            const duplicates = ids.filter((id, idx) => id && ids.indexOf(id) !== idx);
            let isValid = true;
            $('.paneManageName').removeClass('isInvalid');
            panesState.forEach((pane, index) => {
                let invalid = false;
                if (!pane.name || !pane.id) {
                    invalid = true;
                }
                if (duplicates.includes(pane.id)) {
                    invalid = true;
                }
                if (pane.id && reserved.has(pane.id.toLowerCase())) {
                    invalid = true;
                }
                if (invalid) {
                    isValid = false;
                    $paneList.find(`.paneManageRow[data-pane-index="${index}"] .paneManageName`).addClass('isInvalid');
                }
            });
            $('#paneManageSave').prop('disabled', !isValid);
            return isValid;
        }

        function getPaneIndexFromEvent(target) {
            const $row = $(target).closest('.paneManageRow');
            const index = Number($row.data('pane-index'));
            return Number.isFinite(index) ? index : -1;
        }

        // Open the type picker modal (used for add and change type).
        function openPaneTypeModal(index, isAdd) {
            pendingTypeIndex = index;
            pendingAddPane = isAdd;
            openAdminModal($paneTypeModal);
        }

        // Open the icon editor modal for a specific pane id.
        function openPaneIconModal(paneId) {
            activeIconPaneId = paneId;
            const pane = panesState.find((entry) => entry.id === paneId);
            if (!pane) {
                return;
            }
            const icon = pane.icon || { type: 'none', value: '' };
            $('#paneIconSvgInput').val(icon.type === 'svg' ? icon.value : '');
            $('#paneIconFileInput').val('');
            activeIconMode = icon.type === 'file' ? 'file' : 'svg';
            setIconMode(activeIconMode);
            openAdminModal($paneIconModal);
        }

        // Toggle between SVG and file upload tabs in the icon editor.
        function setIconMode(mode) {
            activeIconMode = mode;
            $('.paneIconTab').removeClass('isActive');
            $(`.paneIconTab[data-mode="${mode}"]`).addClass('isActive');
            $('.paneIconPanel').removeClass('isActive');
            $(`.paneIconPanel[data-mode="${mode}"]`).addClass('isActive');
        }

        // Update icon previews in the modal, pane header, and navbar.
        function updatePaneIconPreview(paneId) {
            const pane = panesState.find((entry) => entry.id === paneId);
            if (!pane) {
                return;
            }
            const html = `<span class="paneIconPreview">${renderIconPreview(pane.icon)}</span>`;
            $paneList.find(`.paneManageRow[data-pane-index]`).each(function() {
                const index = Number($(this).data('pane-index'));
                if (panesState[index] && panesState[index].id === paneId) {
                    $(this).find('.paneManageIconButton').html(html);
                }
            });
            $(`#${paneId} .paneIconButton`).html(html);
            $(`.navPaneItem[data-pane-id="${paneId}"] .navLink`).html(renderIconPreview(pane.icon));
        }

        // Detect whether the main admin page has unsaved edits.
        function hasUnsavedChanges() {
            const currentSnapshot = captureSnapshot();
            return !isEqualSnapshot(currentSnapshot, initialSnapshot);
        }

        // Warn before pane management saves if there are unsaved edits elsewhere.
        function confirmReloadIfDirty() {
            if (!hasUnsavedChanges()) {
                return true;
            }
            return window.confirm('You have unsaved edits. Saving pane changes will reload the page and discard them. Continue?');
        }

        // Persist pane management changes through save-config.php and reload.
        function savePaneManagementChanges(options) {
            const opts = options || {};
            if (!validatePaneList()) {
                addAdminNotice('danger', 'Please fix invalid pane names before saving.');
                return;
            }
            if (moduleCatalog.length === 0) {
                addAdminNotice('danger', 'No modules available. Pane management cannot be saved.');
                return;
            }
            if (!opts.force && !confirmReloadIfDirty()) {
                return;
            }
            if (opts.force) {
                addAdminNotice('warning', 'Auto-saving pane icon. Unsaved edits on this page may be lost.');
            }
            const shouldReload = opts.reload !== false;
            const payload = panesState.map((pane, index) => ({
                id: pane.id,
                name: pane.name,
                module: pane.module,
                icon: pane.icon || { type: 'none', value: '' },
                previousId: pane.previousId || pane.id,
                previousModule: pane.previousModule || pane.module,
                order: index + 1
            }));
            const formData = new FormData();
            formData.append('action', 'pane_management');
            formData.append('panes', JSON.stringify(payload));
            appendCsrf(formData);
            panesState.forEach((pane) => {
                if (pane.icon && pane.icon.type === 'file' && pane.iconFile) {
                    formData.append(`paneIconFile_${pane.id}`, pane.iconFile);
                }
            });
            const basePath = window.appConfig && typeof window.appConfig.basePath === 'string'
                ? window.appConfig.basePath.replace(/\/$/, '')
                : '';
            const saveUrl = basePath ? `${basePath}/res/scr/save-config.php` : '/res/scr/save-config.php';

            showSavingOverlay();
            $.ajax({
                url: saveUrl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function() {
                    hideSavingOverlay();
                    closeAdminModal($manageModal);
                    closeAdminModal($paneIconModal);
                    closeAdminModal($paneTypeModal);
                    closeAdminModal($paneDeleteModal);
                    if (shouldReload) {
                        window.location.reload();
                        return;
                    }
                    if (opts.force) {
                        addAdminNotice('ok', 'Pane icon saved.');
                    } else {
                        addAdminNotice('ok', 'Pane updates saved. Reload to see structural changes.');
                    }
                },
                error: function(xhr) {
                    hideSavingOverlay();
                    const responseText = xhr && xhr.responseText ? xhr.responseText : '';
                    let message = 'Pane save failed. Please try again.';
                    if (responseText) {
                        try {
                            const parsed = JSON.parse(responseText);
                            if (parsed && parsed.error) {
                                message = parsed.error;
                            }
                        } catch (err) {
                            message = responseText;
                        }
                    }
                    addAdminNotice('danger', message);
                }
            });
        }

        // Open pane management modal from the navbar button.
        $(document).on('click', '.paneManageButton', function() {
            renderPaneList();
            openAdminModal($manageModal);
        });

        // Icon button inside the management list.
        $(document).on('click', '.paneManageIconButton', function() {
            const index = getPaneIndexFromEvent(this);
            if (index < 0 || !panesState[index]) {
                return;
            }
            openPaneIconModal(panesState[index].id);
        });

        // Icon button inside pane headers (outside the management list).
        $(document).on('click', '.paneIconButton', function() {
            if ($(this).hasClass('paneManageIconButton')) {
                return;
            }
            const paneId = $(this).data('pane-id') || $(this).closest('.pane').attr('id');
            if (paneId) {
                openPaneIconModal(String(paneId));
            }
        });

        // Change pane type for an existing pane.
        $(document).on('click', '.paneManageTypeButton', function() {
            const index = getPaneIndexFromEvent(this);
            if (index < 0) {
                return;
            }
            openPaneTypeModal(index, false);
        });

        // Add new pane (opens the type picker).
        $(document).on('click', '#paneAddButton', function() {
            openPaneTypeModal(-1, true);
        });

        // Select a module type from the type picker modal.
        $(document).on('click', '.paneTypeOption', function() {
            const moduleId = $(this).data('module-id') || '';
            if (!moduleId) {
                return;
            }
            if (pendingAddPane) {
                const moduleName = getModuleName(moduleId);
                const baseId = normalizePaneId(moduleName || 'New Pane');
                const newId = uniquePaneId(baseId || 'newPane');
                panesState.push({
                    id: newId,
                    name: moduleName || 'New Pane',
                    module: moduleId,
                    icon: { type: 'none', value: '' },
                    previousId: '',
                    previousModule: '',
                    iconFile: null
                });
                renderPaneList();
            } else if (pendingTypeIndex !== null && panesState[pendingTypeIndex]) {
                const pane = panesState[pendingTypeIndex];
                if (pane.module !== moduleId) {
                    const confirmed = window.confirm('Changing the pane type will delete existing pane data when saved. Continue?');
                    if (!confirmed) {
                        closeAdminModal($paneTypeModal);
                        pendingTypeIndex = null;
                        pendingAddPane = false;
                        return;
                    }
                }
                pane.module = moduleId;
                renderPaneList();
            }
            pendingTypeIndex = null;
            pendingAddPane = false;
            closeAdminModal($paneTypeModal);
        });

        // Update pane name and ID as the user types.
        $(document).on('input', '.paneManageName', function() {
            const index = getPaneIndexFromEvent(this);
            if (index < 0 || !panesState[index]) {
                return;
            }
            const name = $(this).val();
            panesState[index].name = name;
            panesState[index].id = normalizePaneId(name);
            validatePaneList();
        });

        // Reorder panes using up/down arrow controls.
        $(document).on('click', '.paneManageRow .moveUpLink', function() {
            const index = getPaneIndexFromEvent(this);
            if (index <= 0) {
                return;
            }
            const temp = panesState[index - 1];
            panesState[index - 1] = panesState[index];
            panesState[index] = temp;
            renderPaneList();
        });

        $(document).on('click', '.paneManageRow .moveDownLink', function() {
            const index = getPaneIndexFromEvent(this);
            if (index < 0 || index >= panesState.length - 1) {
                return;
            }
            const temp = panesState[index + 1];
            panesState[index + 1] = panesState[index];
            panesState[index] = temp;
            renderPaneList();
        });

        // Prompt for pane deletion from the management list.
        $(document).on('click', '.paneManageDelete', function() {
            const index = getPaneIndexFromEvent(this);
            if (index < 0 || !panesState[index]) {
                return;
            }
            pendingDeletePaneId = panesState[index].id;
            $('#paneDeleteConfirmMessage').text(`Are you sure you want to remove ${panesState[index].name}? This will delete its data files.`);
            openAdminModal($paneDeleteModal);
        });

        // Prompt for pane deletion from the navbar overlay button.
        $(document).on('click', '.paneDeleteButton', function(event) {
            event.preventDefault();
            const paneId = $(this).data('pane-id');
            const pane = panesState.find((entry) => entry.id === paneId);
            if (!pane) {
                return;
            }
            pendingDeletePaneId = paneId;
            $('#paneDeleteConfirmMessage').text(`Are you sure you want to remove ${pane.name}? This will delete its data files.`);
            openAdminModal($paneDeleteModal);
        });

        // Confirm deletion and immediately persist the change.
        $('#paneDeleteConfirmYes').on('click', function() {
            if (!pendingDeletePaneId) {
                return;
            }
            panesState = panesState.filter((pane) => pane.id !== pendingDeletePaneId);
            pendingDeletePaneId = null;
            closeAdminModal($paneDeleteModal);
            renderPaneList();
            savePaneManagementChanges();
        });

        // Save pane list edits without deleting anything.
        $('#paneManageSave').on('click', function() {
            savePaneManagementChanges();
        });

        // Switch icon modal tabs.
        $('.paneIconTab').on('click', function() {
            const mode = $(this).data('mode');
            setIconMode(mode);
        });

        // Save icon changes into local state (persisted on pane save).
        $('#paneIconSave').on('click', function() {
            if (!activeIconPaneId) {
                return;
            }
            const pane = panesState.find((entry) => entry.id === activeIconPaneId);
            if (!pane) {
                return;
            }
            if (activeIconMode === 'svg') {
                let svg = ($('#paneIconSvgInput').val() || '').trim();
                svg = svg.replace(/<title[^>]*>[\s\S]*?<\/title>/gi, '').trim();
                if (svg && (svg.indexOf('<script') !== -1 || /\\son[a-z]+\\s*=\\s*["']?/i.test(svg))) {
                    addAdminNotice('danger', 'SVG icons cannot contain scripts or inline event handlers.');
                    return;
                }
                pane.icon = svg ? { type: 'svg', value: svg } : { type: 'none', value: '' };
                pane.iconFile = null;
            } else {
                const fileInput = $('#paneIconFileInput')[0];
                const file = fileInput && fileInput.files ? fileInput.files[0] : null;
                if (file) {
                    pane.icon = { type: 'file', value: '' };
                    pane.iconFile = file;
                }
            }
            updatePaneIconPreview(activeIconPaneId);
            closeAdminModal($paneIconModal);
            savePaneManagementChanges({ force: true, reload: false });
        });

        // Remove icon from the pane (sets type to none).
        $('#paneIconRemove').on('click', function() {
            if (!activeIconPaneId) {
                return;
            }
            const pane = panesState.find((entry) => entry.id === activeIconPaneId);
            if (!pane) {
                return;
            }
            pane.icon = { type: 'none', value: '' };
            pane.iconFile = null;
            updatePaneIconPreview(activeIconPaneId);
            closeAdminModal($paneIconModal);
            savePaneManagementChanges({ force: true, reload: false });
        });

        renderPaneList();
    }

    // Migration flow: preview and apply panes.json migration with explicit confirmation.
    function bindMigrationFlow() {
        const $reviewButton = $('#migrationReviewButton');
        const $modal = $('#migrationModal');
        if (!$reviewButton.length || !$modal.length) {
            return;
        }
        let migrationToken = '';

        function openModal() {
            openAdminModal($modal);
        }

        function closeModal() {
            closeAdminModal($modal);
        }

        // Render human-readable file actions (create/update/backup/delete).
        function renderMigrationSummary(actions) {
            const $summary = $('#migrationSummary');
            $summary.empty();
            const create = actions.create || [];
            const update = actions.update || [];
            const backup = actions.backup || [];
            const rename = actions.rename || [];
            const remove = actions.delete || [];

            if (backup.length) {
                $summary.append(`<span>Backup: ${backup.join(', ')}</span>`);
            }
            if (create.length) {
                $summary.append(`<span>Create: ${create.join(', ')}</span>`);
            }
            if (update.length) {
                $summary.append(`<span>Update: ${update.join(', ')}</span>`);
            }
            if (rename.length) {
                $summary.append(`<span>Rename: ${rename.join(', ')}</span>`);
            }
            if (remove.length) {
                $summary.append(`<span>Delete: ${remove.join(', ')}</span>`);
            }
            if (!$summary.children().length) {
                $summary.append('<span>No file changes detected.</span>');
            }
        }

        // Request a migration preview from save-config.php.
        function fetchPreview() {
            const basePath = window.appConfig && typeof window.appConfig.basePath === 'string'
                ? window.appConfig.basePath.replace(/\/$/, '')
                : '';
            const saveUrl = basePath ? `${basePath}/res/scr/save-config.php` : '/res/scr/save-config.php';
            const formData = new FormData();
            formData.append('action', 'migration_preview');
            appendCsrf(formData);

            showSavingOverlay();
            $.ajax({
                url: saveUrl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(resp) {
                    hideSavingOverlay();
                    if (!resp || resp.status !== 'ok') {
                        addAdminNotice('danger', 'Migration preview failed.');
                        return;
                    }
                    migrationToken = resp.token || '';
                    renderMigrationSummary(resp.actions || {});
                    $('#migrationPanesPreview').text(JSON.stringify(resp.payload || {}, null, 2));
                    openModal();
                },
                error: function(xhr) {
                    hideSavingOverlay();
                    const responseText = xhr && xhr.responseText ? xhr.responseText : '';
                    let message = 'Migration preview failed.';
                    if (responseText) {
                        try {
                            const parsed = JSON.parse(responseText);
                            if (parsed && parsed.error) {
                                message = parsed.error;
                            }
                        } catch (err) {
                            message = responseText;
                        }
                    }
                    addAdminNotice('danger', message);
                }
            });
        }

        $reviewButton.on('click', function() {
            fetchPreview();
        });

        $('#migrationApply').on('click', function() {
            if (!migrationToken) {
                addAdminNotice('danger', 'Migration preview not available.');
                return;
            }
            const basePath = window.appConfig && typeof window.appConfig.basePath === 'string'
                ? window.appConfig.basePath.replace(/\/$/, '')
                : '';
            const saveUrl = basePath ? `${basePath}/res/scr/save-config.php` : '/res/scr/save-config.php';
            const formData = new FormData();
            formData.append('action', 'migration_apply');
            formData.append('token', migrationToken);
            appendCsrf(formData);

            showSavingOverlay();
            $.ajax({
                url: saveUrl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function() {
                    hideSavingOverlay();
                    closeModal();
                    window.location.reload();
                },
                error: function(xhr) {
                    hideSavingOverlay();
                    const responseText = xhr && xhr.responseText ? xhr.responseText : '';
                    let message = 'Migration failed.';
                    if (responseText) {
                        try {
                            const parsed = JSON.parse(responseText);
                            if (parsed && parsed.error) {
                                message = parsed.error;
                            }
                        } catch (err) {
                            message = responseText;
                        }
                    }
                    addAdminNotice('danger', message);
                }
            });
        });

        $(document).on('click', '#migrationModal .userModalClose', function() {
            closeModal();
        });
    }

    function bindHeadlineEditingMode() {
        const $inputs = $('.headlineInput');
        if (!$inputs.length) {
            return;
        }
        $inputs.on('focus', function() {
            $('body').addClass('isHeadlineEditing');
            $inputs.removeClass('isEditing');
            $(this).addClass('isEditing');
        });
        $inputs.on('blur', function() {
            const $body = $('body');
            $(this).removeClass('isEditing');
            setTimeout(function() {
                if (!$inputs.is(':focus')) {
                    $body.removeClass('isHeadlineEditing');
                    $inputs.removeClass('isEditing');
                }
            }, 0);
        });
    }

    function bindBackgroundEditingMode() {
        const selector = '.bgAuthorInput, .bgAuthorUrlInput';
        $(document).on('focus', selector, function() {
            const $input = $(this);
            const $row = $input.closest('.bgConfigRow');
            $row.addClass('isEditing');
            $row.find(selector).removeClass('isEditing');
            $input.addClass('isEditing');
        });
        $(document).on('blur', selector, function() {
            const $input = $(this);
            const $row = $input.closest('.bgConfigRow');
            $input.removeClass('isEditing');
            setTimeout(function() {
                if (!$row.find(selector).is(':focus')) {
                    $row.removeClass('isEditing');
                    $row.find(selector).removeClass('isEditing');
                }
            }, 0);
        });
    }

    function buildMarkdownToolbarHtml() {
        return `
            <div class="markdownToolbar" role="toolbar" aria-label="Markdown formatting">
                <div class="markdownToolbarGroup">
                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="bold" title="Bold" aria-label="Bold">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M13.5,15.5H10V12.5H13.5A1.5,1.5 0 0,1 15,14A1.5,1.5 0 0,1 13.5,15.5M10,6.5H13A1.5,1.5 0 0,1 14.5,8A1.5,1.5 0 0,1 13,9.5H10M15.6,10.79C16.57,10.11 17.25,9 17.25,8C17.25,5.74 15.5,4 13.25,4H7V18H14.04C16.14,18 17.75,16.3 17.75,14.21C17.75,12.69 16.89,11.39 15.6,10.79Z" /></svg>
                    </button>
                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="italic" title="Italic" aria-label="Italic">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M10,4V7H12.21L8.79,15H6V18H14V15H11.79L15.21,7H18V4H10Z" /></svg>
                    </button>
                    <select class="markdownHeadingSelect" aria-label="Insert heading">
                        <option value="">Heading</option>
                        <option value="1">Heading 1</option>
                        <option value="2">Heading 2</option>
                        <option value="3">Heading 3</option>
                        <option value="4">Heading 4</option>
                        <option value="5">Heading 5</option>
                        <option value="6">Heading 6</option>
                    </select>
                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="ul" title="Bulleted list" aria-label="Bulleted list">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7,5H21V7H7V5M7,13V11H21V13H7M4,4.5A1.5,1.5 0 0,1 5.5,6A1.5,1.5 0 0,1 4,7.5A1.5,1.5 0 0,1 2.5,6A1.5,1.5 0 0,1 4,4.5M4,10.5A1.5,1.5 0 0,1 5.5,12A1.5,1.5 0 0,1 4,13.5A1.5,1.5 0 0,1 2.5,12A1.5,1.5 0 0,1 4,10.5M7,19V17H21V19H7M4,16.5A1.5,1.5 0 0,1 5.5,18A1.5,1.5 0 0,1 4,19.5A1.5,1.5 0 0,1 2.5,18A1.5,1.5 0 0,1 4,16.5Z" /></svg>
                    </button>
                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="ol" title="Numbered list" aria-label="Numbered list">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7,13V11H21V13H7M7,19V17H21V19H7M7,7V5H21V7H7M3,8V5H2V4H4V8H3M2,17V16H5V20H2V19H4V18.5H3V17.5H4V17H2M4.25,10A0.75,0.75 0 0,1 5,10.75C5,10.95 4.92,11.14 4.79,11.27L3.12,13H5V14H2V13.08L4,11H2V10H4.25Z" /></svg>
                    </button>
                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="quote" title="Quote" aria-label="Quote">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M9 22C8.4 22 8 21.6 8 21V18H4C2.9 18 2 17.1 2 16V4C2 2.9 2.9 2 4 2H20C21.1 2 22 2.9 22 4V16C22 17.1 21.1 18 20 18H13.9L10.2 21.7C10 21.9 9.8 22 9.5 22H9M10 16V19.1L13.1 16H20V4H4V16H10M16.3 6L14.9 9H17V13H13V8.8L14.3 6H16.3M10.3 6L8.9 9H11V13H7V8.8L8.3 6H10.3Z" /></svg>
                    </button>
                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="code" title="Code" aria-label="Code">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M5,3H7V5H5V10A2,2 0 0,1 3,12A2,2 0 0,1 5,14V19H7V21H5C3.93,20.73 3,20.1 3,19V15A2,2 0 0,0 1,13H0V11H1A2,2 0 0,0 3,9V5A2,2 0 0,1 5,3M19,3A2,2 0 0,1 21,5V9A2,2 0 0,0 23,11H24V13H23A2,2 0 0,0 21,15V19A2,2 0 0,1 19,21H17V19H19V14A2,2 0 0,1 21,12A2,2 0 0,1 19,10V5H17V3H19M12,15A1,1 0 0,1 13,16A1,1 0 0,1 12,17A1,1 0 0,1 11,16A1,1 0 0,1 12,15M8,15A1,1 0 0,1 9,16A1,1 0 0,1 8,17A1,1 0 0,1 7,16A1,1 0 0,1 8,15M16,15A1,1 0 0,1 17,16A1,1 0 0,1 16,17A1,1 0 0,1 15,16A1,1 0 0,1 16,15Z" /></svg>
                    </button>
                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="link" title="Link" aria-label="Link">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M19,19H5V5H19V19M13.94,10.06C14.57,10.7 14.92,11.54 14.92,12.44C14.92,13.34 14.57,14.18 13.94,14.81L11.73,17C11.08,17.67 10.22,18 9.36,18C8.5,18 7.64,17.67 7,17C5.67,15.71 5.67,13.58 7,12.26L8.35,10.9L8.34,11.5C8.33,12 8.41,12.5 8.57,12.94L8.62,13.09L8.22,13.5C7.91,13.8 7.74,14.21 7.74,14.64C7.74,15.07 7.91,15.47 8.22,15.78C8.83,16.4 9.89,16.4 10.5,15.78L12.7,13.59C13,13.28 13.18,12.87 13.18,12.44C13.18,12 13,11.61 12.7,11.3C12.53,11.14 12.44,10.92 12.44,10.68C12.44,10.45 12.53,10.23 12.7,10.06C13.03,9.73 13.61,9.74 13.94,10.06M18,9.36C18,10.26 17.65,11.1 17,11.74L15.66,13.1V12.5C15.67,12 15.59,11.5 15.43,11.06L15.38,10.92L15.78,10.5C16.09,10.2 16.26,9.79 16.26,9.36C16.26,8.93 16.09,8.53 15.78,8.22C15.17,7.6 14.1,7.61 13.5,8.22L11.3,10.42C11,10.72 10.82,11.13 10.82,11.56C10.82,12 11,12.39 11.3,12.7C11.47,12.86 11.56,13.08 11.56,13.32C11.56,13.56 11.47,13.78 11.3,13.94C11.13,14.11 10.91,14.19 10.68,14.19C10.46,14.19 10.23,14.11 10.06,13.94C8.75,12.63 8.75,10.5 10.06,9.19L12.27,7C13.58,5.67 15.71,5.68 17,7C17.65,7.62 18,8.46 18,9.36Z" /></svg>
                    </button>
                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="image" title="Image" aria-label="Image">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M13 19C13 19.7 13.13 20.37 13.35 21H5C3.9 21 3 20.11 3 19V5C3 3.9 3.9 3 5 3H19C20.11 3 21 3.9 21 5V13.35C20.37 13.13 19.7 13 19 13V5H5V19H13M13.96 12.29L11.21 15.83L9.25 13.47L6.5 17H13.35C13.75 15.88 14.47 14.91 15.4 14.21L13.96 12.29M20 18V15H18V18H15V20H18V23H20V20H23V18H20Z" /></svg>
                    </button>
                    <button class="usersButton iconButton mdToolbarButton mdTextIcon" type="button" data-md-action="linebreak" title="Line break" aria-label="Line break">&lt;br&gt;</button>
                    <button class="usersButton iconButton mdToolbarButton mdTextIcon" type="button" data-md-action="hr" title="Horizontal rule" aria-label="Horizontal rule">&lt;hr&gt;</button>
                </div>
                <div class="markdownToolbarPreview">
                    <button class="usersButton iconButton mdToolbarButton mdPreviewButton" type="button" data-md-action="preview" title="Preview" aria-label="Preview" aria-pressed="false">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12,9A3,3 0 0,0 9,12A3,3 0 0,0 12,15A3,3 0 0,0 15,12A3,3 0 0,0 12,9M12,17A5,5 0 0,1 7,12A5,5 0 0,1 12,7A5,5 0 0,1 17,12A5,5 0 0,1 12,17M12,4.5C7,4.5 2.73,7.61 1,12C2.73,16.39 7,19.5 12,19.5C17,19.5 21.27,16.39 23,12C21.27,7.61 17,4.5 12,4.5Z" /></svg>
                    </button>
                </div>
            </div>
        `;
    }

    function bindMarkdownToolbars() {
        $(document).on('mousedown', '.markdownToolbar button', function(event) {
            event.preventDefault();
        });

        $(document).on('click', '.markdownToolbar button', function() {
            const $button = $(this);
            const action = $button.data('md-action');
            const $toolbar = $button.closest('.markdownToolbar');
            const $editor = $toolbar.closest('.markdownEditor');
            const $textarea = $editor.find('textarea').first();
            if (!$textarea.length) {
                return;
            }
            const textarea = $textarea[0];
            if (action === 'preview') {
                toggleMarkdownPreview($editor, $button, $textarea);
                return;
            }
            applyMarkdownAction(textarea, action);
            textarea.focus();
        });

        $(document).on('change', '.markdownHeadingSelect', function() {
            const level = parseInt($(this).val(), 10);
            if (!level) {
                return;
            }
            const $toolbar = $(this).closest('.markdownToolbar');
            const $textarea = $toolbar.closest('.markdownEditor').find('textarea').first();
            if ($textarea.length) {
                applyLinePrefix($textarea[0], `${'#'.repeat(level)} `);
                $textarea[0].focus();
            }
            $(this).val('');
        });
    }

    function applyMarkdownAction(textarea, action) {
        switch (action) {
            case 'bold':
                wrapSelection(textarea, '**', '**', 'bold text');
                break;
            case 'italic':
                wrapSelection(textarea, '*', '*', 'italic text');
                break;
            case 'ul':
                applyLinePrefix(textarea, '- ');
                break;
            case 'ol':
                applyLinePrefix(textarea, '1. ');
                break;
            case 'quote':
                applyLinePrefix(textarea, '> ');
                break;
            case 'code':
                insertCode(textarea);
                break;
            case 'link':
                insertLink(textarea, false);
                break;
            case 'image':
                insertLink(textarea, true);
                break;
            case 'linebreak':
                insertText(textarea, '<br>');
                break;
            case 'hr':
                insertText(textarea, '\n<hr>\n');
                break;
            default:
                break;
        }
    }

    function toggleMarkdownPreview($editor, $button, $textarea) {
        const isActive = $editor.hasClass('isPreviewing');
        const $preview = $editor.find('.markdownPreview');
        const $toolbar = $editor.find('.markdownToolbar');
        const $toolbarButtons = $toolbar.find('button').not('[data-md-action="preview"]');
        const $toolbarSelect = $toolbar.find('.markdownHeadingSelect');
        if (isActive) {
            $editor.removeClass('isPreviewing');
            $button.removeClass('isActive').attr('aria-pressed', 'false');
            $preview.attr('hidden', true).empty();
            $toolbarButtons.prop('disabled', false);
            $toolbarSelect.prop('disabled', false);
            $textarea.focus();
            return;
        }
        $editor.addClass('isPreviewing');
        $button.addClass('isActive').attr('aria-pressed', 'true');
        $toolbarButtons.prop('disabled', true);
        $toolbarSelect.prop('disabled', true);
        const markdown = $textarea.val() || '';
        requestMarkdownPreview(markdown, function(html) {
            $preview.html(html).removeAttr('hidden');
        }, function(message) {
            $preview.html(`<p>${escapeHtml(message || 'Preview failed.')}</p>`).removeAttr('hidden');
        });
    }

    function requestMarkdownPreview(markdown, onSuccess, onError) {
        const basePath = window.appConfig && typeof window.appConfig.basePath === 'string'
            ? window.appConfig.basePath.replace(/\/$/, '')
            : '';
        const previewUrl = basePath ? `${basePath}/res/scr/markdown-preview.php` : '/res/scr/markdown-preview.php';
        const formData = new FormData();
        formData.append('markdown', markdown);
        appendCsrf(formData);
        $.ajax({
            url: previewUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response && typeof response.html === 'string') {
                    onSuccess(response.html);
                } else {
                    onError('Preview failed.');
                }
            },
            error: function(xhr) {
                const responseText = xhr && xhr.responseText ? xhr.responseText : '';
                let message = 'Preview failed.';
                if (responseText) {
                    try {
                        const parsed = JSON.parse(responseText);
                        if (parsed && parsed.error) {
                            message = parsed.error;
                        }
                    } catch (err) {
                        message = responseText;
                    }
                }
                onError(message);
            }
        });
    }

    function wrapSelection(textarea, before, after, placeholder) {
        const value = textarea.value || '';
        const start = textarea.selectionStart || 0;
        const end = textarea.selectionEnd || 0;
        const selected = value.substring(start, end);
        const hasSelection = end > start;
        const content = hasSelection ? selected : (placeholder || '');
        const insert = `${before}${content}${after}`;
        textarea.value = value.substring(0, start) + insert + value.substring(end);
        const selectionStart = start + before.length;
        const selectionEnd = selectionStart + content.length;
        textarea.selectionStart = selectionStart;
        textarea.selectionEnd = selectionEnd;
    }

    function insertText(textarea, text) {
        const value = textarea.value || '';
        const start = textarea.selectionStart || 0;
        const end = textarea.selectionEnd || 0;
        textarea.value = value.substring(0, start) + text + value.substring(end);
        const cursor = start + text.length;
        textarea.selectionStart = cursor;
        textarea.selectionEnd = cursor;
    }

    function applyLinePrefix(textarea, prefix) {
        const value = textarea.value || '';
        const start = textarea.selectionStart || 0;
        const end = textarea.selectionEnd || 0;
        const selection = value.substring(start, end);
        if (!selection) {
            const lineStart = value.lastIndexOf('\n', start - 1) + 1;
            const lineEnd = value.indexOf('\n', start);
            const endIndex = lineEnd === -1 ? value.length : lineEnd;
            const line = value.substring(lineStart, endIndex);
            const nextValue = value.substring(0, lineStart) + prefix + line + value.substring(endIndex);
            textarea.value = nextValue;
            const cursor = start + prefix.length;
            textarea.selectionStart = cursor;
            textarea.selectionEnd = cursor;
            return;
        }
        const prefixed = selection.split('\n').map((line) => `${prefix}${line}`).join('\n');
        textarea.value = value.substring(0, start) + prefixed + value.substring(end);
        textarea.selectionStart = start;
        textarea.selectionEnd = start + prefixed.length;
    }

    function insertCode(textarea) {
        const value = textarea.value || '';
        const start = textarea.selectionStart || 0;
        const end = textarea.selectionEnd || 0;
        const selection = value.substring(start, end);
        if (selection) {
            if (selection.includes('\n')) {
                wrapSelection(textarea, '```\n', '\n```');
            } else {
                wrapSelection(textarea, '`', '`');
            }
            return;
        }
        textarea.value = value.substring(0, start) + '``' + value.substring(end);
        const cursor = start + 1;
        textarea.selectionStart = cursor;
        textarea.selectionEnd = cursor;
    }

    function insertLink(textarea, isImage) {
        const value = textarea.value || '';
        const start = textarea.selectionStart || 0;
        const end = textarea.selectionEnd || 0;
        const selected = value.substring(start, end);
        const label = selected || (isImage ? 'alt text' : 'link text');
        const url = isImage ? 'image-url' : 'url';
        const prefix = isImage ? '![' : '[';
        const insert = `${prefix}${label}](${url})`;
        textarea.value = value.substring(0, start) + insert + value.substring(end);
        const urlStart = start + prefix.length + label.length + 2;
        const urlEnd = urlStart + url.length;
        textarea.selectionStart = urlStart;
        textarea.selectionEnd = urlEnd;
    }

    // Event list module: serialize events, validate fields, and manage card UI.
    function bindEventListEditors() {
        const $eventPanes = $('[data-pane-type="eventList"]');
        if (!$eventPanes.length) {
            return;
        }

        const paneApis = [];
        let pendingEventDelete = null;

        function openEventDeleteModal(action) {
            pendingEventDelete = action;
            openAdminModal($('#eventDeleteConfirmModal'));
        }

        $(document).on('click', '#eventDeleteConfirmYes', function() {
            if (typeof pendingEventDelete === 'function') {
                pendingEventDelete();
            }
            pendingEventDelete = null;
            closeAdminModal($('#eventDeleteConfirmModal'));
        });

        $(document).on('click', '#eventDeleteConfirmModal .userModalClose', function() {
            pendingEventDelete = null;
            closeAdminModal($('#eventDeleteConfirmModal'));
        });

        $eventPanes.each(function() {
            const $pane = $(this);
            const paneId = $pane.data('pane-id') || $pane.attr('id') || '';
            const $list = $pane.find('.eventList');
            const $payload = $pane.find('.eventListPayload');
            const $toggle = $pane.find('.eventShowPast');

            function getBrowserTimeZone() {
                if (typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
                    return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
                }
                return '';
            }

            function normalizeEventId(name, startDate, startTime) {
                const base = `${name}-${startDate}-${startTime}`.toLowerCase();
                return base.replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
            }

            function getEventCards() {
                return $list.find('.eventCard');
            }

            function collectEvents() {
                const events = [];
                getEventCards().each(function() {
                    const $card = $(this);
                    const name = ($card.find('.eventNameInput').val() || '').trim();
                    const startDate = ($card.find('.eventStartDateInput').val() || '').trim();
                    const startTime = ($card.find('.eventStartTimeInput').val() || '').trim();
                    const endDate = ($card.find('.eventEndDateInput').val() || '').trim();
                    const endTime = ($card.find('.eventEndTimeInput').val() || '').trim();
                    const timeZone = ($card.find('.eventTimezoneInput').val() || '').trim() || getBrowserTimeZone();
                    const address = ($card.find('.eventAddressInput').val() || '').trim();
                    const description = ($card.find('.eventDescriptionInput').val() || '').trim();
                    const id = normalizeEventId(name, startDate, startTime);
                    events.push({
                        id,
                        name,
                        startDate,
                        startTime,
                        endDate,
                        endTime,
                        timeZone,
                        address,
                        description
                    });
                });
                // Sort newest to oldest for storage and reload order.
                events.sort(compareEventsDesc);
                return events;
            }

            function validateEvents(events) {
                let isValid = true;
                const seen = new Set();

                getEventCards().each(function(index) {
                    const $card = $(this);
                    const $message = $card.find('.eventValidation');
                    $message.text('');
                    const event = events[index];
                    if (!event) {
                        return;
                    }

                    const errors = [];
                    if (!event.name) {
                        errors.push('Name is required.');
                    }
                    if (!event.startDate) {
                        errors.push('Start date is required.');
                    }
                    if (!event.startTime) {
                        errors.push('Start time is required.');
                    }
                    if ((event.endDate && !event.endTime) || (!event.endDate && event.endTime)) {
                        errors.push('End date and time must both be set or both be blank.');
                    }
                    if (event.endDate && event.endTime && isEndBeforeStart(event.startDate, event.startTime, event.endDate, event.endTime)) {
                        errors.push('End date/time cannot be earlier than start date/time.');
                    }
                    if (!event.address) {
                        errors.push('Address is required.');
                    }
                    if (!event.description) {
                        errors.push('Description is required.');
                    }

                    const dedupeKey = `${event.name.toLowerCase()}|${event.startDate}|${event.startTime}`;
                    if (event.name && event.startDate && event.startTime) {
                        if (seen.has(dedupeKey)) {
                            errors.push('Duplicate event (name + date + time).');
                        } else {
                            seen.add(dedupeKey);
                        }
                    }

                    if (errors.length) {
                        $message.text(errors.join(' '));
                        isValid = false;
                    }
                });

                return isValid;
            }

            function updatePayload() {
                const events = collectEvents();
                const payload = {
                    showPast: $toggle.is(':checked'),
                    events
                };
                $payload.val(JSON.stringify(payload));
                return events;
            }

            function refreshValidation() {
                const events = updatePayload();
                return validateEvents(events);
            }

            function ensureEmptyState() {
                const hasCards = getEventCards().length > 0;
                $list.find('.eventEmpty').remove();
                if (!hasCards) {
                    $list.append('<div class="eventEmpty">No events yet. Click Add Event to create one.</div>');
                }
            }

            function addEventCard(data, prepend) {
                const markdownToolbar = buildMarkdownToolbarHtml();
                const template = `
                    <div class=\"eventCard\">
                        <div class=\"eventNameRow\">
                            <label class=\"eventNameLabel\">
                                <span class=\"eventFieldTitle\">Event Name</span>
                                <input type=\"text\" class=\"eventNameInput\" placeholder=\"Event name\">
                            </label>
                            <div class=\"eventCardActions\">
                                <button class=\"deleteLink iconButton\" type=\"button\" title=\"Remove event\" aria-label=\"Remove event\">${$('.linksConfig .deleteLink').first().html() || ''}</button>
                            </div>
                        </div>
                        <div class=\"eventSectionDivider\" aria-hidden=\"true\"></div>
                        <div class=\"eventTimeRow\">
                            <div class=\"eventFieldTitle eventFieldTitleRow\">When</div>
                            <div class=\"eventTimeFields\">
                                <div class=\"eventTimeGroup\">
                                    <span class=\"eventTimeLabel\">From</span>
                                    <input type=\"date\" class=\"eventStartDateInput\" aria-label=\"Start date\">
                                    <input type=\"time\" class=\"eventStartTimeInput\" aria-label=\"Start time\">
                                </div>
                                <div class=\"eventTimeDash\">-</div>
                                <div class=\"eventTimeGroup\">
                                    <span class=\"eventTimeLabel\">To</span>
                                    <input type=\"date\" class=\"eventEndDateInput\" aria-label=\"End date\">
                                    <input type=\"time\" class=\"eventEndTimeInput\" aria-label=\"End time\">
                                </div>
                            </div>
                        </div>
                        <div class=\"eventSectionDivider\" aria-hidden=\"true\"></div>
                        <div class=\"eventTimeZoneRow\">
                            <span class=\"eventFieldTitle\">Time Zone</span>
                            <input type=\"text\" class=\"eventTimezoneInput\" placeholder=\"America/New_York\" aria-label=\"Time zone\">
                        </div>
                        <div class=\"eventSectionDivider\" aria-hidden=\"true\"></div>
                        <div class=\"eventAddressRow\">
                            <div class=\"eventFieldTitle\">Address</div>
                            <input type=\"text\" class=\"eventAddressInput\" placeholder=\"123 Main St, City, State\" aria-label=\"Address\">
                        </div>
                        <div class=\"eventSectionDivider\" aria-hidden=\"true\"></div>
                        <div class=\"eventDescriptionLabel\">
                            <span class=\"eventFieldTitle\">Description</span>
                            <div class=\"markdownEditor\">
                                ${markdownToolbar}
                                <textarea class=\"eventDescriptionInput markdownTextarea\" rows=\"4\" placeholder=\"Details, host, venue, etc.\"></textarea>
                                <div class=\"markdownPreview\" aria-live=\"polite\" hidden></div>
                            </div>
                        </div>
                        <div class=\"eventValidation\" aria-live=\"polite\"></div>
                    </div>\n                `;
                const $card = $(template);
                if (data) {
                    $card.find('.eventNameInput').val(data.name || '');
                    $card.find('.eventStartDateInput').val(data.startDate || data.date || '');
                    $card.find('.eventStartTimeInput').val(data.startTime || '');
                    $card.find('.eventEndDateInput').val(data.endDate || (data.endTime ? (data.startDate || data.date || '') : ''));
                    $card.find('.eventEndTimeInput').val(data.endTime || '');
                    $card.find('.eventTimezoneInput').val(data.timeZone || getBrowserTimeZone());
                    $card.find('.eventAddressInput').val(data.address || '');
                    $card.find('.eventDescriptionInput').val(data.description || '');
                } else {
                    $card.find('.eventTimezoneInput').val(getBrowserTimeZone());
                }
                if (prepend) {
                    $list.prepend($card);
                } else {
                    $list.append($card);
                }
                const $scroll = $pane.find('.eventListScroll');
                if ($scroll.length) {
                    $scroll.scrollTop(0);
                }
                ensureEmptyState();
                refreshValidation();
            }

            function compareEventsDesc(a, b) {
                const aKey = `${a.startDate || a.date || ''} ${a.startTime || ''}`;
                const bKey = `${b.startDate || b.date || ''} ${b.startTime || ''}`;
                return bKey.localeCompare(aKey);
            }

            function renderFromEvents(events) {
                $list.empty();
                if (!events.length) {
                    ensureEmptyState();
                    refreshValidation();
                    return;
                }
                const sorted = events.slice().sort(compareEventsDesc);
                sorted.forEach((event) => addEventCard(event, false));
                ensureEmptyState();
                refreshValidation();
            }

            $pane.on('click', '.eventAddButton', function() {
                addEventCard(null, true);
            });

            $pane.on('click', '.eventCard .deleteLink', function() {
                const $card = $(this).closest('.eventCard');
                openEventDeleteModal(function() {
                    $card.remove();
                    ensureEmptyState();
                    refreshValidation();
                });
            });

            $pane.on('input change', '.eventCard input, .eventCard textarea, .eventShowPast', function() {
                refreshValidation();
            });

            // Ensure initial ordering and payload on load.
            renderFromEvents(collectEvents());

            paneApis.push({
                refresh: function() {
                    let parsed = null;
                    try {
                        parsed = JSON.parse($payload.val() || '{}');
                    } catch (err) {
                        parsed = null;
                    }
                    const events = parsed && Array.isArray(parsed.events) ? parsed.events : [];
                    renderFromEvents(events);
                }
            });
        });

        window.refreshEventListUIs = function() {
            paneApis.forEach(function(api) {
                if (api && typeof api.refresh === 'function') {
                    api.refresh();
                }
            });
        };
    }

    function applySiteEditPermissions() {
        const canEditSite = window.appConfig && window.appConfig.canEditSite === false ? false : true;
        const $targets = $('#container')
            .find('.pane')
            .not('#users')
            .find('input, textarea, button, select')
            .add($('#header').find('.headlineInput, .logoChange, #logoFileInput'))
            .add($('.saveChanges, .paneManageButton, .paneDeleteButton'));

        if (canEditSite) {
            $targets.prop('disabled', false);
            return;
        }

        $targets.prop('disabled', true);
    }

    function addAdminNotice(type, text, options) {
        const $notices = $('#adminNotices');
        if (!$notices.length) {
            return;
        }
        const safeType = type || 'ok';
        const $notice = $(`
            <div class="adminNotice adminNotice--${safeType}">
                <span class="adminNoticeText"></span>
                <button type="button" class="adminNoticeClose" aria-label="Dismiss notification">×</button>
            </div>
        `);
        $notice.find('.adminNoticeText').text(text);
        $notices.append($notice);

        const persist = options && options.persist;
        if (safeType !== 'danger' && !persist) {
            const timeoutMs = safeType === 'warning' ? 15000 : 5000;
            scheduleNoticeTimeout($notice, timeoutMs);
        }
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function showSavingOverlay() {
        $('#savingOverlay').addClass('isActive').attr('aria-hidden', 'false');
    }

    function hideSavingOverlay() {
        $('#savingOverlay').removeClass('isActive').attr('aria-hidden', 'true');
    }

    function noticeHasAction($notice) {
        if (!$notice || !$notice.length) {
            return false;
        }
        return $notice.find('form, button:not(.adminNoticeClose), input[type="submit"], input[type="button"]').length > 0;
    }

    function bindAdminNotices() {
        $(document)
            .off('click.adminNoticeClose')
            .on('click.adminNoticeClose', '.adminNoticeClose', function() {
            const $notice = $(this).closest('.adminNotice');
            clearNoticeTimer($notice);
            $notice.remove();
        });
        $('#adminNotices .adminNotice').each(function() {
            const $notice = $(this);
            if ($notice.data('persist')) {
                return;
            }
            if ($notice.hasClass('adminNotice--danger')) {
                return;
            }
            if (noticeHasAction($notice)) {
                return;
            }
            const timeoutMs = $notice.hasClass('adminNotice--warning') ? 15000 : 5000;
            scheduleNoticeTimeout($notice, timeoutMs);
        });
    }

    function scheduleNoticeTimeout($notice, durationMs) {
        if (!$notice.length || durationMs <= 0) {
            return;
        }
        if ($notice.data('noticeTimerActive')) {
            return;
        }
        $notice.data('noticeTimerActive', true);

        let remainingMs = durationMs;
        let startTime = Date.now();
        let rafId = null;
        let paused = false;

        let $progress = $notice.find('.adminNoticeProgress');
        if (!$progress.length) {
            $progress = $('<div class="adminNoticeProgress"></div>');
            $notice.append($progress);
        }

        function updateProgress() {
            if (paused) {
                return;
            }
            const elapsed = Date.now() - startTime;
            const left = Math.max(remainingMs - elapsed, 0);
            const percent = (left / durationMs) * 100;
            $progress.css('width', `${percent}%`);

            if (left <= 0) {
                $notice.addClass('isClosing');
                $notice.fadeOut(200, function() {
                    clearNoticeTimer($notice);
                    $notice.remove();
                });
                return;
            }
            rafId = requestAnimationFrame(updateProgress);
            $notice.data('noticeRafId', rafId);
        }

        function pauseTimer() {
            if (paused) {
                return;
            }
            paused = true;
            remainingMs = Math.max(remainingMs - (Date.now() - startTime), 0);
            if (rafId) {
                cancelAnimationFrame(rafId);
                rafId = null;
            }
        }

        function resumeTimer() {
            if (!paused) {
                return;
            }
            paused = false;
            startTime = Date.now();
            rafId = requestAnimationFrame(updateProgress);
            $notice.data('noticeRafId', rafId);
        }

        $notice.on('mouseenter.noticeTimer', pauseTimer);
        $notice.on('mouseleave.noticeTimer', resumeTimer);

        rafId = requestAnimationFrame(updateProgress);
        $notice.data('noticeRafId', rafId);
        $notice.data('noticePause', pauseTimer);
        $notice.data('noticeResume', resumeTimer);
    }

    function clearNoticeTimer($notice) {
        if (!$notice || !$notice.length) {
            return;
        }
        const rafId = $notice.data('noticeRafId');
        if (rafId) {
            cancelAnimationFrame(rafId);
        }
        $notice.off('mouseenter.noticeTimer');
        $notice.off('mouseleave.noticeTimer');
        $notice.removeData('noticeRafId');
        $notice.removeData('noticeTimerActive');
        $notice.removeData('noticePause');
        $notice.removeData('noticeResume');
    }

    function updateEditSitePermissionFromUsers() {
        const currentUser = window.appConfig && window.appConfig.currentUser ? window.appConfig.currentUser : '';
        if (!currentUser) {
            return;
        }
        const row = document.querySelector(`.usersRow[data-username="${currentUser}"]`);
        if (!row) {
            return;
        }
        const raw = row.getAttribute('data-permissions') || '';
        const perms = raw ? raw.split(',') : [];
        const isFullAdmin = perms.includes('full_admin');
        window.appConfig.canEditSite = isFullAdmin || perms.includes('edit_site');
    }

    function buildTutorialSteps() {
        return [
            {
                selector: 'header.header',
                text: 'To change the logo, click \"Change\" and select a file. To change the title or subtitle, edit the text boxes.',
                onBefore: function() {
                    // Ensure header is visible; nothing extra needed.
                }
            },
            {
                selector: '#links',
                text: 'Edit links here. Set the display text under Name, the URL under URL, and toggle Full width. Delete removes the entry. Separators add space. Use the buttons at the bottom to add links or separators.',
                onBefore: function() {
                    $('.navLink[data-pane=\"links\"]').trigger('click');
                }
            },
            {
                selector: '#bg',
                text: 'Background images rotate on each load. Change an image with \"Change\", set credits under Author, delete to remove, and add new ones with the button below.',
                onBefore: function() {
                    $('.navLink[data-pane=\"bg\"]').trigger('click');
                }
            },
            {
                selector: 'nav',
                text: 'Use the navbar to switch between panes. Pane Management lets you add, rename, reorder, or remove panes.',
                onBefore: function() {}
            },
            {
                selector: '.paneManageButton',
                text: 'Click Pane Management to add new panes or change their order, names, icons, and types.',
                onBefore: function() {
                    $('.paneManageButton').trigger('click');
                }
            },
            {
                selector: '#paneManagementModal',
                text: 'This modal controls pane order and naming. Use the arrows to reorder, Change Pane Type to swap modules, the icon button to edit icons, and the delete button to remove panes. Save to apply changes.',
                onBefore: function() {
                    $('#paneManagementModal').addClass('isOpen').attr('aria-hidden', 'false');
                },
                onAfter: function() {
                    $('#paneManagementModal').removeClass('isOpen').attr('aria-hidden', 'true');
                }
            },
            {
                selector: '#users',
                text: 'Manage accounts here. Create a new user with a temporary password on the left. Use the list to reset passwords or remove users on the right.',
                onBefore: function() {
                    $('.navLink[data-pane=\"users\"]').trigger('click');
                }
            },
            {
                selector: '.headerActionStack',
                text: 'Click Log Out to leave the admin panel. Click Help to view this tutorial again. Click Save All Changes to commit your edits.',
                onBefore: function() {}
            }
        ];
    }
});

// Config page tutorial and interactions (UI only, no persistence).

$(document).ready(function() {
    const steps = buildTutorialSteps();
    let currentStep = 0;
    let pendingLogoFile = null;
    let gdNoticeShown = false;

    let linkCounter = $('#linksConfig .linksConfigCard').length;
    let authLinkCounter = $('#authLinksConfig .authLinksConfigCard').length;
    let initialSnapshot = null;
    let pendingBgDelete = null;
    let authLinksNeedsNormalization = $('#authLinksConfig').attr('data-needs-normalization') === 'true';
    const csrfToken = window.appConfig && window.appConfig.csrfToken ? window.appConfig.csrfToken : '';
    let activeModal = null;
    let lastFocusedElement = null;
    const modalStack = [];
    const modalBackgroundSelectors = ['#header', '#container', 'nav', '.adminNotices'];
    const defaultBackgroundSettings = { mode: 'random_load', duration: 5 };

    function appendCsrf(formData) {
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }
    }

    // Unified handler for jQuery $.ajax error callbacks against admin
    // endpoints. Hides the saving overlay first, then extracts a
    // user-facing message from xhr.responseText (parsed JSON .error →
    // raw text → defaultMsg fallback chain) and shows the danger
    // notice. Use from each $.ajax({ error }) handler instead of
    // duplicating the parse + cleanup boilerplate. Sites that need
    // additional concerns (a 403 special case, etc.) handle those
    // inline before the call.
    function handleEndpointError(xhr, defaultMsg) {
        hideSavingOverlay();
        let message = defaultMsg;
        const responseText = xhr && xhr.responseText ? xhr.responseText : '';
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

    function readTgGroupRows() {
        const order = [];
        const entriesById = new Map();
        $('#tgBotGroupList .tgBotGroupCard').each(function() {
            const $card = $(this);
            const id = String($card.find('.tgBotGroupIdInput').val() || '').trim();
            if (!id) {
                return;
            }
            const contentRaw = String($card.find('.tgBotGroupContentSelect').val() || 'SFW').toUpperCase();
            const content = contentRaw === 'NSFW' ? 'NSFW' : 'SFW';
            const permissions = [];
            $card.find('.tgBotGroupPerm:checked').each(function() {
                const val = String($(this).val() || '').trim();
                if (val && permissions.indexOf(val) === -1) {
                    permissions.push(val);
                }
            });
            if (!entriesById.has(id)) {
                order.push(id);
                entriesById.set(id, { id, content, permissions: permissions.slice() });
                return;
            }
            const existing = entriesById.get(id);
            if (content === 'NSFW') {
                existing.content = 'NSFW';
            }
            permissions.forEach((perm) => {
                if (existing.permissions.indexOf(perm) === -1) {
                    existing.permissions.push(perm);
                }
            });
        });
        return order.map((id) => entriesById.get(id)).filter(Boolean);
    }

    function createTgBotGroupCard() {
        const deleteIcon = $('#tgBotGroupDeleteIcon').html() || '';
        return `
            <div class="tgBotGroupCard">
                <input class="linksConfigInput tgBotGroupIdInput" type="text" value="" placeholder="-1001234567890" aria-label="Group ID">
                <select class="linksConfigInput tgBotGroupContentSelect" aria-label="Content level">
                    <option value="SFW" selected>SFW</option>
                    <option value="NSFW">NSFW</option>
                </select>
                <label class="tgBotGroupPermCell" title="Edit site content (header, panes, links).">
                    <input type="checkbox" class="tgBotGroupPerm" value="edit_site" aria-label="Edit site">
                </label>
                <label class="tgBotGroupPermCell" title="Create new user accounts.">
                    <input type="checkbox" class="tgBotGroupPerm" value="add_users" aria-label="Add users">
                </label>
                <label class="tgBotGroupPermCell" title="Edit existing user accounts.">
                    <input type="checkbox" class="tgBotGroupPerm" value="edit_users" aria-label="Edit users">
                </label>
                <label class="tgBotGroupPermCell" title="Remove user accounts.">
                    <input type="checkbox" class="tgBotGroupPerm" value="remove_users" aria-label="Remove users">
                </label>
                <button class="iconButton removeTgBotGroup" type="button" aria-label="Remove group" title="Remove group">
                    ${deleteIcon}
                </button>
            </div>
        `;
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

    window.openAdminModal = openAdminModal;
    window.closeAdminModal = closeAdminModal;

    function resetAdminModalState() {
        $('.userModalOverlay').removeClass('isOpen').attr('aria-hidden', 'true');
        modalStack.length = 0;
        activeModal = null;
        setModalBackgroundState(false);
        $(document).off('keydown.adminModal');
        if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
            lastFocusedElement.focus();
        }
        lastFocusedElement = null;
    }

    // Bind Help button
    $('.helpTutorial').on('click', function() {
        startTutorial();
    });

    // Notice banners — admin-side thin wrapper around the shared manager in
    // notice-core.js. The public site (app.js) has its own wrapper using the
    // same factory. skipActionBearing is admin-only: banners that contain a
    // form or button stay onscreen until the user dismisses them, so a save
    // dialog isn't yanked away mid-interaction.
    //
    // Initialized BEFORE the bind* sequence below because bindAdminNotices()
    // references noticeManager — a TDZ error there silently halted init in
    // earlier code, leaving initialSnapshot null and breaking the save flow.
    const noticeManager = window.lpNoticeFactory({ skipActionBearing: true });

    function addAdminNotice(type, text, options) {
        return noticeManager.add(type, text, options);
    }

    window.addAdminNotice = addAdminNotice;

    bindLinksControls();
    bindAuthLinksControls();
    bindBackgroundControls();
    initBackgroundSettings();
    bindSaveHandler();
    bindUserActions();
    bindPaneManagement();
    bindMigrationFlow();
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
        const $list = $('#linksConfig .linksConfigList');

        if (!$list.length) {
            return;
        }

        // Move up
        $list.on('click', '.moveUpLink', function() {
            const $card = $(this).closest('.linksConfigCard');
            const $prev = $card.prev('.linksConfigCard');
            if ($prev.length) {
                $card.insertBefore($prev);
                refreshLinkControls($list);
            }
        });

        // Move down
        $list.on('click', '.moveDownLink', function() {
            const $card = $(this).closest('.linksConfigCard');
            const $next = $card.next('.linksConfigCard');
            if ($next.length) {
                $card.insertAfter($next);
                refreshLinkControls($list);
            }
        });

        // Delete
        $list.on('click', '.deleteLink', function() {
            $(this).closest('.linksConfigCard').remove();
            refreshLinkControls($list);
        });

        // Add link
        $('.addLink').on('click', function() {
            const $newCard = $(createLinkCard());
            $list.append($newCard);
            updateLinkIdForCard($newCard);
            refreshLinkControls($list);
            scrollListToBottom($list);
        });

        // Add separator
        $('.addSeparator').on('click', function() {
            const $newCard = $(createSeparatorCard());
            $list.append($newCard);
            refreshLinkControls($list);
            scrollListToBottom($list);
        });

        // Initial state
        refreshLinkControls($list);

        $list.on('input', 'input[name="linkText[]"]', function() {
            updateLinkIdForCard($(this).closest('.linksConfigCard'));
        });

        $list.find('.linksConfigCard').not('.linksConfigSeparator').each(function() {
            updateLinkIdForCard($(this));
        });

        bindLinksEditingMode();
    }

    function bindAuthLinksControls() {
        const $list = $('#authLinksConfig .authLinksConfigList');
        const $toggle = $('#authLinksToggle');
        const $tokenToggle = $('.authLinksTokenToggle');
        const $testBot = $('.authLinksTestBotButton');
        const $registerWebhook = $('.authLinksRegisterWebhookButton');
        const $validateGroups = $('.authLinksValidateGroupsButton');
        const $groupList = $('#tgBotGroupList');
        const $addGroupButton = $('.addTgBotGroup');

        if ($groupList.length) {
            $groupList.on('click', '.removeTgBotGroup', function() {
                $(this).closest('.tgBotGroupCard').remove();
            });
        }
        if ($addGroupButton.length && $groupList.length) {
            $addGroupButton.on('click', function() {
                const $newCard = $(createTgBotGroupCard());
                $groupList.append($newCard);
                $newCard.find('.tgBotGroupIdInput').trigger('focus');
            });
        }

        if (!$list.length) {
            return;
        }

        $list.on('click', '.moveUpLink', function() {
            const $card = $(this).closest('.authLinksConfigCard');
            const $prev = $card.prev('.authLinksConfigCard');
            if ($prev.length) {
                $card.insertBefore($prev);
                refreshAuthLinkControls($list);
            }
        });

        $list.on('click', '.moveDownLink', function() {
            const $card = $(this).closest('.authLinksConfigCard');
            const $next = $card.next('.authLinksConfigCard');
            if ($next.length) {
                $card.insertAfter($next);
                refreshAuthLinkControls($list);
            }
        });

        $list.on('click', '.deleteLink', function() {
            $(this).closest('.authLinksConfigCard').remove();
            refreshAuthLinkControls($list);
        });

        $('.addAuthLink').on('click', function() {
            const $newCard = $(createAuthLinkCard());
            $list.append($newCard);
            updateAuthLinkIdForCard($newCard);
            refreshAuthLinkControls($list);
            scrollListToBottom($list);
        });

        $('.addAuthSeparator').on('click', function() {
            const $newCard = $(createAuthSeparatorCard());
            $list.append($newCard);
            refreshAuthLinkControls($list);
            scrollListToBottom($list);
        });

        $list.on('input', 'input[name="authLinkText[]"]', function() {
            updateAuthLinkIdForCard($(this).closest('.authLinksConfigCard'));
        });

        $list.find('.authLinksConfigCard').not('.linksConfigSeparator').each(function() {
            updateAuthLinkIdForCard($(this));
        });

        if ($toggle.length) {
            $toggle.on('change', function() {
                const enabled = $(this).is(':checked');
                setAuthLinksVisibility(enabled);
            });
            setAuthLinksVisibility($toggle.is(':checked'));
        }

        if ($tokenToggle.length) {
            $tokenToggle.on('click', function() {
                const $button = $(this);
                const targetSelector = $button.data('target') || '#tgBotToken';
                const $input = $(targetSelector);
                const isVisible = $button.attr('data-visible') === 'true';
                const labelShow = $button.data('aria-show') || 'Show token';
                const labelHide = $button.data('aria-hide') || 'Hide token';
                $input.attr('type', isVisible ? 'password' : 'text');
                $button.attr('data-visible', isVisible ? 'false' : 'true');
                $button.attr('aria-label', isVisible ? labelShow : labelHide);
                const icon = isVisible
                    ? $button.data('icon-closed')
                    : $button.data('icon-open');
                if (icon) {
                    $button.html(icon);
                }
            });
            $tokenToggle.each(function() {
                const $button = $(this);
                if (!$button.data('icon-open')) {
                    const open = $('#tgBotTokenToggleOpen').html() || '';
                    $button.data('icon-open', open);
                }
                if (!$button.data('icon-closed')) {
                    const closed = $('#tgBotTokenToggleClosed').html() || '';
                    $button.data('icon-closed', closed);
                }
            });
        }

        if ($testBot.length) {
            $testBot.on('click', function() {
                const basePath = lpGetBasePath();
                const proxyPath = '/res/scr/plugin-endpoint.php?plugin=telegram&endpoint=test';
                const url = basePath ? `${basePath}${proxyPath}` : proxyPath;
                const csrfToken = (window.appConfig && window.appConfig.csrfToken) || '';
                const body = new URLSearchParams();
                body.append('csrf_token', csrfToken);
                fetch(url, { method: 'POST', body })
                    .then((resp) => resp.json())
                    .then((data) => {
                        const ok = data && data.ok;
                        const desc = data && data.description ? String(data.description) : 'No response message.';
                        const expected = 'Expected: ok=true with a bot username/id if the token is valid.';
                        alert(`${ok ? 'OK' : 'FAILED'}: ${desc}\n${expected}`);
                    })
                    .catch((err) => {
                        alert(`FAILED: ${err && err.message ? err.message : 'Request failed.'}\nExpected: ok=true with a bot username/id if the token is valid.`);
                    });
            });
        }

        if ($registerWebhook.length) {
            $registerWebhook.on('click', function() {
                const basePath = lpGetBasePath();
                const proxyPath = '/res/scr/plugin-endpoint.php?plugin=telegram&endpoint=register-webhook';
                const url = basePath ? `${basePath}${proxyPath}` : proxyPath;
                const csrfToken = (window.appConfig && window.appConfig.csrfToken) || '';
                const body = new URLSearchParams();
                body.append('csrf_token', csrfToken);
                fetch(url, { method: 'POST', body })
                    .then((resp) => resp.json())
                    .then((data) => {
                        const ok = data && data.ok;
                        const desc = data && data.description ? String(data.description) : 'No response message.';
                        const expected = 'Expected: ok=true with the registered webhook URL.';
                        alert(`${ok ? 'OK' : 'FAILED'}: ${desc}\n${expected}`);
                    })
                    .catch((err) => {
                        alert(`FAILED: ${err && err.message ? err.message : 'Request failed.'}\nExpected: ok=true with the registered webhook URL.`);
                    });
            });
        }

        if ($validateGroups.length) {
            $validateGroups.on('click', function() {
                const basePath = lpGetBasePath();
                const proxyPath = '/res/scr/plugin-endpoint.php?plugin=telegram&endpoint=validate-groups';
                const url = basePath ? `${basePath}${proxyPath}` : proxyPath;
                const groupIds = readTgGroupRows().map((entry) => entry.id);
                if (!groupIds.length) {
                    alert('No group IDs found. Add at least one ID and try again.');
                    return;
                }
                const csrfToken = (window.appConfig && window.appConfig.csrfToken) || '';
                const body = new URLSearchParams();
                body.append('csrf_token', csrfToken);
                groupIds.forEach((id) => body.append('group_ids[]', id));
                fetch(url, {
                    method: 'POST',
                    body
                })
                    .then((resp) => resp.json())
                    .then((data) => {
                        if (!data || !data.ok) {
                            const desc = data && data.description ? String(data.description) : 'Validation failed.';
                            alert(`FAILED: ${desc}`);
                            return;
                        }
                        const valid = Array.isArray(data.valid) ? data.valid : [];
                        const invalid = Array.isArray(data.invalid) ? data.invalid : [];
                        if (!invalid.length) {
                            alert(`OK: All ${valid.length} group ID(s) are valid.`);
                            return;
                        }
                        const messages = invalid.map((id) => {
                            const reason = data.errors && data.errors[id] ? data.errors[id] : 'Unknown error';
                            return `${id}: ${reason}`;
                        });
                        alert(`Some group IDs failed:\n${messages.join('\n')}`);
                    })
                    .catch((err) => {
                        alert(`FAILED: ${err && err.message ? err.message : 'Request failed.'}`);
                    });
            });
        }

        refreshAuthLinkControls($list);
    }

    // Disable the up/down move arrows at list boundaries. Shared
    // algorithm for the three reorderable lists in the admin
    // (links, auth links, backgrounds); each public wrapper just
    // queries its own row collection and delegates here.
    function refreshListControls($items) {
        $items.find('.moveUpLink, .moveDownLink').prop('disabled', false);
        if ($items.length === 0) {
            return;
        }
        $items.first().find('.moveUpLink').prop('disabled', true);
        $items.last().find('.moveDownLink').prop('disabled', true);
        if ($items.length === 1) {
            $items.first().find('.moveDownLink').prop('disabled', true);
        }
    }

    function refreshLinkControls($list) {
        refreshListControls($list ? $list.find('.linksConfigCard') : $('.linksConfigCard'));
    }

    function buildLinkIdFromText(text, prefix = 'link') {
        const words = (text || '').match(/[a-z0-9]+/gi) || [];
        if (!words.length) {
            return '';
        }
        const pascal = words
            .map((word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
            .join('');
        return `${prefix}${pascal}`;
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
        const selector = '.linksConfigInput[name="linkText[]"], .linksConfigInput[name="linkUrl[]"], .linksConfigInput[name="linkTitle[]"], .linksConfigInput[name="authLinkText[]"], .linksConfigInput[name="authLinkUrl[]"], .linksConfigInput[name="authLinkTitle[]"], .linksConfigIdValue';
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

        // Move up
        $bgList.on('click', '.moveUpLink', function() {
            const $row = $(this).closest('.bgConfigRow');
            const $prev = $row.prevAll('.bgConfigRow').not('.bgConfigHeader').first();
            if ($prev.length) {
                $row.insertBefore($prev);
                updateBackgroundRowIndexes();
                refreshBackgroundControls();
            }
        });

        // Move down
        $bgList.on('click', '.moveDownLink', function() {
            const $row = $(this).closest('.bgConfigRow');
            const $next = $row.nextAll('.bgConfigRow').not('.bgConfigHeader').first();
            if ($next.length) {
                $row.insertAfter($next);
                updateBackgroundRowIndexes();
                refreshBackgroundControls();
            }
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

    function updateBackgroundRowIndexes() {
        $('#bgConfig')
            .find('.bgConfigList .bgConfigRow')
            .not('.bgConfigHeader')
            .each(function(index) {
                $(this).attr('data-index', index);
            });
    }

    function refreshBackgroundControls() {
        refreshListControls($('#bgConfig').find('.bgConfigList .bgConfigRow').not('.bgConfigHeader'));
    }

    function initBackgroundSettings() {
        const $mode = $('#bgModeSelect');
        const $duration = $('#bgDurationInput');
        if (!$mode.length || !$duration.length) {
            return;
        }
        const headerSettings = window.headerData && typeof window.headerData === 'object'
            ? window.headerData.backgroundSettings
            : null;
        const settings = headerSettings && typeof headerSettings === 'object'
            ? headerSettings
            : {};
        const mode = settings.mode || defaultBackgroundSettings.mode;
        const durationRaw = parseInt(settings.duration, 10);
        const duration = Number.isFinite(durationRaw) && durationRaw > 0
            ? durationRaw
            : defaultBackgroundSettings.duration;
        $mode.val(mode);
        $duration.val(String(duration));
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
            .then(({ ok, status, data }) => {
                if (!ok) {
                    const message = data && data.error ? data.error : 'Background upload failed.';
                    addAdminNotice(status === 413 ? 'warning' : 'danger', message);
                    hideSavingOverlay();
                    return;
                }
                renderBackgrounds(data.backgrounds || []);
                initialSnapshot.backgrounds = getBackgroundsData();
                addAdminNotice('ok', 'Background uploaded.');
                if (data.gd_unavailable && !gdNoticeShown) {
                    gdNoticeShown = true;
                    addAdminNotice('ok', 'For better performance, install the PHP GD extension on your server.');
                }
                hideSavingOverlay();
            })
            .catch((error) => {
                console.error('Background upload failed', error);
                addAdminNotice('danger', 'Background upload failed. Please try again.');
                hideSavingOverlay();
            });
    }

    function scrollBgListToBottom() {
        const $bgList = $('#bgConfig .bgConfigList');
        if ($bgList.length) {
            $bgList.scrollTop($bgList[0].scrollHeight);
        }
    }

    // Save handler: gather data and POST to save endpoint.
    function bindSaveHandler() {
        const basePath = lpGetBasePath();
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
                if (currentSnapshot.header.backgroundSettings) {
                    formData.append('backgroundMode', currentSnapshot.header.backgroundSettings.mode || defaultBackgroundSettings.mode);
                    formData.append('backgroundDuration', String(currentSnapshot.header.backgroundSettings.duration || defaultBackgroundSettings.duration));
                }
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
            // Authorized links
            if (!isEqualSnapshot(currentSnapshot.authLinks, initialSnapshot.authLinks) || authLinksNeedsNormalization) {
                formData.append('authorizedLinks', JSON.stringify(currentSnapshot.authLinks));
                hasChanges = true;
            }
            // Telegram bot settings
            if (!isEqualSnapshot(currentSnapshot.tgBot, initialSnapshot.tgBot)) {
                formData.append('tgBot', JSON.stringify(currentSnapshot.tgBot));
                hasChanges = true;
            }

            // Backgrounds
            if (!isEqualSnapshot(currentSnapshot.backgrounds, initialSnapshot.backgrounds)) {
                formData.append('backgrounds', JSON.stringify(currentSnapshot.backgrounds));
                hasChanges = true;
            } else {
                const authorChanges = getBackgroundAuthorChanges(currentSnapshot.backgrounds, initialSnapshot.backgrounds);
                if (authorChanges.length > 0) {
                    formData.append('backgroundAuthors', JSON.stringify(authorChanges));
                    hasChanges = true;
                }
            }

            // Pane data (save_map entries)
            if (appendPaneChanges(formData, currentSnapshot.panes, initialSnapshot.panes)) {
                hasChanges = true;
            }

            // Site Config (lp-siteConfig.json). Bracket-notation field names
            // unpack into $_POST['siteConfig'][<module>][<key>] server-side.
            // The hidden __rendered marker tells the PHP handler to honor
            // this submission even if every box is unchecked (otherwise an
            // all-unchecked submit would have no siteConfig keys at all).
            let siteConfigChanged = false;
            if (currentSnapshot.siteConfig && !isEqualSnapshot(currentSnapshot.siteConfig, initialSnapshot.siteConfig)) {
                formData.append('siteConfig[__rendered]', '1');
                Object.keys(currentSnapshot.siteConfig).forEach((module) => {
                    const flags = currentSnapshot.siteConfig[module] || {};
                    Object.keys(flags).forEach((key) => {
                        const value = flags[key];
                        // Bool: append "1" only when checked (HTML form
                        // semantics; PHP reads !empty()).
                        // Number: always append, including 0, so the int
                        // value lands in $_POST verbatim.
                        if (typeof value === 'boolean') {
                            if (value) {
                                formData.append('siteConfig[' + module + '][' + key + ']', '1');
                            }
                        } else if (typeof value === 'number') {
                            formData.append('siteConfig[' + module + '][' + key + ']', String(value));
                        }
                    });
                });
                hasChanges = true;
                siteConfigChanged = true;
            }

            if (!hasChanges) {
                addAdminNotice('warning', 'No changes to save.');
                return;
            }

            if (!validatePaneInlineErrors()) {
                addAdminNotice('danger', 'Please fix the highlighted errors before saving.');
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
                    if (siteConfigChanged) {
                        // Site config drives server-side conditional rendering
                        // (gear modal checkbox states, per-item modal button
                        // visibility) that JS state propagation can't refresh
                        // in place. Hard-reload so every conditional re-derives.
                        addAdminNotice('ok', 'Site config saved. Reloading…', { persist: true });
                        setTimeout(function() { window.location.reload(); }, 600);
                        return;
                    }
                    addAdminNotice('ok', 'Changes saved.');
                    if (resp && resp.gd_unavailable && !gdNoticeShown) {
                        gdNoticeShown = true;
                        addAdminNotice('ok', 'For better performance, install the PHP GD extension on your server.');
                    }
                    let refreshPromise = Promise.resolve();
                    if (typeof window.refreshEventListUIs === 'function') {
                        window.refreshEventListUIs();
                    }
                    if (typeof window.refreshMediaGalleryUIs === 'function') {
                        const result = window.refreshMediaGalleryUIs();
                        if (result && typeof result.then === 'function') {
                            refreshPromise = result;
                        }
                    }
                    refreshPromise.finally(function() {
                        authLinksNeedsNormalization = false;
                        $('#authLinksConfig').attr('data-needs-normalization', 'false');
                        initialSnapshot = captureSnapshot();
                        pendingLogoFile = null;
                        hideSavingOverlay();
                    });
                },
                error: function(xhr) {
                    if (xhr && xhr.status === 403) {
                        addAdminNotice('danger', 'You do not have permission to edit site content.');
                        hideSavingOverlay();
                        return;
                    }
                    handleEndpointError(xhr, 'Save failed. Please try again.');
                }
            });
        });
    }

    function getHeaderData() {
        const mode = $('#bgModeSelect').val() || defaultBackgroundSettings.mode;
        const durationRaw = parseInt($('#bgDurationInput').val(), 10);
        const duration = Number.isFinite(durationRaw) && durationRaw > 0
            ? durationRaw
            : defaultBackgroundSettings.duration;
        return {
            title: $('.headlineInput[name="siteTitle"]').val() || '',
            subtitle: $('.headlineInput[name="siteSubtitle"]').val() || '',
            backgroundSettings: {
                mode,
                duration
            }
        };
    }

    function getLinksData() {
        const links = [];
        $('#linksConfig .linksConfigCard').each(function() {
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
        const showLinks = $('#linksVisibleToggle').is(':checked');
        const authLinksEnabled = $('#authLinksToggle').is(':checked');
        return {
            settings: {
                show_links: showLinks,
                auth_links: authLinksEnabled
            },
            links
        };
    }

    function getAuthLinksData() {
        const links = [];
        $('#authLinksConfig .authLinksConfigCard').each(function() {
            const $card = $(this);
            if ($card.hasClass('linksConfigSeparator')) {
                links.push({ type: 'separator' });
                return;
            }
            const id = $card.find('input[name="authLinkId[]"]').val() || '';
            const href = $card.find('input[name="authLinkUrl[]"]').val() || '';
            const text = $card.find('input[name="authLinkText[]"]').val() || '';
            const title = $card.find('input[name="authLinkTitle[]"]').val() || '';
            const fullWidth = $card.find('input[name="authLinkFullWidth[]"]').is(':checked');
            const cta = $card.find('input[name="authLinkCta[]"]').is(':checked');
            const content = $card.find('input[name="authLinkNsfw[]"]').is(':checked') ? 'nsfw' : 'sfw';
            links.push({
                type: 'link',
                id,
                href,
                text,
                title,
                fullWidth,
                cta,
                content
            });
        });
        return { links };
    }

    function parseTgUserEntries(rawValue) {
        const lines = String(rawValue || '').split('\n').map((v) => v.trim()).filter((v) => v.length > 0);
        const order = [];
        const entriesById = new Map();
        lines.forEach((line) => {
            const parts = line.split(/\s+/).filter((v) => v.length > 0);
            if (!parts.length) { return; }
            const id = parts[0];
            if (!/^-?\d+$/.test(id)) { return; }
            const content = parts[1] && /^nsfw$/i.test(parts[1]) ? 'NSFW' : 'SFW';
            if (!entriesById.has(id)) {
                order.push(id);
                entriesById.set(id, { id, content });
                return;
            }
            if (content === 'NSFW') { entriesById.set(id, { id, content: 'NSFW' }); }
        });
        return order.map((id) => entriesById.get(id)).filter(Boolean);
    }

    function getTgBotData() {
        const token = ($('#tgBotToken').val() || '').trim();
        const webhookSecret = ($('#tgBotWebhookSecret').val() || '').trim();
        const username = ($('#tgBotUsername').val() || '').trim();
        const ttlRaw = ($('#tgBotCacheTtl').val() || '').trim();
        const ttlValue = parseInt(ttlRaw, 10);
        const ttl = Number.isFinite(ttlValue) && ttlValue > 0 ? ttlValue : 30;
        const message = ($('#tgBotUnauthorizedMessage').val() || '').trim();
        const entries = readTgGroupRows();
        entries.sort((a, b) => a.id.localeCompare(b.id, undefined, { numeric: true }));
        const whitelistEntries = parseTgUserEntries($('#tgBotWhitelistUserIds').val() || '');
        const blacklistEntries = parseTgUserEntries($('#tgBotBlacklistUserIds').val() || '');
        whitelistEntries.sort((a, b) => a.id.localeCompare(b.id, undefined, { numeric: true }));
        blacklistEntries.sort((a, b) => a.id.localeCompare(b.id, undefined, { numeric: true }));
        return {
            bot_username: username,
            bot_token: token,
            webhook_secret_token: webhookSecret,
            group_ids: entries,
            whitelist_user_ids: whitelistEntries,
            blacklist_user_ids: blacklistEntries,
            membership_cache_ttl_minutes: ttl,
            unauthorized_message: message
        };
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

    function getSiteConfigData() {
        // Walks every checkbox or number input inside #siteConfig with a
        // name shaped like siteConfig[<module>][<key>], so new flags added
        // to lawnding_site_config_defaults() (and rendered by the PHP form)
        // flow through the snapshot/diff path without any JS updates.
        // Type detection mirrors the PHP form: checkboxes become bools,
        // number inputs become ints. Returns null when the section isn't
        // rendered (user lacks edit_site) so the snapshot diff stays clean.
        const $section = $('#siteConfig');
        if (!$section.length) {
            return null;
        }
        const data = {};
        $section.find('input[type="checkbox"][name^="siteConfig["], input[type="number"][name^="siteConfig["]').each(function() {
            const $input = $(this);
            const name = $input.attr('name') || '';
            const m = name.match(/^siteConfig\[([^\]]+)\]\[([^\]]+)\]$/);
            if (!m) {
                return; // skips siteConfig[__rendered] and any non-flag inputs
            }
            const moduleKey = m[1];
            const flagKey = m[2];
            if (!data[moduleKey]) {
                data[moduleKey] = {};
            }
            if ($input.attr('type') === 'checkbox') {
                data[moduleKey][flagKey] = $input.is(':checked');
            } else {
                const parsed = parseInt($input.val(), 10);
                data[moduleKey][flagKey] = Number.isFinite(parsed) ? parsed : 0;
            }
        });
        return data;
    }

    function captureSnapshot() {
        return {
            header: getHeaderData(),
            links: getLinksData(),
            authLinks: getAuthLinksData(),
            tgBot: getTgBotData(),
            backgrounds: getBackgroundsData(),
            panes: getPaneSaveData(),
            siteConfig: getSiteConfigData()
        };
    }

    // Save All gate. Modules that do inline per-card validation populate
    // .eventValidation elements on input; this checks whether any pane
    // currently shows a non-empty error and blocks the save if so.
    function validatePaneInlineErrors() {
        let isValid = true;
        $('.eventValidation').each(function() {
            if (($(this).text() || '').trim() !== '') {
                isValid = false;
                return false;
            }
        });
        return isValid;
    }

    function isEqualSnapshot(a, b) {
        return JSON.stringify(a) === JSON.stringify(b);
    }

    function findReservedLinkIds() {
        const reservedIds = getReservedIdSet();
        const ids = [];
        $('#linksConfig .linksConfigCard').not('.linksConfigSeparator').each(function() {
            const value = $(this).find('input[name="linkId[]"]').val() || '';
            const trimmed = value.trim();
            if (trimmed) {
                ids.push(trimmed);
            }
        });
        $('#authLinksConfig .authLinksConfigCard').not('.linksConfigSeparator').each(function() {
            const value = $(this).find('input[name="authLinkId[]"]').val() || '';
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

    function setAuthLinksVisibility(enabled) {
        const $pane = $('#authLinks');
        const $navItem = $('.authLinksNavItem');
        const $navLink = $navItem.find('.navLink[data-pane="authLinks"]');
        if (enabled) {
            $pane.removeClass('hidden');
            $navItem.removeClass('isHidden');
            $navLink.removeClass('hidden');
        } else {
            $pane.addClass('hidden');
            $navItem.addClass('isHidden');
            $navLink.addClass('hidden');
            if ($('.navLink.navActive[data-pane="authLinks"]').length) {
                $('.navLink[data-pane="links"]').trigger('click');
            }
        }
        if (typeof window.lawndingRebuildPaneOrder === 'function') {
            window.lawndingRebuildPaneOrder();
        }
    }

    function refreshAuthLinkControls($list) {
        refreshListControls($list ? $list.find('.authLinksConfigCard') : $('.authLinksConfigCard'));
    }

    function updateAuthLinkIdForCard($card) {
        if (!$card.length || $card.hasClass('linksConfigSeparator')) {
            return;
        }
        const $textInput = $card.find('input[name="authLinkText[]"]');
        const $idInput = $card.find('input[name="authLinkId[]"]');
        const $idValue = $card.find('.linksConfigIdValue');
        const textValue = $textInput.val() || '';
        const generated = buildLinkIdFromText(textValue, 'authLink');
        const nextId = generated || ($idInput.val() || '');
        $idInput.val(nextId);
        $idValue.text(`#${nextId}`);
        $idValue.attr('aria-label', `ID ${nextId}`);
        updateReservedIdState($idInput);
    }

    function createAuthLinkCard() {
        authLinkCounter += 1;
        const uniqueId = `authLink${authLinkCounter}`;
        return `
            <div class="linksConfigCard authLinksConfigCard">
                <div class="linksConfigRow">
                    <label class="linksConfigField" title="The label that is displayed for each link."><span class="linksConfigLabelText">Name</span>
                        <input class="linksConfigInput" type="text" name="authLinkText[]" value="" placeholder="Display text" title="The label that is displayed for each link.">
                    </label>
                    <div class="linksConfigField linksConfigIdField" title="The internal HTML ID of the link.  Make it unique.">
                        <span class="linksConfigLabelText">ID</span>
                        <span class="linksConfigIdValue" tabindex="0" aria-label="ID ${uniqueId}">#${uniqueId}</span>
                        <input class="linksConfigIdInput" type="hidden" name="authLinkId[]" value="${uniqueId}">
                    </div>
                </div>
                <div class="linksConfigRow">
                    <label class="linksConfigField" title="The full URL (https: and all) to link to."><span class="linksConfigLabelText">URL</span>
                        <input class="linksConfigInput" type="text" name="authLinkUrl[]" value="" placeholder="Link URL" title="The full URL (https: and all) to link to.">
                    </label>
                    <label class="linksConfigField" title="The text that appears when the user hovers over a link."><span class="linksConfigLabelText">Tooltip</span>
                        <input class="linksConfigInput" type="text" name="authLinkTitle[]" value="" placeholder="Title attribute" title="The text that appears when the user hovers over a link.">
                    </label>
                </div>
                <div class="linksConfigRow linksConfigToggles">
                    <label class="linksConfigCheckbox" title="If checked, the link takes up the full width of the links pane.  Otherwise, it'll take up half of the width.">
                        <input type="checkbox" name="authLinkFullWidth[]" title="If checked, the link takes up the full width of the links pane.  Otherwise, it'll take up half of the width.">
                        Full width
                    </label>
                    <label class="linksConfigCheckbox" title="AKA Call to Action.  If checked, the link appears more prominently than the others.  Ideally, you will only want to use one, but you can set multiple links as a CTA button.">
                        <input type="checkbox" name="authLinkCta[]" title="AKA Call to Action.  If checked, the link appears more prominently than the others.  Ideally, you will only want to use one, but you can set multiple links as a CTA button.">
                        CTA
                    </label>
                    <label class="linksConfigCheckbox" title="If checked, this authorized link is marked as NSFW content. If unchecked, it is treated as SFW.">
                        <input type="checkbox" name="authLinkNsfw[]" title="If checked, this authorized link is marked as NSFW content. If unchecked, it is treated as SFW.">
                        NSFW
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

    function createAuthSeparatorCard() {
        return `
            <div class="linksConfigCard authLinksConfigCard linksConfigSeparator">
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
            'authlinks',
            'authlinksconfig',
            'authlinkslogin',
            'authlinksnotice',
            'tgbottoken',
            'tgbotusername',
            'tgbotgroupids',
            'tgbotcachettl',
            'tgbotunauthorizedmessage',
            'tgbottokentoggleclosed',
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
        const basePath = lpGetBasePath();
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
        const $bgList = $('#bgConfig .bgConfigList');
        $bgList.find('.bgConfigRow').not('.bgConfigHeader').remove();

        if (!Array.isArray(backgrounds)) {
            return;
        }

        const moveUpIcon = $('.linksConfig .moveUpLink').first().html() || 'Up';
        const moveDownIcon = $('.linksConfig .moveDownLink').first().html() || 'Down';
        const deleteIcon = $('.linksConfig .deleteLink').first().html() || 'Delete';

        backgrounds.forEach((bg, index) => {
            const url = bg && typeof bg.url === 'string' ? bg.url : '';
            const displayUrl = bg && typeof bg.displayUrl === 'string' ? bg.displayUrl : url;
            const author = bg && typeof bg.author === 'string' ? bg.author : '';
            const authorUrl = bg && typeof bg.authorUrl === 'string' ? bg.authorUrl : '';
            const originalSize = bg && parseInt(bg.original_size, 10) > 0 ? parseInt(bg.original_size, 10) : 0;
            const savedSize = bg && parseInt(bg.saved_size, 10) > 0 ? parseInt(bg.saved_size, 10) : 0;
            const sizeAttr = originalSize > 0
                ? ` data-size-info="Original: ${lpFormatBytes(originalSize)}\nResized:  ${lpFormatBytes(savedSize)}"`
                : '';
            const isEmpty = !displayUrl;
            const row = `
                <div class="bgConfigRow" data-current-url="${lpEscapeHtml(url)}" data-author-url="${lpEscapeHtml(authorUrl)}" data-index="${index}">
                    <div class="bgThumbWrap ${isEmpty ? 'empty' : ''}"${sizeAttr}>
                        <img class="bgThumb" src="${lpEscapeHtml(displayUrl)}" alt="Background preview">
                    </div>
                    <input class="bgAuthorInput" type="text" name="bgAuthor[]" value="${lpEscapeHtml(author)}" placeholder="Author">
                    <input class="bgAuthorUrlInput" type="text" name="bgAuthorUrl[]" value="${lpEscapeHtml(authorUrl)}" placeholder="URL">
                    <div class="bgRowActions">
                        <button class="moveUpLink iconButton" type="button" title="Move background up" aria-label="Move background up">${moveUpIcon}</button>
                        <button class="moveDownLink iconButton" type="button" title="Move background down" aria-label="Move background down">${moveDownIcon}</button>
                        <button class="bgChange iconButton" type="button" title="Change background image" aria-label="Change background image">Change</button>
                        <button class="deleteBackground usersDanger iconButton" type="button" aria-label="Delete background" title="Remove this background">${deleteIcon}</button>
                    </div>
                </div>
            `;
            $bgList.append(row);
        });

        updateBackgroundRowIndexes();
        refreshBackgroundControls();
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
            const isTargetMaster = $row.data('master') === true || $row.data('master') === 'true';
            const isTargetReadOnly = $row.data('readonly') === true || $row.data('readonly') === 'true';
            const $readOnlyItem = $('#readOnlyPermissionItem');
            const $readOnlyToggle = $('#readOnlyToggle');
            if ($readOnlyItem.length && $readOnlyToggle.length) {
                if (isTargetMaster) {
                    $readOnlyToggle.prop('checked', false);
                    $readOnlyItem.addClass('hidden');
                } else {
                    $readOnlyItem.removeClass('hidden');
                    $readOnlyToggle.prop('checked', isTargetReadOnly);
                }
            }
            applyPermissionsModalState($permissionsModal);
            if (window.appConfig && window.appConfig.isReadOnlyUser) {
                $permissionsModal.find('button[type="submit"]').prop('disabled', true);
            }

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
            applyPermissionsModalState($('#permissionsModal'));
        });

        $(document).on('change', '#readOnlyToggle', function() {
            applyPermissionsModalState($('#permissionsModal'));
        });

        $(document).on('submit', '.usersCreateForm, #permissionsForm, #removeUserForm', function(event) {
            if (this.id === 'permissionsForm') {
                if (window.appConfig && window.appConfig.isReadOnlyUser) {
                    event.preventDefault();
                    addAdminNotice('danger', 'Read-only accounts cannot update permissions.');
                    return;
                }
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
        const $readOnly = $permissionsModal.find('#readOnlyToggle');
        const $otherItems = $permissionsModal.find('.permissionsItem').filter(function() {
            const $checkbox = $(this).find('input[type="checkbox"]');
            return $checkbox.val() !== 'full_admin' && $checkbox.attr('id') !== 'readOnlyToggle';
        });
        const $otherCheckboxes = $otherItems.find('input[type="checkbox"]');

        if (isFullAdmin) {
            $otherCheckboxes.prop('checked', true).prop('disabled', true);
            $otherItems.addClass('isDisabled');
            if ($readOnly.length) {
                $readOnly.prop('checked', false).prop('disabled', true);
                $readOnly.closest('.permissionsItem').addClass('isDisabled');
            }
        } else {
            $otherCheckboxes.prop('disabled', false);
            $otherItems.removeClass('isDisabled');
            if ($readOnly.length) {
                $readOnly.prop('disabled', false);
                $readOnly.closest('.permissionsItem').removeClass('isDisabled');
            }
        }
    }

    function applyPermissionsModalState($permissionsModal) {
        const $readOnly = $permissionsModal.find('#readOnlyToggle');
        const isReadOnly = $readOnly.length && $readOnly.is(':checked');
        if (isReadOnly) {
            const $otherItems = $permissionsModal.find('.permissionsItem').filter(function() {
                return $(this).find('input[type="checkbox"]').attr('id') !== 'readOnlyToggle';
            });
            const $otherCheckboxes = $otherItems.find('input[type="checkbox"]');
            $otherCheckboxes.prop('checked', false).prop('disabled', true);
            $otherItems.addClass('isDisabled');
            return;
        }
        applyFullAdminState($permissionsModal);
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
                applyPermissionsModalState($newPermissionsModal);
                updateEditSitePermissionFromUsers();
                applySiteEditPermissions();
                resetAdminModalState();

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
                const basePath = lpGetBasePath();
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
            const seen = new Set();
            const duplicates = new Set();
            panesState.forEach((pane) => {
                const id = pane.id;
                if (!id) return;
                if (seen.has(id)) {
                    duplicates.add(id);
                } else {
                    seen.add(id);
                }
            });
            let isValid = true;
            $('.paneManageName').removeClass('isInvalid');
            panesState.forEach((pane, index) => {
                let invalid = false;
                if (!pane.name || !pane.id) {
                    invalid = true;
                }
                if (duplicates.has(pane.id)) {
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
            // .paneIconDisplay = dashboard chip; legacy .paneIconButton
            // selector kept for holdover contexts (PR2 retires it).
            $(`#${paneId} .paneIconDisplay, #${paneId} .paneIconButton`).html(html);
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
            const basePath = lpGetBasePath();
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
                    handleEndpointError(xhr, 'Pane save failed. Please try again.');
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

        // Universal gear → per-pane Settings modal. Pane-header icon
        // (.paneIconDisplay) is intentionally non-interactive.
        // .paneManageIconButton in the bulk modal still uses #paneIconModal (PR2).
        $(document).on('click', '.paneSettingsButton', function() {
            const paneId = $(this).data('pane-id') || $(this).closest('.pane').attr('id');
            if (paneId) {
                openPerPaneSettingsModal(String(paneId));
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

        // Per-pane Settings modal — universal across modules. Hydrates
        // from window.appConfig.perPaneSettings; saves POST to
        // pane-icon-save.php then pane-settings-save.php (sequenced).
        const $perPaneSettingsModal = $('#panePerPaneSettingsModal');
        let activePerPaneSettingsId = null;
        // Selected picker chip's SVG, or '' when none picked. Pre-populated
        // from the saved icon on open so "no change" detection works.
        let activePerPaneSelectedSvg = '';

        function getPerPaneSettingsData() {
            const cfg = window.appConfig && window.appConfig.perPaneSettings;
            return cfg && typeof cfg === 'object' ? cfg : { panes: {}, modules: {} };
        }

        // <fieldset disabled> cascades to all descendant form controls
        // (chip button, picker chips, module checkboxes). Toggle is hidden
        // when the module declares no per_pane_settings.
        function syncPerPaneOverridesEnabled() {
            const $useDefaultsLabel = $('.panePerPaneSettingsUseDefaults');
            const $useDefaultsInput = $('#panePerPaneSettingsUseDefaultsInput');
            const visible = !$useDefaultsLabel.is('[hidden]');
            const checked = $useDefaultsInput.prop('checked');
            $('.panePerPaneSettingsOverrides').prop('disabled', visible && checked);
        }

        function openPerPaneSettingsModal(paneId) {
            const data = getPerPaneSettingsData();
            const paneData = data.panes && data.panes[paneId];
            if (!paneData) {
                return;
            }
            activePerPaneSettingsId = paneId;

            const moduleData = (data.modules && data.modules[paneData.module]) || { default_icon: '', per_pane_settings: [] };
            const declared = Array.isArray(moduleData.per_pane_settings) ? moduleData.per_pane_settings : [];
            const hasModuleSettings = declared.length > 0;

            $('#panePerPaneSettingsModal-title').text(`${paneData.name || 'Pane'} Settings`);
            $('#panePerPaneSettingsActiveId').val(paneId);

            // Progressive disclosure: chip is the click-to-change trigger,
            // editor (picker grid) hidden until clicked.
            const icon = paneData.icon || { type: 'none', value: '' };
            const moduleDefaultIcon = moduleData.default_icon || '';

            // Preview falls back to module default for type='none' so the
            // chip shows the icon as the dashboard renders it.
            let previewIcon = icon;
            if (icon.type === 'none' && moduleDefaultIcon) {
                previewIcon = { type: 'svg', value: moduleDefaultIcon };
            }
            $('.panePerPaneSettingsIconCurrent .paneIconPreview').html(renderIconPreview(previewIcon));

            // Default state: summary visible, editor hidden.
            $('.panePerPaneSettingsIconSummary').removeAttr('hidden');
            $('.panePerPaneSettingsIconEditor').attr('hidden', 'hidden');

            // Picker entries: module default first (always), "Current"
            // entry only when the saved SVG isn't covered by default or
            // library, then library entries. Chip matching savedSvg is
            // pre-selected.
            activePerPaneSelectedSvg = icon.type === 'svg' ? icon.value : '';
            const iconLibrary = Array.isArray(data.iconLibrary) ? data.iconLibrary : [];
            const savedSvg = icon.type === 'svg' && icon.value
                ? icon.value
                : (icon.type === 'none' && moduleDefaultIcon ? moduleDefaultIcon : '');
            const $picker = $('#panePerPaneSettingsIconPicker');
            $picker.empty();

            const renderChoice = (key, label, svg, isSelected) => {
                const $choice = $('<button class="panePerPaneSettingsIconChoice" type="button" role="radio"></button>');
                $choice.attr('data-key', key);
                $choice.attr('data-svg', svg);
                $choice.attr('aria-label', label);
                $choice.attr('title', label);
                $choice.html(svg);
                $choice.attr('aria-checked', isSelected ? 'true' : 'false');
                if (isSelected) {
                    $choice.addClass('isSelected');
                }
                return $choice;
            };

            const entries = [];
            if (moduleDefaultIcon) {
                entries.push({ key: 'module-default', label: 'Module default', svg: moduleDefaultIcon });
            }
            const libraryHasSaved = savedSvg && iconLibrary.some((e) => e.svg === savedSvg);
            const savedIsModuleDefault = savedSvg && savedSvg === moduleDefaultIcon;
            if (savedSvg && !libraryHasSaved && !savedIsModuleDefault) {
                entries.push({ key: 'current', label: 'Current icon', svg: savedSvg });
            }
            iconLibrary.forEach((entry) => entries.push(entry));

            entries.forEach((entry) => {
                const isMatch = entry.svg === savedSvg;
                $picker.append(renderChoice(entry.key, entry.label, entry.svg, isMatch));
            });

            // "Use site defaults" toggle visibility + state.
            const $useDefaultsLabel = $('.panePerPaneSettingsUseDefaults');
            const $useDefaultsInput = $('#panePerPaneSettingsUseDefaultsInput');
            if (hasModuleSettings) {
                $useDefaultsLabel.removeAttr('hidden');
                $useDefaultsInput.prop('checked', !!paneData.useSiteDefaults);
            } else {
                $useDefaultsLabel.attr('hidden', 'hidden');
                $useDefaultsInput.prop('checked', true);
            }

            // Module checkboxes pre-populate from resolvedValues so
            // unchecking the toggle starts from today's effective values.
            const $moduleSection = $('.panePerPaneSettingsModuleSection');
            const $moduleControls = $('#panePerPaneSettingsModuleControls');
            $moduleControls.empty();
            if (hasModuleSettings) {
                $moduleSection.removeAttr('hidden');
                const resolved = paneData.resolvedValues || {};
                declared.forEach((entry) => {
                    const $label = $('<label class="siteConfigToggle"><input type="checkbox"><span></span></label>');
                    $label.find('input').attr('name', entry.key).prop('checked', !!resolved[entry.key]);
                    $label.find('span').text(entry.label || entry.key);
                    $moduleControls.append($label);
                });
            } else {
                $moduleSection.attr('hidden', 'hidden');
            }

            syncPerPaneOverridesEnabled();
            openAdminModal($perPaneSettingsModal);
        }

        $(document).on('change', '#panePerPaneSettingsUseDefaultsInput', function() {
            syncPerPaneOverridesEnabled();
        });

        // Chip is the click-to-change trigger; reveals the picker.
        // Every modal open resets to summary state.
        $(document).on('click', '#panePerPaneSettingsIconChange', function() {
            $('.panePerPaneSettingsIconSummary').attr('hidden', 'hidden');
            $('.panePerPaneSettingsIconEditor').removeAttr('hidden');
        });

        // Picker click — clicked chip's SVG becomes the proposed icon
        // and the chip preview updates live (no save needed to see it).
        $(document).on('click', '.panePerPaneSettingsIconChoice', function() {
            const svg = $(this).attr('data-svg') || '';
            if (svg === '') {
                return;
            }
            activePerPaneSelectedSvg = svg;
            $('.panePerPaneSettingsIconChoice').removeClass('isSelected').attr('aria-checked', 'false');
            $(this).addClass('isSelected').attr('aria-checked', 'true');
            $('.panePerPaneSettingsIconCurrent .paneIconPreview').html(renderIconPreview({ type: 'svg', value: svg }));
        });

        function refreshPaneAfterSave(paneId) {
            const data = getPerPaneSettingsData();
            const paneData = data.panes && data.panes[paneId];
            if (!paneData) {
                return;
            }
            const moduleData = (data.modules && data.modules[paneData.module]) || {};
            // type='none' renders as module default_icon (matches PHP renderer).
            let previewIcon = paneData.icon || { type: 'none', value: '' };
            if (previewIcon.type === 'none' && moduleData.default_icon) {
                previewIcon = { type: 'svg', value: moduleData.default_icon };
            }
            const html = `<span class="paneIconPreview">${renderIconPreview(previewIcon)}</span>`;
            // Dashboard chip + sidebar nav.
            $(`#${paneId} .paneIconDisplay`).html(html);
            $(`.navPaneItem[data-pane-id="${paneId}"] .navLink`).html(renderIconPreview(previewIcon));

            // Keep panesState coherent so the legacy management modal
            // shows the current icon if opened later.
            const stateIdx = panesState.findIndex((entry) => entry.id === paneId);
            if (stateIdx >= 0) {
                panesState[stateIdx].icon = paneData.icon;
                $paneList.find(`.paneManageRow[data-pane-index="${stateIdx}"] .paneManageIconButton`).html(html);
            }
        }

        // Returns the icon-save POST body, null when unchanged (caller
        // skips the icon roundtrip — the existing icon must survive a
        // toggle-only save), or {error:...} for unsafe SVG.
        function resolvePerPaneSettingsIconPayload(paneData) {
            const existingType = (paneData.icon && paneData.icon.type) || 'none';
            const existingValue = (paneData.icon && paneData.icon.value) || '';

            // Toggle-on forces icon to clear (pane inherits site default).
            const $defaultsLabel = $('.panePerPaneSettingsUseDefaults');
            const $defaultsInput = $('#panePerPaneSettingsUseDefaultsInput');
            const useSiteDefaults = $defaultsInput.prop('checked') && !$defaultsLabel.is('[hidden]');
            if (useSiteDefaults) {
                if (existingType === 'none') {
                    return null; // already cleared; no-op
                }
                return { type: 'none', svg: '', file: null };
            }

            const svg = (activePerPaneSelectedSvg || '').trim();
            if (svg === '') {
                // Nothing picked — preserve the saved icon.
                return null;
            }
            if (svg.indexOf('<script') !== -1 || /\son[a-z]+\s*=\s*["']?/i.test(svg)) {
                return { error: 'SVG icons cannot contain scripts or inline event handlers.' };
            }
            if (existingType === 'svg' && svg === existingValue) {
                return null; // unchanged
            }
            return { type: 'svg', svg: svg, file: null };
        }

        $(document).on('click', '#panePerPaneSettingsSave', function() {
            if (!activePerPaneSettingsId) {
                return;
            }
            const paneId = activePerPaneSettingsId;
            const data = getPerPaneSettingsData();
            const paneData = data.panes && data.panes[paneId];
            if (!paneData) {
                return;
            }
            const moduleId = paneData.module || '';

            const iconPayload = resolvePerPaneSettingsIconPayload(paneData);
            if (iconPayload && iconPayload.error) {
                addAdminNotice('danger', iconPayload.error);
                return;
            }

            const basePath = lpGetBasePath();
            const iconUrl = basePath ? `${basePath}/res/scr/pane-icon-save.php` : '/res/scr/pane-icon-save.php';
            const settingsUrl = basePath ? `${basePath}/res/scr/pane-settings-save.php` : '/res/scr/pane-settings-save.php';

            showSavingOverlay();

            // Step 1 (conditional): null payload skips the icon POST so
            // the saved icon survives a toggle-only / settings-only save.
            const iconStep = iconPayload
                ? (() => {
                    const iconForm = new FormData();
                    iconForm.append('paneId', paneId);
                    iconForm.append('iconType', iconPayload.type);
                    if (iconPayload.type === 'svg') {
                        iconForm.append('iconSvg', iconPayload.svg);
                    } else if (iconPayload.type === 'file' && iconPayload.file) {
                        iconForm.append('iconFile', iconPayload.file);
                    }
                    appendCsrf(iconForm);
                    return fetch(iconUrl, { method: 'POST', body: iconForm, credentials: 'same-origin' })
                        .then((r) => r.json().then((j) => ({ ok: r.ok, body: j })))
                        .then(({ ok, body }) => {
                            if (!ok) {
                                throw new Error((body && body.error) || 'Failed to save icon.');
                            }
                            if (body.icon) {
                                paneData.icon = body.icon;
                            }
                        });
                })()
                : Promise.resolve();

            iconStep
                .then(() => {
                    const settingsForm = new FormData();
                    settingsForm.append('paneId', paneId);
                    settingsForm.append('module', moduleId);
                    const useSiteDefaults = $('#panePerPaneSettingsUseDefaultsInput').prop('checked');
                    settingsForm.append('useSiteDefaults', useSiteDefaults ? '1' : '0');
                    if (!useSiteDefaults) {
                        $('#panePerPaneSettingsModuleControls input[type="checkbox"]').each(function() {
                            if ($(this).prop('checked')) {
                                settingsForm.append($(this).attr('name'), '1');
                            }
                        });
                    }
                    appendCsrf(settingsForm);
                    return fetch(settingsUrl, { method: 'POST', body: settingsForm, credentials: 'same-origin' })
                        .then((r) => r.json().then((j) => ({ ok: r.ok, body: j })));
                })
                .then(({ ok, body }) => {
                    hideSavingOverlay();
                    if (!ok) {
                        throw new Error((body && body.error) || 'Failed to save pane settings.');
                    }
                    if (body.persisted && body.settings) {
                        paneData.useSiteDefaults = !!body.settings.useSiteDefaults;
                        const moduleEntry = (data.modules && data.modules[moduleId]) || {};
                        const declared = Array.isArray(moduleEntry.per_pane_settings) ? moduleEntry.per_pane_settings : [];
                        const siteDefaults = (moduleEntry.siteDefaults && typeof moduleEntry.siteDefaults === 'object')
                            ? moduleEntry.siteDefaults
                            : {};
                        const newResolved = {};
                        declared.forEach((entry) => {
                            if (paneData.useSiteDefaults) {
                                // Inheriting — read the siteDefaults snapshot so
                                // the next open shows actual defaults, not stale
                                // per-pane values.
                                newResolved[entry.key] = !!siteDefaults[entry.key];
                            } else if (typeof body.settings[entry.key] !== 'undefined') {
                                newResolved[entry.key] = !!body.settings[entry.key];
                            } else {
                                // Defensive — shouldn't happen with current endpoint.
                                newResolved[entry.key] = !!(paneData.resolvedValues || {})[entry.key];
                            }
                        });
                        paneData.resolvedValues = newResolved;
                    }
                    // Listeners: mediaGallery/admin.js (per-item button
                    // visibility). See feedback_shared_event_channel_for_module_updates.
                    $(document).trigger('lp:per-pane-settings-saved', [{
                        paneId: paneId,
                        module: moduleId,
                        useSiteDefaults: paneData.useSiteDefaults,
                        resolvedValues: paneData.resolvedValues || {}
                    }]);
                    refreshPaneAfterSave(paneId);
                    closeAdminModal($perPaneSettingsModal);
                    addAdminNotice('ok', 'Pane settings saved.');
                })
                .catch((err) => {
                    hideSavingOverlay();
                    addAdminNotice('danger', (err && err.message) || 'Save failed.');
                });
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
            const basePath = lpGetBasePath();
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
                    handleEndpointError(xhr, 'Migration preview failed.');
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
            const basePath = lpGetBasePath();
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
                    handleEndpointError(xhr, 'Migration failed.');
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

    // Toolbar HTML render moved to admin/plugins/markdownEditor/. The plugin's
    // editor.js sets window.lpMarkdownEditor.toolbarHtml() and (for legacy
    // call sites) window.buildMarkdownToolbarHtml.

    // Sync each .markdownToolbar's gating-level active state from its
    // .markdownEditor's data-mdGatingLevel. The plugin's helper hardcodes
    // NONE as active in the markup; this re-applies the correct state on
    // dynamically rebuilt toolbars (e.g. eventList card re-render after save).
    function ensureMarkdownToolbarEnhancements($context) {
        const $scope = $context && $context.length ? $context : $(document);
        $scope.find('.markdownToolbar').each(function() {
            const $editor = $(this).closest('.markdownEditor');
            if ($editor.length) {
                setMarkdownGatingLevel($editor, getMarkdownGatingLevel($editor));
            }
        });
    }

    function getMarkdownGatingLevel($editor) {
        const level = String($editor.data('mdGatingLevel') || '').toLowerCase();
        if (level === 'sfw' || level === 'nsfw') {
            return level;
        }
        return 'none';
    }

    function setMarkdownGatingLevel($editor, level) {
        const normalized = level === 'nsfw' ? 'nsfw' : (level === 'sfw' ? 'sfw' : 'none');
        $editor.data('mdGatingLevel', normalized);
        const $toolbar = $editor.find('.markdownToolbar').first();
        const $none = $toolbar.find('[data-md-gating-level="none"]');
        const $sfw = $toolbar.find('[data-md-gating-level="sfw"]');
        const $nsfw = $toolbar.find('[data-md-gating-level="nsfw"]');
        $none.toggleClass('isActive', normalized === 'none');
        $sfw.toggleClass('isActive', normalized === 'sfw' || normalized === 'nsfw');
        $nsfw.toggleClass('isActive', normalized === 'nsfw');
    }

    function renderMarkdownPreview($editor, $textarea) {
        const markdown = $textarea.val() || '';
        const $preview = $editor.find('.markdownPreview');
        const clearance = getMarkdownGatingLevel($editor);
        requestMarkdownPreview(markdown, clearance, function(html) {
            $preview.html(html).removeAttr('hidden');
        }, function(message) {
            $preview.html(`<p>${lpEscapeHtml(message || 'Preview failed.')}</p>`).removeAttr('hidden');
        });
    }

    function bindMarkdownToolbars() {
        ensureMarkdownToolbarEnhancements($(document));

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
            const gatingLevel = String($button.data('md-gating-level') || '');
            if (gatingLevel) {
                setMarkdownGatingLevel($editor, gatingLevel);
                if ($editor.hasClass('isPreviewing')) {
                    renderMarkdownPreview($editor, $textarea);
                }
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
            case 'sfw_tag':
                wrapSelection(textarea, '[sfw]', '[/sfw]', 'SFW content');
                break;
            case 'nsfw_tag':
                wrapSelection(textarea, '[nsfw]', '[/nsfw]', 'NSFW content');
                break;
            default:
                break;
        }
    }

    function toggleMarkdownPreview($editor, $button, $textarea) {
        const isActive = $editor.hasClass('isPreviewing');
        const $preview = $editor.find('.markdownPreview');
        const $toolbar = $editor.find('.markdownToolbar');
        const $toolbarButtons = $toolbar.find('button').not('[data-md-action="preview"]').not('[data-md-gating-level]');
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
        renderMarkdownPreview($editor, $textarea);
    }

    function requestMarkdownPreview(markdown, clearance, onSuccess, onError) {
        const basePath = lpGetBasePath();
        const previewUrl = basePath ? `${basePath}/res/scr/markdown-preview.php` : '/res/scr/markdown-preview.php';
        const formData = new FormData();
        formData.append('markdown', markdown);
        formData.append('clearance', clearance || 'none');
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

    function applySiteEditPermissions() {
        const isReadOnlyUser = window.appConfig && window.appConfig.isReadOnlyUser === true;
        const canEditSite = !isReadOnlyUser && !(window.appConfig && window.appConfig.canEditSite === false);
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

    function showSavingOverlay() {
        $('#savingOverlay').addClass('isActive').attr('aria-hidden', 'false');
    }

    function hideSavingOverlay() {
        $('#savingOverlay').removeClass('isActive').attr('aria-hidden', 'true');
    }

    window.showSavingOverlay = showSavingOverlay;
    window.hideSavingOverlay = hideSavingOverlay;

    function bindAdminNotices() {
        return noticeManager.bind();
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
        const isReadOnly = row.getAttribute('data-readonly') === 'true';
        if (window.appConfig) {
            window.appConfig.isReadOnlyUser = isReadOnly;
            window.appConfig.canEditSite = !isReadOnly && (isFullAdmin || perms.includes('edit_site'));
        }
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


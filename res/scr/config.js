// Config page tutorial and interactions (UI only, no persistence).

$(document).ready(function() {
    const steps = buildTutorialSteps();
    let currentStep = 0;
    let pendingLogoFile = null;
    let linkCounter = $('.linksConfigCard').length;
    let pendingBackgroundFiles = new WeakMap();
    let bgCounter = $('.bgConfigRow').not('.bgConfigHeader').length;
    let currentBgTarget = null;

    // Bind Help button
    $('.helpTutorial').on('click', function() {
        startTutorial();
    });

    bindLinksControls();
    bindBackgroundControls();
    bindSaveHandler();

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

    function startTutorial() {
        currentStep = 0;
        $('#tutorialOverlay').removeClass('hidden');
        goToStep(currentStep);
    }

    function endTutorial() {
        $('#tutorialOverlay').addClass('hidden');
        resetMask();
    }

    function goToStep(index) {
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

    function createLinkCard() {
        linkCounter += 1;
        const uniqueId = `link_${linkCounter}`;
        return `
            <div class="linksConfigCard">
                <div class="linksConfigRow">
                    <label class="linksConfigField" title="The internal HTML ID of the link.  Make it unique.">ID
                        <input class="linksConfigInput" type="text" name="linkId[]" value="${uniqueId}" placeholder="Link ID" title="The internal HTML ID of the link.  Make it unique.">
                    </label>
                    <label class="linksConfigField" title="The full URL (https: and all) to link to.">URL
                        <input class="linksConfigInput" type="text" name="linkUrl[]" value="" placeholder="Link URL" title="The full URL (https: and all) to link to.">
                    </label>
                </div>
                <div class="linksConfigRow">
                    <label class="linksConfigField" title="The label that is displayed for each link.">Text
                        <input class="linksConfigInput" type="text" name="linkText[]" value="" placeholder="Display text" title="The label that is displayed for each link.">
                    </label>
                    <label class="linksConfigField" title="The text that appears when the user hovers over a link.">Title
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
                    <button class="moveUpLink" type="button" title="Move this entry up in the list.">↑</button>
                    <button class="moveDownLink" type="button" title="Move this entry down in the list.">↓</button>
                    <button class="deleteLink" type="button" title="Removes this entry from the list.">Delete</button>
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
                    <button class="moveUpLink" type="button" title="Move this entry up in the list.">↑</button>
                    <button class="moveDownLink" type="button" title="Move this entry down in the list.">↓</button>
                    <button class="deleteLink" type="button" title="Removes this entry from the list.">Delete</button>
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
            currentBgTarget = $(this).closest('.bgConfigRow');
            $bgFileInput.trigger('click');
        });

        // File input change
        $bgFileInput.on('change', function() {
            const file = this.files && this.files[0] ? this.files[0] : null;
            if (file && currentBgTarget) {
                previewBackgroundFile(currentBgTarget, file);
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
                const $row = $(this).closest('.bgConfigRow');
                previewBackgroundFile($row, file);
            }
        });

        // Delete
        $bgList.on('click', '.deleteBackground', function() {
            $(this).closest('.bgConfigRow').remove();
        });

        // Add new background
        $('.addBackground').on('click', function() {
            const $newRow = $(createBackgroundRow());
            // Insert before the actions row
            $('.bgConfigActions').before($newRow);
            scrollBgListToBottom();
        });
    }

    function previewBackgroundFile($row, file) {
        if (!file || !(file.type || '').startsWith('image/')) {
            return;
        }
        pendingBackgroundFiles.set($row[0], file);

        const reader = new FileReader();
        reader.onload = function(ev) {
            const $wrap = $row.find('.bgThumbWrap');
            $wrap.removeClass('empty');
            $row.find('.bgThumb').attr('src', ev.target.result);
        };
        reader.readAsDataURL(file);
    }

    function createBackgroundRow() {
        bgCounter += 1;
        return `
            <div class="bgConfigRow" data-current-url="">
                <div class="bgThumbWrap empty">
                    <img class="bgThumb" src="" alt="Background preview">
                    <button class="bgChange" type="button">Change</button>
                </div>
                <input class="bgAuthorInput" type="text" name="bgAuthor[]" value="" placeholder="Author">
                <button class="deleteBackground" type="button">Delete</button>
            </div>
        `;
    }

    function scrollBgListToBottom() {
        const $bgList = $('#bgConfig');
        $bgList.scrollTop($bgList[0].scrollHeight);
    }

    // Save handler: gather data and POST to save endpoint.
    function bindSaveHandler() {
        $('.saveChanges').on('click', function() {
            const formData = new FormData();

            // Header text
            formData.append('siteTitle', $('.headlineInput[name="siteTitle"]').val() || '');
            formData.append('siteSubtitle', $('.headlineInput[name="siteSubtitle"]').val() || '');

            // Logo file (optional)
            if (pendingLogoFile) {
                formData.append('logoFile', pendingLogoFile);
            }

            // Links
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
            formData.append('links', JSON.stringify(links));

            // Backgrounds
            const backgrounds = [];
            $('.bgConfigRow').not('.bgConfigHeader').each(function(index) {
                const rowEl = this;
                const $row = $(rowEl);
                const author = $row.find('.bgAuthorInput').val() || '';
                const file = pendingBackgroundFiles.get(rowEl);
                const currentUrl = $row.data('current-url') || $row.find('.bgThumb').attr('src') || '';

                if (file) {
                    const fileKey = `bgFile_${index}`;
                    formData.append(fileKey, file);
                    backgrounds.push({ url: currentUrl, author, fileKey });
                } else if (currentUrl) {
                    backgrounds.push({ url: currentUrl, author });
                }
                // If no file and no url, skip this entry.
            });
            formData.append('backgrounds', JSON.stringify(backgrounds));

            // Markdown
            formData.append('aboutMarkdown', $('textarea[name="aboutMarkdown"]').val() || '');
            formData.append('rulesMarkdown', $('textarea[name="rulesMarkdown"]').val() || '');
            formData.append('faqMarkdown', $('textarea[name="faqMarkdown"]').val() || '');

            $.ajax({
                url: 'res/scr/save-config.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(resp) {
                    console.log('Save successful', resp);
                    alert('Changes saved.');
                },
                error: function(xhr) {
                    console.error('Save failed', xhr.responseText);
                    alert('Save failed. Please try again.');
                }
            });
        });
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
                text: 'Use the navbar to switch between panes.',
                onBefore: function() {}
            },
            {
                selector: '#about',
                text: 'Each pane uses markdown. Edit the text here. For help with markdown, see commonmark.org/help/ or use stackedit.io to edit and paste back.',
                onBefore: function() {
                    $('.navLink[data-pane=\"about\"]').trigger('click');
                }
            },
            {
                selector: '.headerActions',
                text: 'Click Help to view this tutorial again. Click Save All Changes to commit your edits.',
                onBefore: function() {}
            }
        ];
    }
});

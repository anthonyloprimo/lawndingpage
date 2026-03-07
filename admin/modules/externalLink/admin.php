<?php
// Module: External Nav Link (admin)
// Edits URL/open behavior/icon mode for a navbar-only external link pane.

if (!isset($pane) || !is_array($pane)) {
    return;
}

static $externalLinkAdminStylesInjected = false;
if (!$externalLinkAdminStylesInjected) {
    $externalLinkAdminStylesInjected = true;
    $styleUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/module-style.php?module=externalLink')
        : '/res/scr/module-style.php?module=externalLink';
    echo '<link rel="stylesheet" href="' . htmlspecialchars($styleUrl, ENT_QUOTES, 'UTF-8') . '">';
}

static $externalLinkAdminScriptInjected = false;
if (!$externalLinkAdminScriptInjected) {
    $externalLinkAdminScriptInjected = true;
    ?>
    <script>
    (function() {
        function normalizeExternalSettings(pane) {
            const payload = pane || {};
            const url = typeof payload.url === 'string' ? payload.url : '';
            const openMode = payload.openMode === 'same' ? 'same' : 'new';
            const iconMode = payload.iconMode === 'favicon' ? 'favicon' : 'custom';
            return { url, openMode, iconMode };
        }

        function syncExternalPanePayload(paneEl) {
            if (!paneEl) {
                return;
            }
            const urlInput = paneEl.querySelector('.externalLinkUrlInput');
            const openModeInput = paneEl.querySelector('.externalLinkOpenModeInput');
            const iconModeInput = paneEl.querySelector('.externalLinkIconModeInput');
            const payloadField = paneEl.querySelector('.externalLinkPayload');
            if (!urlInput || !openModeInput || !iconModeInput || !payloadField) {
                return;
            }
            const settings = normalizeExternalSettings({
                url: urlInput.value || '',
                openMode: openModeInput.value || 'new',
                iconMode: iconModeInput.value || 'custom'
            });
            payloadField.value = JSON.stringify(settings);
        }

        function syncAllExternalPanePayloads() {
            document.querySelectorAll('.externalLinkPane').forEach(syncExternalPanePayload);
        }

        document.addEventListener('input', function(event) {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            if (!target.classList.contains('externalLinkUrlInput')) {
                return;
            }
            syncExternalPanePayload(target.closest('.externalLinkPane'));
        });

        document.addEventListener('change', function(event) {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            if (!target.classList.contains('externalLinkOpenModeInput') && !target.classList.contains('externalLinkIconModeInput')) {
                return;
            }
            syncExternalPanePayload(target.closest('.externalLinkPane'));
        });

        document.addEventListener('DOMContentLoaded', syncAllExternalPanePayloads);
        window.addEventListener('load', syncAllExternalPanePayloads);
    })();
    </script>
    <?php
}

$paneId = isset($pane['id']) ? (string) $pane['id'] : '';
$paneName = isset($pane['name']) ? (string) $pane['name'] : '';
$paneData = isset($pane['data']) && is_array($pane['data']) ? $pane['data'] : [];
$jsonFile = isset($paneData['json']) ? (string) $paneData['json'] : '';
if ($paneId === '' || $jsonFile === '') {
    return;
}

$jsonPath = function_exists('lawnding_data_path')
    ? lawnding_data_path($jsonFile)
    : __DIR__ . '/../../public/res/data/' . $jsonFile;
$settings = ['url' => '', 'openMode' => 'new', 'iconMode' => 'custom'];
if (is_readable($jsonPath)) {
    $decoded = json_decode((string) file_get_contents($jsonPath), true);
    if (is_array($decoded)) {
        $settings = array_merge($settings, $decoded);
    }
}
$url = isset($settings['url']) && is_string($settings['url']) ? $settings['url'] : '';
$openMode = (isset($settings['openMode']) && $settings['openMode'] === 'same') ? 'same' : 'new';
$iconMode = (isset($settings['iconMode']) && $settings['iconMode'] === 'favicon') ? 'favicon' : 'custom';
$iconHtml = '';
if (isset($renderPaneIcon) && is_callable($renderPaneIcon)) {
    $iconHtml = (string) $renderPaneIcon($pane);
}
if ($iconHtml === '') {
    $iconHtml = '<span class="paneIconFallback">Icon</span>';
}
$dataHint = 'saves to ' . $jsonFile;
?>
<div class="pane glassConvex externalLinkPane" id="<?php echo htmlspecialchars($paneId); ?>">
    <div class="paneHeader">
        <button class="paneIconButton" type="button" data-pane-id="<?php echo htmlspecialchars($paneId); ?>" aria-label="Edit pane icon">
            <span class="paneIconPreview"><?php echo $iconHtml; ?></span>
        </button>
        <div class="paneHeaderTitle">
            <span class="paneTitle"><?php echo htmlspecialchars($paneName); ?></span>
            <span class="paneDataHint"><?php echo htmlspecialchars('(' . $dataHint . ')'); ?></span>
        </div>
    </div>
    <div class="externalLinkPaneBody">
        <div class="externalLinkRow">
            <label class="externalLinkField">
                <span class="externalLinkLabel">External URL</span>
                <input class="externalLinkInput externalLinkUrlInput" type="url" value="<?php echo htmlspecialchars($url); ?>" placeholder="https://example.com">
            </label>
        </div>
        <div class="externalLinkRow">
            <label class="externalLinkField">
                <span class="externalLinkLabel">Open Link</span>
                <select class="externalLinkSelect externalLinkOpenModeInput">
                    <option value="new" <?php echo $openMode === 'new' ? 'selected' : ''; ?>>New window/tab</option>
                    <option value="same" <?php echo $openMode === 'same' ? 'selected' : ''; ?>>Current window</option>
                </select>
            </label>
            <label class="externalLinkField">
                <span class="externalLinkLabel">Icon Source</span>
                <select class="externalLinkSelect externalLinkIconModeInput">
                    <option value="custom" <?php echo $iconMode === 'custom' ? 'selected' : ''; ?>>Pane icon (SVG/Image)</option>
                    <option value="favicon" <?php echo $iconMode === 'favicon' ? 'selected' : ''; ?>>Website favicon from URL</option>
                </select>
            </label>
        </div>
        <div class="externalLinkHint">Use Pane Management icon controls for custom SVG/image icons when Icon Source is set to Pane icon.</div>
        <textarea class="externalLinkPayload" name="pane[<?php echo htmlspecialchars($paneId); ?>][external]" hidden><?php
            echo htmlspecialchars(json_encode([
                'url' => $url,
                'openMode' => $openMode,
                'iconMode' => $iconMode,
            ], JSON_UNESCAPED_SLASHES));
        ?></textarea>
    </div>
</div>

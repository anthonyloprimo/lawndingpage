<?php
// Module: Basic Text Pane (admin)
// Renders the pane editor and exposes content to Save All via pane[<id>][content].

if (!isset($pane) || !is_array($pane)) {
    return;
}

// Inject admin styles once per request.
static $basicTextAdminStylesInjected = false;
if (!$basicTextAdminStylesInjected) {
    $basicTextAdminStylesInjected = true;
    $styleUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/module-style.php?module=basicText')
        : '/res/scr/module-style.php?module=basicText';
    echo '<link rel="stylesheet" href="'
        . htmlspecialchars($styleUrl, ENT_QUOTES, 'UTF-8')
        . '">';
}

// Pane metadata used for IDs, labels, and file resolution.
$paneId = isset($pane['id']) ? (string) $pane['id'] : '';
$paneName = isset($pane['name']) ? (string) $pane['name'] : '';
$paneData = isset($pane['data']) && is_array($pane['data']) ? $pane['data'] : [];
$markdownFile = isset($paneData['md']) ? (string) $paneData['md'] : '';

if ($paneId === '' || $markdownFile === '') {
    return;
}

// Resolve markdown file path through bootstrap helpers when available.
$markdownPath = function_exists('lawnding_data_path')
    ? lawnding_data_path($markdownFile)
    : __DIR__ . '/../../public/res/data/' . $markdownFile;

$markdown = is_readable($markdownPath) ? file_get_contents($markdownPath) : '';
// Render icon HTML using the shared helper injected by admin/config.php.
$iconHtml = '';
if (isset($renderPaneIcon) && is_callable($renderPaneIcon)) {
    $iconHtml = (string) $renderPaneIcon($pane);
}
if ($iconHtml === '') {
    $iconHtml = '<span class="paneIconFallback">Icon</span>';
}
?>
<div class="pane glassConvex" id="<?php echo htmlspecialchars($paneId); ?>">
    <div class="paneHeader">
        <span class="paneIconDisplay" aria-hidden="true">
            <span class="paneIconPreview"><?php echo $iconHtml; ?></span>
        </span>
        <div class="paneHeaderTitle">
            <span class="paneTitle"><?php echo htmlspecialchars($paneName); ?></span>
        </div>
        <button class="paneSettingsButton iconButton" type="button" data-pane-id="<?php echo htmlspecialchars($paneId); ?>" aria-label="Pane settings" title="Pane settings"><?php echo lawnding_icon_svg('settings'); ?></button>
    </div>
    <div class="markdownEditor">
        <?php echo markdown_editor_toolbar_html(); ?>
        <textarea class="paneEditor markdownTextarea" name="pane[<?php echo htmlspecialchars($paneId); ?>][content]" aria-label="<?php echo htmlspecialchars($paneName); ?> markdown"><?php echo htmlspecialchars($markdown); ?></textarea>
        <div class="markdownPreview" aria-live="polite" hidden></div>
    </div>
</div>

<?php
// Module template (admin)

if (!isset($pane) || !is_array($pane)) {
    return;
}

$paneId = isset($pane['id']) ? (string) $pane['id'] : '';
$paneName = isset($pane['name']) ? (string) $pane['name'] : '';
$paneData = isset($pane['data']) && is_array($pane['data']) ? $pane['data'] : [];
$markdownFile = isset($paneData['md']) ? (string) $paneData['md'] : '';

if ($paneId === '' || $markdownFile === '') {
    return;
}

$markdownPath = function_exists('lawnding_data_path')
    ? lawnding_data_path($markdownFile)
    : __DIR__ . '/../../public/res/data/' . $markdownFile;

$markdown = is_readable($markdownPath) ? file_get_contents($markdownPath) : '';
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
    <textarea class="paneEditor" name="pane[<?php echo htmlspecialchars($paneId); ?>][content]" aria-label="<?php echo htmlspecialchars($paneName); ?> content"><?php echo htmlspecialchars($markdown); ?></textarea>
</div>

<?php
// Module: Basic Text Pane (admin)
// Renders the pane editor and exposes content to Save All via pane[<id>][content].

if (!isset($pane) || !is_array($pane)) {
    return;
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
// Build the "saves to ..." hint from the pane data map.
$dataFiles = [];
if (!empty($paneData) && is_array($paneData)) {
    foreach ($paneData as $file) {
        if (is_string($file) && $file !== '') {
            $dataFiles[] = $file;
        }
    }
}
$dataHint = $dataFiles ? 'saves to ' . implode(', ', $dataFiles) : '';
?>
<div class="pane glassConvex" id="<?php echo htmlspecialchars($paneId); ?>">
    <div class="paneHeader">
        <button class="paneIconButton" type="button" data-pane-id="<?php echo htmlspecialchars($paneId); ?>" aria-label="Edit pane icon">
            <span class="paneIconPreview"><?php echo $iconHtml; ?></span>
        </button>
        <div class="paneHeaderTitle">
            <span class="paneTitle"><?php echo htmlspecialchars($paneName); ?></span>
            <?php if ($dataHint !== ''): ?>
                <span class="paneDataHint">(<?php echo htmlspecialchars($dataHint); ?>)</span>
            <?php endif; ?>
        </div>
    </div>
    <textarea class="paneEditor" name="pane[<?php echo htmlspecialchars($paneId); ?>][content]" aria-label="<?php echo htmlspecialchars($paneName); ?> markdown"><?php echo htmlspecialchars($markdown); ?></textarea>
</div>

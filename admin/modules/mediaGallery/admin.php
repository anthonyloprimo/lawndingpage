<?php
// Module: Media Gallery (admin)

if (!isset($pane) || !is_array($pane)) {
    return;
}

// Inject admin styles/scripts once per request.
static $mediaGalleryAdminAssetsInjected = false;
if (!$mediaGalleryAdminAssetsInjected) {
    $mediaGalleryAdminAssetsInjected = true;
    $styleUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/module-style.php?module=mediaGallery')
        : '/res/scr/module-style.php?module=mediaGallery';
    echo '<link rel="stylesheet" href="'
        . htmlspecialchars(lawnding_versioned_url($styleUrl), ENT_QUOTES, 'UTF-8')
        . '">';

    $scriptUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/module-script.php?module=mediaGallery&file=admin.js')
        : '/res/scr/module-script.php?module=mediaGallery&file=admin.js';
    echo '<script src="'
        . htmlspecialchars(lawnding_versioned_url($scriptUrl), ENT_QUOTES, 'UTF-8')
        . '" defer></script>';
}

// Pane metadata used for IDs, labels, and data file resolution.
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

$raw = is_readable($jsonPath) ? file_get_contents($jsonPath) : '';
$decoded = $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($decoded)) {
    $decoded = [];
}
$items = $decoded['items'] ?? [];
if (!is_array($items)) {
    $items = [];
}

// Render icon HTML using the shared helper injected by admin/config.php.
$iconHtml = '';
if (isset($renderPaneIcon) && is_callable($renderPaneIcon)) {
    $iconHtml = (string) $renderPaneIcon($pane);
}
if ($iconHtml === '') {
    $iconHtml = '<span class="paneIconFallback">Icon</span>';
}

$dataFiles = [];
if (!empty($paneData) && is_array($paneData)) {
    foreach ($paneData as $file) {
        if (is_string($file) && $file !== '') {
            $dataFiles[] = $file;
        }
    }
}
$dataHint = $dataFiles ? 'saves to ' . implode(', ', $dataFiles) : '';

$itemsJson = json_encode(['items' => $items], JSON_UNESCAPED_SLASHES);
if ($itemsJson === false) {
    $itemsJson = '{"items":[]}';
}
?>
<div class="pane glassConvex mediaGalleryPane" id="<?php echo htmlspecialchars($paneId); ?>" data-pane-type="mediaGallery" data-pane-id="<?php echo htmlspecialchars($paneId); ?>">
    <div class="paneHeader">
        <button class="paneIconButton" type="button" data-pane-id="<?php echo htmlspecialchars($paneId); ?>" aria-label="Edit pane icon">
            <span class="paneIconPreview"><?php echo $iconHtml; ?></span>
        </button>
        <div class="paneHeaderTitle">
            <span class="paneTitle"><?php echo htmlspecialchars($paneName); ?></span>
            <?php if ($dataHint !== ''): ?>
                <span class="paneDataHint"><?php echo htmlspecialchars('(' . $dataHint . ')'); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="mediaGalleryScroll">
        <div class="mediaGalleryGrid" data-pane-id="<?php echo htmlspecialchars($paneId); ?>">
            <?php if (empty($items)): ?>
                <div class="mediaGalleryEmpty">No media yet. Click Add new media to upload.</div>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
                <?php
                    if (!is_array($item)) {
                        $item = [];
                    }
                    $itemId = isset($item['id']) ? (string) $item['id'] : '';
                    $itemType = isset($item['type']) ? (string) $item['type'] : 'image';
                    $itemFile = isset($item['file']) ? (string) $item['file'] : '';
                    $itemThumb = isset($item['thumb']) ? (string) $item['thumb'] : '';
                    $itemTitle = isset($item['title']) ? (string) $item['title'] : '';
                    $itemOrder = isset($item['order']) ? (int) $item['order'] : 0;
                    $thumbPath = $itemThumb !== '' ? $itemThumb : $itemFile;
                    $thumbUrl = function_exists('lawnding_asset_url') ? lawnding_asset_url($thumbPath) : $thumbPath;
                ?>
                <div class="mediaGalleryItem<?php echo $itemType === 'video' ? ' isVideo' : ''; ?>" data-item-id="<?php echo htmlspecialchars($itemId); ?>" data-item-type="<?php echo htmlspecialchars($itemType); ?>" data-item-order="<?php echo (int) $itemOrder; ?>" data-item-file="<?php echo htmlspecialchars($itemFile); ?>" data-item-thumb="<?php echo htmlspecialchars($itemThumb); ?>" data-item-title="<?php echo htmlspecialchars($itemTitle); ?>">
                    <button class="mediaGalleryThumbButton" type="button" aria-label="Edit media"<?php echo $thumbUrl !== '' ? ' style="background-image: url(' . htmlspecialchars($thumbUrl) . ');"' : ''; ?>></button>
                    <div class="mediaGalleryItemActions">
                        <button class="mediaGalleryMoveUp iconButton" type="button" title="Move up" aria-label="Move up"><?php echo lawnding_icon_svg('move_up'); ?></button>
                        <button class="mediaGalleryMoveDown iconButton" type="button" title="Move down" aria-label="Move down"><?php echo lawnding_icon_svg('move_down'); ?></button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="mediaGalleryControls">
        <div class="mediaGalleryControlsRow">
            <button class="mediaGalleryAddButton" type="button">
                <span class="mediaGalleryAddIcon" aria-hidden="true"></span>
                Add new media
            </button>
            <input class="mediaGalleryUploadInput" type="file" accept="image/*,video/*" hidden>
        </div>
        <div class="mediaGalleryFootnote">Uploads are saved immediately. Max upload size: 2MB.</div>
    </div>

    <textarea class="mediaGalleryChanges" name="pane[<?php echo htmlspecialchars($paneId); ?>][mediaChanges]" aria-label="<?php echo htmlspecialchars($paneName); ?> media changes" hidden></textarea>
    <script type="application/json" class="mediaGalleryData"><?php echo htmlspecialchars($itemsJson, ENT_QUOTES, 'UTF-8'); ?></script>

    <div class="userModalOverlay mediaGalleryModal" id="mediaGalleryModal-<?php echo htmlspecialchars($paneId); ?>" aria-hidden="true">
        <div class="userModal glassConcave">
            <h4>Media Details</h4>
            <div class="mediaGalleryModalBody">
                <div class="mediaGalleryModalPreview">
                    <div class="mediaGalleryModalImage" role="img" aria-label="Media preview"></div>
                    <video class="mediaGalleryModalVideo" controls playsinline></video>
                </div>
                <div class="mediaGalleryModalActions">
                    <label class="mediaGalleryField">
                        <span class="mediaGalleryFieldLabel">Caption / Alt text</span>
                        <input type="text" class="mediaGalleryCaptionInput" placeholder="Optional caption">
                    </label>
                    <div class="mediaGalleryButtonStack">
                        <button class="mediaGalleryChangeButton" type="button">Change media</button>
                        <input class="mediaGalleryChangeInput" type="file" accept="image/*,video/*" hidden>
                        <button class="mediaGalleryThumbButtonAction" type="button">Set thumbnail</button>
                        <input class="mediaGalleryThumbInput" type="file" accept="image/*" hidden>
                        <button class="mediaGalleryThumbClear" type="button">Use default thumbnail</button>
                        <button class="mediaGalleryRemoveButton usersDanger" type="button">Remove from gallery</button>
                    </div>
                    <div class="mediaGalleryFootnote">Uploads are saved immediately. Max upload size: 2MB.</div>
                </div>
            </div>
            <button class="userModalClose" type="button" aria-label="Close">×</button>
        </div>
    </div>
</div>

<?php
// Module: Media Gallery (admin)

if (!isset($pane) || !is_array($pane)) {
    return;
}

// Inject admin styles/scripts once per request.
if (!defined('LAWNDING_MEDIA_GALLERY_ADMIN_ASSETS_INJECTED')) {
    define('LAWNDING_MEDIA_GALLERY_ADMIN_ASSETS_INJECTED', true);
    $styleUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/module-style.php?module=mediaGallery')
        : '/res/scr/module-style.php?module=mediaGallery';
    echo '<link rel="stylesheet" href="'
        . htmlspecialchars($styleUrl, ENT_QUOTES, 'UTF-8')
        . '">';

    $scriptUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/module-script.php?module=mediaGallery&file=admin.js')
        : '/res/scr/module-script.php?module=mediaGallery&file=admin.js';
    echo '<script src="'
        . htmlspecialchars($scriptUrl, ENT_QUOTES, 'UTF-8')
        . '" defer></script>';

    // smartcrop.js (vendored at v2.0.5) runs client-side before each
    // upload to suggest a focal point. admin.js feature-detects
    // window.smartcrop; if the script fails to load, uploads still
    // succeed and the server falls back to centered crop.
    $smartcropUrl = function_exists('lawnding_versioned_local_asset_url')
        ? lawnding_versioned_local_asset_url('res/scr/smartcrop.js')
        : '/res/scr/smartcrop.js';
    echo '<script src="'
        . htmlspecialchars($smartcropUrl, ENT_QUOTES, 'UTF-8')
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

require_once __DIR__ . '/helpers.php';
$itemsJson = json_encode(['items' => media_gallery_build_payload($items)], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($itemsJson === false) {
    $itemsJson = '{"items":[]}';
}

// Load per-pane settings sidecar and resolve effective values for this
// render. Resolved booleans gate the per-item modal buttons below; the
// raw $paneSettings array is also serialized into the pane-settings
// modal for editing.
$dataDirForSettings = function_exists('lawnding_data_path') ? lawnding_data_path('') : (dirname(__DIR__, 3) . '/public/res/data');
$dataDirForSettings = rtrim($dataDirForSettings, '/\\');
$paneSettingsPath = media_gallery_settings_path($dataDirForSettings, $paneId);
$paneSettings = lawnding_load_pane_settings($paneSettingsPath);
$canCustomThumbs = lawnding_resolve_pane_setting($paneSettings, 'mediaGallery', 'customThumbs');
$canChangeMedia  = lawnding_resolve_pane_setting($paneSettings, 'mediaGallery', 'changeMedia');
$useSiteDefaults = !isset($paneSettings['useSiteDefaults']) || $paneSettings['useSiteDefaults'] !== false;
?>
<div class="pane glassConvex mediaGalleryPane" id="<?php echo htmlspecialchars($paneId); ?>" data-pane-type="mediaGallery" data-pane-id="<?php echo htmlspecialchars($paneId); ?>">
    <div class="paneHeader">
        <button class="paneIconButton" type="button" data-pane-id="<?php echo htmlspecialchars($paneId); ?>" aria-label="Edit pane icon">
            <span class="paneIconPreview"><?php echo $iconHtml; ?></span>
        </button>
        <div class="paneHeaderTitle">
            <span class="paneTitle"><?php echo htmlspecialchars($paneName); ?></span>
            <span class="paneDataHint"><?php echo htmlspecialchars('Max upload size: ' . lawnding_app_upload_max_label()); ?></span>
        </div>
        <button class="mediaGallerySettingsButton iconButton" type="button" aria-label="Pane settings" title="Pane settings"><?php echo lawnding_icon_svg('settings'); ?></button>
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
                    <button class="mediaGalleryThumbButton" type="button" aria-label="Edit media"><?php if ($thumbUrl !== ''): ?><img class="mediaGalleryThumb" src="<?php echo htmlspecialchars($thumbUrl); ?>" alt="<?php echo htmlspecialchars($itemTitle); ?>"><?php endif; ?></button>
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
            <div class="mediaGalleryControlsActions">
                <button class="mediaGalleryAddButton" type="button">
                    <svg class="mediaGalleryAddIcon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 15V18H15V20H18V23H20V20H23V18H20V15H18M13.3 21H5C3.9 21 3 20.1 3 19V5C3 3.9 3.9 3 5 3H19C20.1 3 21 3.9 21 5V13.3C20.4 13.1 19.7 13 19 13C17.9 13 16.8 13.3 15.9 13.9L14.5 12L11 16.5L8.5 13.5L5 18H13.1C13 18.3 13 18.7 13 19C13 19.7 13.1 20.4 13.3 21Z" /></svg>
                    Add new media
                </button>
                <input class="mediaGalleryUploadInput" type="file" accept="image/*" multiple hidden>
            </div>
        </div>
    </div>

    <textarea class="mediaGalleryChanges" name="pane[<?php echo htmlspecialchars($paneId); ?>][mediaChanges]" aria-label="<?php echo htmlspecialchars($paneName); ?> media changes" hidden></textarea>
    <script type="application/json" class="mediaGalleryData"><?php echo $itemsJson; ?></script>

    <div class="userModalOverlay mediaGalleryModal" id="mediaGalleryModal-<?php echo htmlspecialchars($paneId); ?>" aria-hidden="true">
        <div class="userModal glassConcave">
            <h4>Media Details</h4>
            <div class="mediaGalleryModalBody">
                <div class="mediaGalleryModalPreview">
                    <div class="mediaGalleryModalImage" role="img" aria-label="Media preview, click to set focal point" tabindex="0">
                        <div class="mediaGalleryFocalMarker" aria-hidden="true" hidden></div>
                    </div>
                    <video class="mediaGalleryModalVideo" controls playsinline></video>
                    <div class="mediaGalleryConfirmDelete" role="alertdialog" aria-hidden="true" aria-labelledby="mediaGalleryConfirmDeletePrompt-<?php echo htmlspecialchars($paneId); ?>">
                        <p id="mediaGalleryConfirmDeletePrompt-<?php echo htmlspecialchars($paneId); ?>" class="mediaGalleryConfirmDeletePrompt">Are you sure?</p>
                        <div class="mediaGalleryActionRow mediaGalleryConfirmDeleteActions">
                            <button class="mediaGalleryConfirmDeleteYes usersButton usersDanger" type="button">Yes, delete</button>
                            <button class="mediaGalleryConfirmDeleteNo usersButton" type="button">No</button>
                        </div>
                    </div>
                </div>
                <div class="mediaGalleryModalActions">
                    <div class="mediaGalleryInfo">
                        <h5 class="mediaGalleryInfoHeader">Info</h5>
                        <dl class="mediaGalleryInfoList">
                            <dt>Original size</dt><dd class="mediaGalleryInfoOriginalSize">—</dd>
                            <dt>Saved size</dt><dd class="mediaGalleryInfoSavedSize">—</dd>
                            <dt>Thumbnail coords</dt><dd class="mediaGalleryInfoFocalCoords">0%, 0%</dd>
                            <dt>Uploaded at</dt><dd class="mediaGalleryInfoUploadedAt">—</dd>
                            <dt>Uploaded by</dt><dd class="mediaGalleryInfoUploadedBy">—</dd>
                            <dt>Hovertext</dt><dd class="mediaGalleryInfoHovertext"><input type="text" class="mediaGalleryCaptionInput" placeholder="Optional"></dd>
                        </dl>
                    </div>
                    <?php if ($canChangeMedia || $canCustomThumbs): ?>
                    <div class="mediaGalleryButtonStack">
                        <?php if ($canChangeMedia): ?>
                        <button class="mediaGalleryChangeButton usersButton" type="button">Change media</button>
                        <input class="mediaGalleryChangeInput" type="file" accept="image/*" hidden>
                        <?php endif; ?>
                        <?php if ($canCustomThumbs): ?>
                        <button class="mediaGalleryThumbButtonAction usersButton" type="button">Set thumbnail</button>
                        <input class="mediaGalleryThumbInput" type="file" accept="image/*" hidden>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <p class="mediaGalleryModalHint">Tip!<br>Click the image to adjust the center of the thumbnail.</p>
                    <div class="mediaGalleryActionRow mediaGalleryModalFooterActions">
                        <button class="mediaGalleryModalCancel usersButton" type="button">Cancel</button>
                    </div>
                    <div class="mediaGalleryActionRow">
                        <button class="mediaGalleryFocalSave usersButton" type="button" disabled>Save</button>
                        <button class="mediaGalleryRemoveButton usersButton usersDanger" type="button">Delete</button>
                    </div>
                </div>
            </div>
            <button class="userModalClose" type="button" aria-label="Close">×</button>
        </div>
    </div>

    <div class="userModalOverlay mediaGallerySettingsModal" id="mediaGallerySettingsModal-<?php echo htmlspecialchars($paneId); ?>" aria-hidden="true">
        <div class="userModal glassConcave">
            <h4><?php echo htmlspecialchars($paneName); ?> Settings</h4>
            <form class="mediaGallerySettingsForm" data-pane-id="<?php echo htmlspecialchars($paneId); ?>">
                <label class="siteConfigToggle mediaGallerySettingsUseDefaults">
                    <input type="checkbox" class="mediaGallerySettingsUseDefaultsInput" <?php echo $useSiteDefaults ? 'checked' : ''; ?>>
                    <span>Use site defaults</span>
                </label>
                <fieldset class="siteConfigGroup mediaGallerySettingsOverrides" <?php echo $useSiteDefaults ? 'disabled' : ''; ?>>
                    <legend>Per-pane overrides</legend>
                    <label class="siteConfigToggle">
                        <input type="checkbox" name="customThumbs" <?php echo $canCustomThumbs ? 'checked' : ''; ?>>
                        <span>Allow per-item custom thumbnail uploads</span>
                    </label>
                    <label class="siteConfigToggle">
                        <input type="checkbox" name="changeMedia" <?php echo $canChangeMedia ? 'checked' : ''; ?>>
                        <span>Allow replacing media files on existing items</span>
                    </label>
                </fieldset>
                <div class="mediaGalleryActionRow">
                    <button class="mediaGallerySettingsSave usersButton" type="button">Save</button>
                    <button class="mediaGallerySettingsCancel usersButton" type="button">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

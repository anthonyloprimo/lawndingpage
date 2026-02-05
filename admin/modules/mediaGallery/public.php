<?php
// Module: Media Gallery (public)

if (!isset($pane) || !is_array($pane)) {
    return;
}

// Inject public styles/scripts once per request.
static $mediaGalleryPublicAssetsInjected = false;
if (!$mediaGalleryPublicAssetsInjected) {
    $mediaGalleryPublicAssetsInjected = true;
    $styleUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/module-style.php?module=mediaGallery')
        : '/res/scr/module-style.php?module=mediaGallery';
    echo '<link rel="stylesheet" href="'
        . htmlspecialchars(lawnding_versioned_url($styleUrl), ENT_QUOTES, 'UTF-8')
        . '">';

    $scriptUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/module-script.php?module=mediaGallery&file=public.js')
        : '/res/scr/module-script.php?module=mediaGallery&file=public.js';
    echo '<script src="'
        . htmlspecialchars(lawnding_versioned_url($scriptUrl), ENT_QUOTES, 'UTF-8')
        . '" defer></script>';
}

$paneId = isset($pane['id']) ? (string) $pane['id'] : '';
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

usort($items, function ($a, $b) {
    $orderA = is_array($a) && isset($a['order']) ? (int) $a['order'] : 0;
    $orderB = is_array($b) && isset($b['order']) ? (int) $b['order'] : 0;
    return $orderA <=> $orderB;
});

$itemsForJson = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    $file = isset($item['file']) ? (string) $item['file'] : '';
    $thumb = isset($item['thumb']) ? (string) $item['thumb'] : '';
    $item['file'] = function_exists('lawnding_asset_url') ? lawnding_asset_url($file) : $file;
    $item['thumb'] = function_exists('lawnding_asset_url') ? lawnding_asset_url($thumb) : $thumb;
    $itemsForJson[] = $item;
}

$itemsJson = json_encode(['items' => $itemsForJson], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($itemsJson === false) {
    $itemsJson = '{"items":[]}';
}
?>
<div class="pane glassConvex mediaGalleryPublic" id="<?php echo htmlspecialchars($paneId); ?>" data-pane-type="mediaGallery" data-pane-id="<?php echo htmlspecialchars($paneId); ?>">
    <div class="mediaGalleryPublicGrid">
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
                $itemFileUrl = function_exists('lawnding_asset_url') ? lawnding_asset_url($itemFile) : $itemFile;
                $thumbPath = $itemThumb !== '' ? $itemThumb : ($itemType === 'image' ? $itemFile : '');
                $thumbUrl = $thumbPath !== '' && function_exists('lawnding_asset_url') ? lawnding_asset_url($thumbPath) : $thumbPath;
            ?>
            <a class="mediaGalleryPublicItem<?php echo $itemType === 'video' ? ' isVideo' : ''; ?>" href="<?php echo htmlspecialchars($itemFileUrl); ?>" data-media-id="<?php echo htmlspecialchars($itemId); ?>" data-media-type="<?php echo htmlspecialchars($itemType); ?>" data-media-file="<?php echo htmlspecialchars($itemFileUrl); ?>" data-media-thumb="<?php echo htmlspecialchars($itemThumb); ?>" data-media-title="<?php echo htmlspecialchars($itemTitle); ?>">
                <?php if ($thumbUrl !== ''): ?>
                    <img class="mediaGalleryPublicThumb" src="<?php echo htmlspecialchars($thumbUrl); ?>" alt="">
                <?php endif; ?>
                <span class="mediaGalleryPublicLabel" aria-hidden="true"></span>
            </a>
        <?php endforeach; ?>
    </div>

    <script type="application/json" class="mediaGalleryData"><?php echo $itemsJson; ?></script>

    <div class="mediaGalleryLightbox" id="mediaGalleryLightbox-<?php echo htmlspecialchars($paneId); ?>" aria-hidden="true">
        <div class="mediaGalleryLightboxBackdrop" data-lightbox-close></div>
        <div class="mediaGalleryLightboxContent" role="dialog" aria-modal="true" aria-label="Media viewer">
            <button class="mediaGalleryLightboxClose" type="button" aria-label="Close">×</button>
            <button class="mediaGalleryLightboxNav mediaGalleryLightboxPrev" type="button" aria-label="Previous">‹</button>
            <button class="mediaGalleryLightboxNav mediaGalleryLightboxNext" type="button" aria-label="Next">›</button>
            <div class="mediaGalleryLightboxMedia">
                <img class="mediaGalleryLightboxImage" alt="">
                <video class="mediaGalleryLightboxVideo" controls playsinline></video>
            </div>
            <div class="mediaGalleryLightboxCaption"></div>
        </div>
    </div>
</div>

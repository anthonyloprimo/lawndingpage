<?php
// Module template (public)

if (!isset($pane) || !is_array($pane)) {
    return;
}

$paneId = isset($pane['id']) ? (string) $pane['id'] : '';
$paneData = isset($pane['data']) && is_array($pane['data']) ? $pane['data'] : [];
$markdownFile = isset($paneData['md']) ? (string) $paneData['md'] : '';

if ($paneId === '' || $markdownFile === '') {
    return;
}

$markdownPath = function_exists('lawnding_data_path')
    ? lawnding_data_path($markdownFile)
    : __DIR__ . '/../../public/res/data/' . $markdownFile;

$markdown = is_readable($markdownPath) ? file_get_contents($markdownPath) : '';

if (!class_exists('Parsedown')) {
    $parsedownPath = function_exists('lawnding_public_path')
        ? lawnding_public_path('res/scr/Parsedown.php')
        : __DIR__ . '/../../public/res/scr/Parsedown.php';
    require_once $parsedownPath;
}

$parser = new Parsedown();
$content = $parser->text($markdown);
?>
<div class="pane glassConvex" id="<?php echo htmlspecialchars($paneId); ?>">
    <?php echo $content; ?>
</div>

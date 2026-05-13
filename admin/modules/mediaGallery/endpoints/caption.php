<?php
// Persist a single item's caption (item.title) to the gallery's JSON
// store. POST { paneId, itemId, title, csrf_token }. Caller is the
// modal Save flow when the caption input has diverged from its
// modal-open snapshot.
//
// title can be any string (including empty -- empty clears the caption).
// All other validation (length cap, rich-text sanitization, etc.) is
// upstream caller's concern; this endpoint just stores what it's given.
require_once lawnding_admin_path('modules/mediaGallery/helpers.php');
lawnding_init_session();

media_gallery_require_method('POST');
media_gallery_require_edit_site();

$paneId = (string) ($_POST['paneId'] ?? '');
$itemId = (string) ($_POST['itemId'] ?? '');
$title  = (string) ($_POST['title']  ?? '');

$state = media_gallery_load_pane_state($paneId);
$index = media_gallery_require_item($state['items'], $itemId);

$state['items'][$index]['title'] = $title;
media_gallery_save_and_respond($state['json_path'], $state['data'], $state['items']);

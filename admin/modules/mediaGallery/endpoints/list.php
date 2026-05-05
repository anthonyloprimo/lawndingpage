<?php
require_once lawnding_admin_path('modules/mediaGallery/helpers.php');
lawnding_init_session();

media_gallery_require_method('POST');
media_gallery_require_edit_site();

$paneId = (string) ($_POST['paneId'] ?? '');
$state = media_gallery_load_pane_state($paneId);

media_gallery_save_and_respond($state['json_path'], $state['data'], $state['items']);

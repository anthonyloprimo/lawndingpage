<?php
// Tests for lawnding_tg_bot_id_from_token() in admin/lib/tg-auth.php.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../admin/lib/tg-auth.php';

test_assert(
    lawnding_tg_bot_id_from_token('12345:AAFAKETOKEN-abc') === '12345',
    'standard bot token → numeric ID prefix'
);
test_assert(
    lawnding_tg_bot_id_from_token('') === '',
    'empty token → empty ID'
);
test_assert(
    lawnding_tg_bot_id_from_token('abc:def') === '',
    'non-numeric prefix → empty ID'
);
test_assert(
    lawnding_tg_bot_id_from_token('12345') === '',
    'token with no colon → empty ID'
);
test_assert(
    lawnding_tg_bot_id_from_token(':abc') === '',
    'token starting with colon → empty ID'
);
test_assert(
    lawnding_tg_bot_id_from_token('12345abc:def') === '',
    'mixed-alphanumeric prefix → empty ID (ctype_digit rejects)'
);
test_assert(
    lawnding_tg_bot_id_from_token('123456789:secretpart:withcolons') === '123456789',
    'multiple colons → only first split taken'
);

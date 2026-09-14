<?php

// Local-only router for `php -S`, used solely so the dev server can be
// launched with an explicit -d upload_tmp_dir override (upload_tmp_dir is
// PHP_INI_SYSTEM-only, so neither .user.ini nor ini_set() can set it — see
// docs/phases/PHASE_07.md's manual-verification note). Not used in
// production; artisan serve's own vendor router is unaffected.
chdir(__DIR__);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

if ($uri !== '/' && file_exists(__DIR__.$uri)) {
    return false;
}

require __DIR__.'/index.php';

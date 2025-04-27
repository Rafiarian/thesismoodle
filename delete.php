<?php
require_once('../../config.php');
require_login();

// Get the id to delete
$cpmkid = required_param('id', PARAM_INT);

// Cek dulu ada gak CPMK-nya
if (!$record = $DB->get_record('local_cpmk', ['id' => $cpmkid])) {
    throw new moodle_exception('invalidcpmk', 'local_edulog');
}

// Hapus dari database
$DB->delete_records('local_cpmk', ['id' => $cpmkid]);

// Redirect balik ke halaman utama
redirect(new moodle_url('/local/edulog/index.php'), 'CPMK has been deleted successfully.', 2);
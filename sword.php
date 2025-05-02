<?php

/**
 * Version details.
 *
 * @package    local_edulog
 * @author     Rafiarian
 * @copyright  Rafi Arian Yusuf, Radityo Prasetianto Wibowo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require('../../config.php');
require_login();
use local_edulog\utils_sword;

global $PAGE, $OUTPUT;

// Ambil parameter CPMK
$cpmkid = required_param('cpmkid', PARAM_INT);
$tempdir = __DIR__ . '/temp';
$inputfile = "$tempdir/sword_input_$cpmkid.csv";
$outputfile = "$tempdir/sword_output.json";

// 1. Ambil data log dari database
$logdata = utils_sword::get_most_visited_by_user($cpmkid);

// 2. Pastikan folder temp ada
if (!is_dir($tempdir)) {
    mkdir($tempdir, 0777, true);
}

// 3. Simpan log ke file CSV
$fp = fopen($inputfile, 'w');
fputcsv($fp, ['Time', 'User full name', 'Affected user', 'Event context', 'Component', 'Event name', 'Origin', 'IP address']);
foreach ($logdata as $row) {
    fputcsv($fp, [
        $row->time ?? '',
        $row->user_full_name ?? '',
        $row->affected_user ?? '',
        $row->event_context ?? '',
        $row->component ?? '',
        $row->event_name ?? '',
        $row->origin ?? '',
        $row->ip ?? '',
    ]);
}
fclose($fp);

// 4. Jalankan Python script
$python = trim(shell_exec("which python3"));
$pycmd = escapeshellcmd("python3 " . __DIR__ . "/py/sword.py $inputfile $outputfile");
exec($pycmd, $py_output, $ret);

// 5. Baca hasil JSON dari Python
$console_output = [];
if (file_exists($outputfile)) {
    $json = json_decode(file_get_contents($outputfile), true);
    $console_output = $json['console_output'] ?? ['No output returned.'];
}

// 6. Tampilkan ke Mustache
$templatecontext = [
    'cpmkid' => $cpmkid,
    'py_output' => $console_output,
];

// Setup Moodle page
$PAGE->set_url(new moodle_url('/local/edulog/sword.php', ['cpmkid' => $cpmkid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('SWORD Result');
$PAGE->set_heading('SWORD Result');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_edulog/sword_result', $templatecontext);
echo $OUTPUT->footer();

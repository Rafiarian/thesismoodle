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
use local_edulog\utils;
use local_edulog\utils_sword;
use local_edulog\utils_content;

global $PAGE, $OUTPUT;

// Get the CPMK ID from URL
$cpmkid = required_param('id', PARAM_INT);

$sort = optional_param('sort', 'content', PARAM_ALPHA);

$template = 'local_edulog/cpmk_content';

switch ($sort) {
    case 'content':
        $cpmk_data = utils::get_course_and_cpmk_name($cpmkid);
        $modules = utils_content::get_cpmk_modules($cpmkid);
        $assignments = utils_content::get_cpmk_assignments($cpmkid);
        $quizzes = utils_content::get_cpmk_quizzes($cpmkid);
        $template = 'local_edulog/cpmk_content';
        break;
    case 'most':
        $records = utils::get_most_visited_with_score($cpmkid);
        $template = 'local_edulog/cpmk_result_1';
        break;
    case 'least':
        $records = utils::get_least_visited_with_score($cpmkid);
        $template = 'local_edulog/cpmk_result_2';
        break;
    case 'notaccess':
        $records = utils::get_zero_visited_with_score($cpmkid);
        $template = 'local_edulog/cpmk_result_notaccess';
        break;
    case 'time':
        $access_data = utils::get_access_time_data($cpmkid);
        $cpmk_data = utils::get_course_and_cpmk_name($cpmkid);
        $records = $access_data['records'];
        $labels = $access_data['labels'];
        $counts = $access_data['counts'];
        $deadline = utils::get_quiz_deadline($cpmkid);
        $template = 'local_edulog/cpmk_result_3';
        break;
    case 'sword':
        $cpmk_data = utils::get_course_and_cpmk_name($cpmkid);
        $template = 'local_edulog/cpmk_result_sword';
        break;
    default:
            $cpmk_data = utils::get_course_and_cpmk_name($cpmkid);
            $modules = utils_content::get_cpmk_modules($cpmkid);
            $assignments = utils_content::get_cpmk_assignments($cpmkid);
            $quizzes = utils_content::get_cpmk_quizzes($cpmkid);
            $template = 'local_edulog/cpmk_content';
        break;
}

    error_log('Cek data content Modules' . print_r($modules, true));
    error_log('Cek data content Assignment' . print_r($assignments, true));
    error_log('Cek data content Quizzes' . print_r($quizzes, true));

    foreach ($records as &$record) {
        $record->profileurl = new moodle_url('/local/edulog/details.php', [
            'cpmkid' => $record->cpmkid ?? $cpmkid, // fallback kalau belum ada
            'userid' => $record->userid ?? $record->id // id user
        ]);
    }
    unset($record); 

// SWORD HANDLING UNTUK PYTHONNYA While keeping the view_result clean
if ($sort === 'sword') {
    $tempdir = __DIR__ . '/temp';
    $inputfile = "$tempdir/sword_input_$cpmkid.csv";
    $outputfile = "$tempdir/sword_output.json";

    // 1. Get log data
    $logdata = utils_sword::get_most_visited_by_user($cpmkid);

    // 2. Ensure temp dir exists
    if (!is_dir($tempdir)) {
        mkdir($tempdir, 0777, true);
    }

    // 3. Save to CSV
    $fp = fopen($inputfile, 'w');
    fputcsv($fp, ['Time', 'User full name', 'Affected user', 'Event context', 'Component', 'Event name','Description', 'Origin', 'IP address']);
    foreach ($logdata as $row) {
        fputcsv($fp, [
            $row->time ?? '',
            $row->user_full_name ?? '',
            $row->affected_user ?? '',
            $row->event_context ?? '',
            $row->component ?? '',
            $row->event_name ?? '',
            $row->description ?? '',
            $row->origin ?? '',
            $row->ip ?? '',
        ]);
    }
    fclose($fp);

    // 4. Run Python script
    $python = trim(shell_exec("which python3"));
    $pycmd = escapeshellcmd("python3 " . __DIR__ . "/py/sword.py $inputfile $outputfile");
    exec($pycmd, $py_output, $ret);

    // 5. Read JSON result
    $console_output = [];
    if (file_exists($outputfile)) {
        $json = json_decode(file_get_contents($outputfile), true);
        $console_output = $json['console_output'] ?? ['No output returned.'];
    }

    $templatecontext = [
        'cpmkid' => $cpmkid,
        'py_output' => $console_output,
    ];
} else {
    // Default template context
    $templatecontext = [
        'cpmkid' => $cpmkid,
        'records' => $records,
    ];

    if ($sort === 'time') {
        $templatecontext['labels'] = $labels;
        $templatecontext['counts'] = $counts;
        $templatecontext['course_fullname'] = $cpmk_data['course_fullname'];
        $templatecontext['cpmk_name'] = $cpmk_data['cpmk_name'];
        $templatecontext['deadline'] = $deadline;
    }
}

// Prepare data for Mustache
$templatecontext = [
    'view_detail' => new moodle_url('/local/edulog/view_result.php', ['id' => $records->id]),
    'course_fullname' => $records ? reset($records)->course_fullname : '',
    'cpmk_name' => $records ? reset($records)->cpmk_name : '',
    'records' => array_values($records),
    'labels' => json_encode($labels), //cpmk_graph ⬅ encode ke JSON
    'counts' => json_encode($counts), //cpmk_graph
    'course_fullname_graph' => $cpmk_data['course_fullname'] ?? '', //cpmk_graph
    'cpmk_name_graph' => $cpmk_data['cpmk_name'] ?? '', //cpmk_graph
    'deadline' => isset($deadline) ? $deadline : '',
    'modules' => array_values($modules),   //cpmk_content
    'assignments' => array_values($assignments), //cpmk_content
    'quizzes' => array_values($quizzes),$quizzes, //cpmk_content
    'is_most' => $sort === 'most', //sorting function
    'is_least' => $sort === 'least',
    'is_none' => $sort === 'notaccess',
    'is_time' => $sort === 'time',
    'is_sword ' => $sort === 'sword',
    'is_content' => $sort === 'content',
];

// Setup Moodle page
$PAGE->set_url(new moodle_url('/local/edulog/view_result.php', ['cpmkid' => $cpmkid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('CPMK Result');
$PAGE->set_heading('CPMK Result');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template($template, $templatecontext);
echo $OUTPUT->footer();

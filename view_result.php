<?php

require('../../config.php');
require_login();
use local_edulog\utils;
use local_edulog\utils_deviation;
use local_edulog\utils_content;

global $PAGE, $OUTPUT;

$cpmkid = required_param('id', PARAM_INT);
$sort = optional_param('sort', 'content', PARAM_ALPHA);
$should_run_script = optional_param('runcmd', 0, PARAM_INT);

// ==== Modular Methods ==== //

function handleContentView($cpmkid) {
    $data['cpmk_data'] = utils::get_course_and_cpmk_name($cpmkid);
    $data['modules'] = utils_content::get_cpmk_modules($cpmkid);
    $data['assignments'] = utils_content::get_cpmk_assignments($cpmkid);
    $data['quizzes'] = utils_content::get_cpmk_quizzes($cpmkid);
    $data['template'] = 'local_edulog/cpmk_content';
    return $data;
}

function handleMostVisitedView($cpmkid) {
    return [
        'records' => utils::get_most_visited_with_score($cpmkid),
        'template' => 'local_edulog/cpmk_result_mostvisit'
    ];
}

function handleLeastVisitedView($cpmkid) {
    return [
        'records' => utils::get_least_visited_with_score($cpmkid),
        'template' => 'local_edulog/cpmk_result_leastvisit'
    ];
}

function handleZeroAccessView($cpmkid) {
    return [
        'records' => utils::get_zero_visited_with_score($cpmkid),
        'template' => 'local_edulog/cpmk_result_notaccess'
    ];
}

function handleAccessTimeView($cpmkid) {
    $access_data = utils::get_access_time_data($cpmkid);
    $cpmk_data = utils::get_course_and_cpmk_name($cpmkid);
    return [
        'records' => $access_data['records'],
        'labels' => $access_data['labels'],
        'counts' => $access_data['counts'],
        'cpmk_data' => $cpmk_data,
        'deadline' => utils::get_quiz_deadline($cpmkid),
        'template' => 'local_edulog/cpmk_result_accesstime'
    ];
}
function handleDeviationView($cpmkid, $should_run_script) {
    $cpmk_data = utils::get_course_and_cpmk_name($cpmkid);
    $tempdir = __DIR__ . '/temp';
    if (!is_dir($tempdir)) {
        mkdir($tempdir, 0777, true);
    }
    $tempdir = str_replace('\\', '/', realpath($tempdir));
    $outputfile = "$tempdir/output/deviation_output_{$cpmkid}.json";
    $inputfile = "$tempdir/deviation_input_$cpmkid.csv";
    $logdata = utils_deviation::get_deviation($cpmkid);

    if ($should_run_script) {
        $pycmd = escapeshellcmd("python3 " . __DIR__ . "/py/deviation.py $inputfile $outputfile");
        exec($pycmd, $py_output, $ret);
        $fp = fopen($inputfile, 'w');
        fputcsv($fp, ['Time', 'User full name', 'Affected user', 'Event context', 'Component', 'Event name','Description', 'Origin', 'IP address']);
        foreach ($logdata as $row) {
            fputcsv($fp, [
                $row->time ?? '', $row->user_full_name ?? '', $row->affected_user ?? '',
                $row->event_context ?? '', $row->component ?? '', $row->event_name ?? '',
                $row->description ?? '', $row->origin ?? '', $row->ip ?? ''
            ]);
        }
        fclose($fp);
    }

    $structured_output = [
    'number_summary' => '',
    'classified_table' => [],
    'raw_lines' => [],
    'error' => null

    
];

if (file_exists($outputfile)) {
    $json_raw = file_get_contents($outputfile);
    $json = json_decode($json_raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $structured_output['error'] = 'JSON decode error: ' . json_last_error_msg();
    } elseif (is_array($json)) {
        // Ambil 1 kalimat summary
        if (!empty($json['Number of unusual activities']) && is_array($json['Number of unusual activities'])) {
            foreach ($json['Number of unusual activities'] as $line) {
                if (stripos($line, 'There were') !== false) {
                    $structured_output['number_summary'] = $line;
                    break;
                }
            }
        }

        // Ambil tabel classified
        if (!empty($json['Unusual activity (classified)']) && is_array($json['Unusual activity (classified)'])) {
            $structured_output['classified_table'] = $json['Unusual activity (classified)'];
        }

        // Ambil raw log lines
        if (!empty($json['Frequent occurrence of activity']) && is_array($json['Frequent occurrence of activity'])) {
            $structured_output['raw_lines'] = $json['Frequent occurrence of activity'];
        }
    } else {
        $structured_output['error'] = 'Invalid JSON format received.';
    }
} else {
    $structured_output['error'] = "Output file not found at path: $outputfile";
}

    return [
    'cpmkid' => $cpmkid,
    'cpmk_data' => $cpmk_data,
    'template' => 'local_edulog/cpmk_result_deviation',
    'py_output' => $structured_output
    ];
    
}

// ==== Dispatcher ====

switch ($sort) {
    case 'content':
        extract(handleContentView($cpmkid));
        break;
    case 'most':
        extract(handleMostVisitedView($cpmkid));
        break;
    case 'least':
        extract(handleLeastVisitedView($cpmkid));
        break;
    case 'notaccess':
        extract(handleZeroAccessView($cpmkid));
        break;
    case 'time':
        extract(handleAccessTimeView($cpmkid));
        break;
    case 'sword':
        extract(handleDeviationView($cpmkid, $should_run_script));
        break;
    default:
        extract(handleContentView($cpmkid));
        break;
}

// ==== Tambahan view logic ====

foreach ($records as &$record) {
    $record->profileurl = new moodle_url('/local/edulog/details.php', [
        'cpmkid' => $record->cpmkid ?? $cpmkid,
        'userid' => $record->userid ?? $record->id
    ]);
}
unset($record);

$templatecontext = [
    'view_detail' => new moodle_url('/local/edulog/view_result.php', ['id' => $records->id ?? 0]),
    'course_fullname' => $records ? reset($records)->course_fullname : '',
    'cpmk_name' => $records ? reset($records)->cpmk_name : '',
    'records' => array_values($records ?? []),
    'labels' => json_encode($labels ?? []),
    'counts' => json_encode($counts ?? []),
    'course_fullname_graph' => $cpmk_data['course_fullname'] ?? '',
    'cpmk_name_graph' => $cpmk_data['cpmk_name'] ?? '',
    'py_output' => $py_output ?? [],
    'deadline' => $deadline ?? '',
    'modules' => array_values($modules ?? []),
    'assignments' => array_values($assignments ?? []),
    'quizzes' => array_values($quizzes ?? []),
    'is_most' => $sort === 'most',
    'is_least' => $sort === 'least',
    'is_none' => $sort === 'notaccess',
    'is_time' => $sort === 'time',
    'is_sword' => $sort === 'sword',
    'is_content' => $sort === 'content',
];

// ==== Render ====
$PAGE->set_url(new moodle_url('/local/edulog/view_result.php', ['cpmkid' => $cpmkid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('CPMK Result');
$PAGE->set_heading('CPMK Result');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template($template, $templatecontext);
echo $OUTPUT->footer();
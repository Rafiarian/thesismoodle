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

global $PAGE, $OUTPUT;

// Get the CPMK ID from URL
$cpmkid = required_param('id', PARAM_INT);

$sort = optional_param('sort', 'most', PARAM_ALPHA);

$template = 'local_edulog/cpmk_result_1';

switch ($sort) {
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
        $records = $access_data['records'];
        $labels = $access_data['labels'];
        $counts = $access_data['counts'];
        $deadline = utils::get_quiz_deadline($cpmkid);
        $template = 'local_edulog/cpmk_result_3';
        break;
    default:
        $records = utils::get_most_visited_with_score($cpmkid);
        $template = 'local_edulog/cpmk_result_1';
        break;
}


error_log('Records views' . print_r($records, true));

error_log('label nih!'. print_r($labels, true));
error_log('COUNT NIH!' . print_r($counts, true));

// Prepare data for Mustache
$templatecontext = [
    'course_fullname' => $records ? reset($records)->course_fullname : '',
    'cpmk_name' => $records ? reset($records)->cpmk_name : '',
    'labels' => json_encode($labels), // ⬅ encode ke JSON
    'counts' => json_encode($counts),
    'deadline' => isset($deadline) ? $deadline : '',
    'records' => array_values($records),
    'is_most' => $sort === 'most',
    'is_least' => $sort === 'least',
    'is_none' => $sort === 'notaccess',
    'is_time' => $sort === 'time',
];

error_log('Template context: ' . print_r($templatecontext, true));

// Setup Moodle page
$PAGE->set_url(new moodle_url('/local/edulog/view_result.php', ['cpmkid' => $cpmkid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('CPMK Result');
$PAGE->set_heading('CPMK Result');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template($template, $templatecontext);
echo $OUTPUT->footer();

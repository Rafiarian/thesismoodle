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
        $records = utils::get_access_time_data($cpmkid);
        $template = 'local_edulog/cpmk_result_time';
        break;
    default:
        $records = utils::get_most_visited_with_score($cpmkid);
        $template = 'local_edulog/cpmk_result_1';
        break;
}




// Prepare data for Mustache
$templatecontext = [
    'course_fullname' => $records ? reset($records)->course_fullname : '',
    'cpmk_name' => $records ? reset($records)->cpmk_name : '',
    'records' => array_values($records),
    'is_most' => $sort === 'most',
    'is_least' => $sort === 'least',
    'is_none' => $sort === 'notaccess',
    'is_time' => $sort === 'time',
];

// Setup Moodle page
$PAGE->set_url(new moodle_url('/local/edulog/view_result.php', ['cpmkid' => $cpmkid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('CPMK Result');
$PAGE->set_heading('CPMK Result');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template($template, $templatecontext);
echo $OUTPUT->footer();

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

// Fetch data using the utility function
$records = utils::get_student_data($cpmkid);

// Prepare data for Mustache
$templatecontext = [
    'course_fullname' => $records ? reset($records)->course_fullname : '',
    'cpmk_name' => $records ? reset($records)->cpmk_name : '',
    'records' => array_values($records),
];

// Setup Moodle page
$PAGE->set_url(new moodle_url('/local/edulog/view_result.php', ['cpmkid' => $cpmkid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('CPMK Result');
$PAGE->set_heading('CPMK Result');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_edulog/cpmk_result_1', $templatecontext);
echo $OUTPUT->footer();

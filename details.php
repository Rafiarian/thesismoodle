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
use local_edulog\utils_detail;

global $PAGE, $OUTPUT;

// Get CPMK ID and USER ID from URL
$cpmkid = required_param('cpmkid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);

// Fetch the data
$userdetails = utils_detail::get_user_cpmk_course_info($cpmkid, $userid);
$moduleaccess = utils_detail::get_most_visited_by_user($cpmkid, $userid);
$activity = utils_detail::get_access_time_data_per_user($cpmkid, $userid);

// OPTIONAL (kalau mau tambah quiz deadline)
// $deadlines = utils_detail::get_quiz_deadline($cpmkid);

// Prepare template context
$templatecontext = [
    'username' => $userdetails->username ?? '',
    'coursefullname' => $userdetails->course_fullname ?? '',
    'cpmkname' => $userdetails->cpmk_name ?? '',
    'modules' => array_values($moduleaccess),
    'labels' => json_encode($activity['labels']),
    'counts' => json_encode($activity['counts']),
    'hasdata' => !empty($moduleaccess),
    // 'deadline' => $deadlines, // Uncomment kalau mau pakai deadline
];

// Debugging (hapus di production)
// error_log('Template context: ' . print_r($templatecontext, true));

// Setup the page
$PAGE->set_url(new moodle_url('/local/edulog/details.php', ['cpmkid' => $cpmkid, 'userid' => $userid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('User Activity Detail');
$PAGE->set_heading('User Activity Detail');

// Output
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_edulog/detail_result', $templatecontext);
echo $OUTPUT->footer();
<?php

/**
 * Version details.
 *
 * @package    local_edulog
 * @author     Rafiarian
 * @copyright  Rafi Arian Yusuf, Radityo Prasetianto Wibowo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/edulog/index.php'));
$PAGE->set_title('Edulog Insight');
$PAGE->set_heading('Edulog Insight');

$userid = $USER->id;

list($records, $totalcount) = fetch_cpmk_records($userid);

// Prepare data for mustache
$templatecontext = [
    'username' => fullname($USER),
    'records' => []
];

foreach ($records as $record) {
    $templatecontext['records'][] = [
        'timecreated' => userdate($record->timecreated),
        'fullname' => $record->fullname,
        'cpmk_name' => $record->cpmk_name,
        'info_url' => new moodle_url('/local/edulog/view_result.php', ['id' => $record->id]),
        'delete_url' => new moodle_url('/local/edulog/delete.php', ['id' => $record->id])
    ];
}

// Render pagination
$page = optional_param('page', 0, PARAM_INT);
$perpage = 10;
$baseurl = new moodle_url('/local/edulog/index.php');
$templatecontext['pagination'] = $OUTPUT->paging_bar($totalcount, $page, $perpage, $baseurl);

// Render mustache
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_edulog/index', $templatecontext);
echo $OUTPUT->footer();

function fetch_cpmk_records($userid) {
    global $DB;
    $page = optional_param('page', 0, PARAM_INT);
    $perpage = 10;
    $offset = $page * $perpage;

    $totalcount = $DB->count_records('local_cpmk', ['userid' => $userid]);

    $sql = "SELECT a.*, b.fullname
            FROM {local_cpmk} a
            JOIN {course} b ON a.courseid = b.id
            WHERE a.userid = :userid
            ORDER BY a.timecreated DESC
            LIMIT $perpage OFFSET $offset";

    $params = [
        'userid' => $userid
    ];

    $records = $DB->get_records_sql($sql, $params);

    return [$records, $totalcount];
}
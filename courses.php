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
 $PAGE->set_url(new moodle_url('/local/edulog/courses.php')); // change this if needed
 $PAGE->set_context(context_system::instance());
 $PAGE->set_title('My Teaching Courses');
 
 global $USER, $DB;
 
 // Role ID for "editingteacher" (default is 3 in Moodle)
 $teacherroleid = 3;
 
 // SQL to get courses where the user is a teacher
//  $sql = "SELECT c.*
//          FROM {course} c
//          JOIN {context} ctx ON ctx.contextlevel = :courselevel AND ctx.instanceid = c.id
//          JOIN {role_assignments} ra ON ra.contextid = ctx.id
//          WHERE ra.userid = :userid AND ra.roleid = :roleid
//          ORDER BY c.fullname ASC";
 
//  $params = [
//      'courselevel' => CONTEXT_COURSE,
//      'userid' => $USER->id,
//      'roleid' => $teacherroleid,
//  ];
 
 $courses = $DB->get_records_sql($sql, $params);
 
 $templatecontext = (object)[
     'courses' => array_values($courses),
     'username' => $USER->username,
 ];
 
 echo $OUTPUT->header();
 echo $OUTPUT->render_from_template('local_edulog/courses', $templatecontext);
 echo $OUTPUT->footer();
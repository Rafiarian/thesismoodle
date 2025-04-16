<?php


/**
 * Version details.
 *
 * @package    local_edulog
 * @author     Rafiarian
 * @copyright  Rafi Arian Yusuf, Radityo Prasetianto Wibowo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 //location edulog/classes/utils.php
 namespace local_edulog;

 defined('MOODLE_INTERNAL') || die();
 
 class utils {
     public static function get_student_data($cpmkid) {
         global $DB;
 
         $sql = "SELECT 
                     u.id, 
                     CONCAT(u.firstname, ' ', u.lastname) AS name, 
                     c.cpmk_name, 
                     COUNT(l.id) AS visits,
                     d.fullname AS course_fullname
                 FROM {local_cpmk} c
                 JOIN {local_cpmk_to_modules} m 
                    ON m.cpmkid = c.id
                 JOIN mdl_course d
                    ON d.id = c.courseid
                 JOIN {logstore_standard_log} l 
                     ON l.contextinstanceid = m.coursemoduleid 
                     AND l.target = 'course_module'
                     AND l.courseid = c.courseid
                 JOIN {user} u 
                     ON u.id = l.userid
                 WHERE c.id = :cpmkid
                 GROUP BY u.id, name, c.cpmk_name, d.fullname";
 
         return $DB->get_records_sql($sql, ['cpmkid' => $cpmkid]);
     }
 }
<?php


/**
 * Version details.
 *
 * @package    local_edulog
 * @author     Rafiarian
 * @copyright  Rafi Arian Yusuf, Radityo Prasetianto Wibowo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 //location edulog/classes/utils_sword.php
 namespace local_edulog;

 defined('MOODLE_INTERNAL') || die();
 
 class utils_content {
    public static function get_cpmk_modules($cpmkid) {
        global $DB;
        $sql = "SELECT cm.name AS module_name
                FROM {local_cpmk_to_modules} m
                JOIN {course_modules} cmid ON cmid.id = m.coursemoduleid
                JOIN {modules} mo ON mo.id = cmid.module
                JOIN {url} cm ON cm.id = cmid.instance AND mo.name = 'url'
                WHERE m.cpmkid = :cpmkid";
        return $DB->get_records_sql($sql, ['cpmkid' => $cpmkid]);
    }

    public static function get_cpmk_assignments($cpmkid) {
        global $DB;
        $sql = "SELECT a.name AS assign_name
                FROM {local_cpmk_to_assign} m
                JOIN {assign} a ON a.id = m.assignid
                WHERE m.cpmkid = :cpmkid";
        return $DB->get_records_sql($sql, ['cpmkid' => $cpmkid]);
    }

    public static function get_cpmk_quizzes($cpmkid) {
        global $DB;
        $sql = "SELECT q.name AS quiz_name
                FROM {local_cpmk_to_quiz} m
                JOIN {quiz} q ON q.id = m.quizid
                WHERE m.cpmkid = :cpmkid";
        return $DB->get_records_sql($sql, ['cpmkid' => $cpmkid]);
    }

    public static function get_cpmk_name($cpmkid) {
        global $DB;
        $sql = "SELECT c.cpmk_name, co.fullname
                FROM {local_cpmk} c
                JOIN {course} co ON co.id = c.courseid
                WHERE c.id = :cpmkid";
        return $DB->get_record_sql($sql, ['cpmkid' => $cpmkid]);
    }

 }
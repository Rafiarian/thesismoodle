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
 
 class utils_sword {
    public static function get_most_visited_by_user($cpmkid) {
        global $DB;

        $sql = "SELECT 
                    FROM_UNIXTIME(l.timecreated, '%d/%m/%y, %H:%i') AS time,
                    CONCAT(u.firstname, ' ', u.lastname) AS user_full_name,
                    COALESCE(CONCAT(ru.firstname, ' ', ru.lastname), '-') AS affected_user,
                    CONCAT(UPPER(l.component), ': ', cm.name) AS event_context,
                    l.component,
                    REPLACE(SUBSTRING_INDEX(l.eventname, '\\\\', -1), '_', ' ') AS event_name,
                    CONCAT('The user ', u.firstname, ' ', u.lastname, ' accessed ', cm.name) AS description,
                    l.origin,
                    l.ip
                FROM mdl_logstore_standard_log l
                JOIN mdl_local_cpmk_to_modules m ON m.coursemoduleid = l.contextinstanceid
                JOIN mdl_user u ON u.id = l.userid
                LEFT JOIN mdl_user ru ON ru.id = l.relateduserid
                JOIN mdl_course_modules cmid ON cmid.id = l.contextinstanceid
                JOIN mdl_url cm ON cm.id = cmid.instance AND cmid.module = (
                    SELECT id FROM mdl_modules WHERE name = 'url' LIMIT 1
                )
                -- ❌ Exclude Admin and Teacher roles
                LEFT JOIN mdl_role_assignments ra ON ra.userid = u.id
                LEFT JOIN mdl_context ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 -- 50 = course
                LEFT JOIN mdl_role r ON r.id = ra.roleid
                WHERE m.cpmkid = :cpmkid
                AND l.target = 'course_module'
                AND (r.shortname IS NULL OR r.shortname NOT IN ('editingteacher', 'teacher', 'manager'))
                ORDER BY l.timecreated DESC";  
        
        return $DB->get_records_sql($sql, ['cpmkid' => $cpmkid]);
    }
 }
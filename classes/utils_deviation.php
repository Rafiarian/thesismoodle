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
 
 class utils_deviation {
    public static function get_deviation($cpmkid) {
        global $DB;

        $sql = "SELECT 
            l.id AS logid,
            FROM_UNIXTIME(l.timecreated, '%d/%m/%y, %H:%i') AS time,
            CONCAT(u.firstname, ' ', u.lastname) AS user_full_name,
            COALESCE(CONCAT(ru.firstname, ' ', ru.lastname), '-') AS affected_user,
            CONCAT(UPPER(l.component), ': ', 
                COALESCE(url.name, book.name, resource.name, quiz.name, 'Unknown')
            ) AS event_context,
            l.component,
            REPLACE(SUBSTRING_INDEX(l.eventname, '\\\\', -1), '_', ' ') AS event_name,
            CONCAT('The user ', u.firstname, ' ', u.lastname, ' accessed ', 
                COALESCE(url.name, book.name, resource.name, quiz.name, 'Unknown')
            ) AS description,
            l.origin,
            l.ip
        FROM {logstore_standard_log} l
        JOIN {local_cpmk_to_modules} m ON m.coursemoduleid = l.contextinstanceid
        JOIN {user} u ON u.id = l.userid
        LEFT JOIN {user} ru ON ru.id = l.relateduserid
        JOIN {course_modules} cmid ON cmid.id = l.contextinstanceid
        JOIN {modules} mo ON mo.id = cmid.module
        LEFT JOIN {url} url ON url.id = cmid.instance AND mo.name = 'url'
        LEFT JOIN {book} book ON book.id = cmid.instance AND mo.name = 'book'
        LEFT JOIN {resource} resource ON resource.id = cmid.instance AND mo.name = 'resource'
        LEFT JOIN {quiz} quiz ON quiz.id = cmid.instance AND mo.name = 'quiz'
        LEFT JOIN {role_assignments} ra ON ra.userid = u.id
        LEFT JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
        LEFT JOIN {role} r ON r.id = ra.roleid
        WHERE m.cpmkid = :cpmkid
        AND l.contextlevel = 70
        AND (r.id IS NULL OR r.shortname NOT IN ('editingteacher', 'teacher', 'manager'))
        ORDER BY l.timecreated DESC";    
        
        return $DB->get_records_sql($sql, ['cpmkid' => $cpmkid], 0, 0);
    }
 }



 
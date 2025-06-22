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

         // Step 1: Fetch the latest quiz deadline
            $quiz_deadline_sql = "SELECT 
                                    MAX(q.timeclose) AS latest_deadline
                                FROM {local_cpmk_to_quiz} cq
                                JOIN {quiz} q ON q.id = cq.quizid
                                WHERE cq.cpmkid = :cpmkid";
        
            $latest_deadline_timestamp = $DB->get_field_sql($quiz_deadline_sql, ['cpmkid' => $cpmkid]);
        
            if (!$latest_deadline_timestamp) {
                // No quiz deadline found, return empty
                return [
                    'records' => [],
                    'labels' => json_encode([]),   
                    'counts' => json_encode([]),
                ];
            }
        
            $latest_deadline_date = date('Y-m-d', $latest_deadline_timestamp);
            $start_date = date('Y-m-d', strtotime('-45 days', $latest_deadline_timestamp));

        // Step 2: Fetch Data log data
        $sql = "SELECT 
            l.id AS logid,
            FROM_UNIXTIME(l.timecreated, '%d/%m/%y, %H:%i') AS time,
            CONCAT(u.firstname) AS user_full_name,
            COALESCE(CONCAT(ru.firstname), '-') AS affected_user,
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
        AND l.timecreated BETWEEN UNIX_TIMESTAMP(:start_date) AND UNIX_TIMESTAMP(:end_date)
        ORDER BY l.timecreated DESC";    

        $params = [
        'cpmkid' => $cpmkid,
        'start_date' => $start_date . ' 00:00:00',
        'end_date' => $latest_deadline_date . ' 23:59:59'
        ];
        
        return $DB->get_records_sql($sql, $params, 0, 0);
    }
 }



 
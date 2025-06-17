<?php


/**
 * Version details.
 *
 * @package    local_edulog
 * @author     Rafiarian
 * @copyright  Rafi Arian Yusuf, Radityo Prasetianto Wibowo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 //location edulog/classes/utils_detail.php
 namespace local_edulog;

 defined('MOODLE_INTERNAL') || die();
 
 class utils_detail {
    public static function get_most_visited_by_user($cpmkid, $userid) {
        global $DB;
    
        $sql = "SELECT 
                    cm.id AS coursemoduleid,
                    cm.idnumber,
                    CASE 
                        WHEN m.name IS NOT NULL THEN CONCAT(m.name, ' - ', cm.id)
                        ELSE cm.id
                    END AS modulename,
                    COALESCE(qz.name, res.name, bz.name, uz.name, ass.name, forum.name, page.name, folder.name) AS instance_name,
                    COUNT(DISTINCT l.id) AS visits
                FROM mdl_local_cpmk c
                JOIN mdl_local_cpmk_to_modules ctm 
                    ON ctm.cpmkid = c.id
                JOIN mdl_local_cpmk_to_quiz ctq
                    ON ctq.cpmkid = c.id
                JOIN mdl_local_cpmk_to_assign cta
                    ON cta.cpmkid = c.id
                JOIN mdl_course_modules cm 
                    ON cm.id = ctm.coursemoduleid
                JOIN mdl_modules m 
                    ON m.id = cm.module
                LEFT JOIN mdl_quiz qz
                    ON qz.id = cm.instance AND m.name = 'quiz'
                LEFT JOIN mdl_book bz
                    ON bz.id = cm.instance AND m.name = 'book'
                LEFT JOIN mdl_url uz
                    ON uz.id = cm.instance AND m.name = 'url'
                LEFT JOIN mdl_resource res
                    ON res.id = cm.instance AND m.name = 'resource'
                LEFT JOIN mdl_assign ass
                    ON ass.id = cm.instance AND m.name = 'assign'
                LEFT JOIN mdl_forum forum
                    ON forum.id = cm.instance AND m.name = 'forum'
                LEFT JOIN mdl_page page
                    ON page.id = cm.instance AND m.name = 'page'
                LEFT JOIN mdl_folder folder
                    ON folder.id = cm.instance AND m.name = 'folder'
                LEFT JOIN mdl_logstore_standard_log l 
                    ON l.contextinstanceid = cm.id 
                    AND l.userid = :userid
                    AND l.target = 'course_module'
                    AND l.courseid = c.courseid
                    AND (
                        l.timecreated <= (
                            SELECT MAX(q.timeclose)
                            FROM mdl_local_cpmk_to_quiz cq
                            JOIN mdl_quiz q ON q.id = cq.quizid
                            WHERE cq.cpmkid = c.id
                        )
                        OR (
                            SELECT COUNT(1)
                            FROM mdl_local_cpmk_to_quiz cq
                            WHERE cq.cpmkid = c.id
                        ) = 0
                    )
                LEFT JOIN mdl_context ctx 
                    ON ctx.instanceid = l.courseid AND ctx.contextlevel = 50
                LEFT JOIN mdl_role_assignments ra 
                    ON ra.userid = l.userid AND ra.contextid = ctx.id
                LEFT JOIN mdl_role r 
                    ON r.id = ra.roleid
                WHERE c.id = :cpmkid
                AND r.shortname NOT IN ('editingteacher', 'teacher', 'manager')  -- exclude teachers & admin
                GROUP BY cm.id, cm.idnumber, m.name, qz.name, res.name, bz.name, uz.name, ass.name, forum.name, page.name, folder.name
                ORDER BY visits DESC";
    
        $params = [
            'cpmkid' => $cpmkid,
            'userid' => $userid
        ];
        error_log("SQL QUERY: " . $sql);
        return $DB->get_records_sql($sql, $params);
    }

    public static function get_user_cpmk_course_info($cpmkid, $userid) {
        global $DB;
    
        $sql = "SELECT 
                    u.id AS userid,
                    CONCAT(u.firstname, ' ', u.lastname) AS username,
                    c.id AS cpmkid,
                    c.cpmk_name,
                    d.id AS courseid,
                    d.fullname AS course_fullname
                FROM {local_cpmk} c
                JOIN {course} d ON d.id = c.courseid
                JOIN {user_enrolments} ue ON ue.enrolid IN (
                    SELECT e.id FROM {enrol} e WHERE e.courseid = d.id
                )
                JOIN {user} u ON u.id = ue.userid
                WHERE c.id = :cpmkid
                  AND u.id = :userid
                LIMIT 1";
    
        $params = [
            'cpmkid' => $cpmkid,
            'userid' => $userid
        ];
    
        return $DB->get_record_sql($sql, $params);
    }

    public static function get_access_time_data_per_user($cpmkid, $userid) {
        global $DB;
    
        // Step 1: Ambil deadline quiz terakhir
        $quiz_deadline_sql = "SELECT 
                                  MAX(q.timeclose) AS latest_deadline
                              FROM {local_cpmk_to_quiz} cq
                              JOIN {quiz} q ON q.id = cq.quizid
                              WHERE cq.cpmkid = :cpmkid";
    
        $latest_deadline_timestamp = $DB->get_field_sql($quiz_deadline_sql, ['cpmkid' => $cpmkid]);
    
        if (!$latest_deadline_timestamp) {
            // Kalau tidak ada deadline quiz
            return [
                'records' => [],
                'labels' => json_encode([]),
                'counts' => json_encode([]),
            ];
        }
    
        $latest_deadline_date = date('Y-m-d', $latest_deadline_timestamp);
        $start_date = date('Y-m-d', strtotime('-45 days', $latest_deadline_timestamp));
    
        // Step 2: Ambil aktivitas user per hari
        $sql = "SELECT 
                    DATE(FROM_UNIXTIME(l.timecreated)) AS access_date,
                    COUNT(l.id) AS access_count
                FROM {logstore_standard_log} l
                JOIN {local_cpmk_to_modules} m ON m.coursemoduleid = l.contextinstanceid
                WHERE m.cpmkid = :cpmkid
                  AND l.userid = :userid
                  AND l.target = 'course_module'
                  AND l.timecreated BETWEEN UNIX_TIMESTAMP(:start_date) AND UNIX_TIMESTAMP(:end_date)
                GROUP BY access_date
                ORDER BY access_date ASC";
    
        $access_data = $DB->get_records_sql($sql, [
            'cpmkid' => $cpmkid,
            'userid' => $userid,
            'start_date' => $start_date . ' 00:00:00',
            'end_date' => $latest_deadline_date . ' 23:59:59',
        ]);
    error_log("Total records returned: " . count($access_data));
        // Step 3: Mapping tanggal - jumlah akses
        $access_map = [];
        foreach ($access_data as $data) {
            $access_map[$data->access_date] = $data->access_count;
        }
    
        // Step 4: Isi semua tanggal dari start ke deadline
        $records = [];
        $labels = [];
        $counts = [];
    
        $current = strtotime($start_date);
        $end = strtotime($latest_deadline_date);
    
        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $count = isset($access_map[$date]) ? $access_map[$date] : 0;
    
            $records[] = (object)[
                'label' => $date,
                'count' => $count,
            ];
    
            $labels[] = $date;
            $counts[] = $count;
            $current = strtotime('+1 day', $current);
        }
    
        // Step 5: Return final
        return [
            'records' => $records,
            'labels' => $labels,
            'counts' => $counts,
        ];
    }

 }
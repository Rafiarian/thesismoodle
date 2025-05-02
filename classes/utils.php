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
    public static function get_most_visited($cpmkid) {
        global $DB;
    
        $sql = "SELECT 
                    u.id, 
                    CONCAT(u.firstname, ' ', u.lastname) AS namee, 
                    c.cpmk_name, 
                    COUNT(l.id) AS visits,
                    d.fullname AS course_fullname
                FROM {local_cpmk} c
                JOIN {local_cpmk_to_modules} m 
                    ON m.cpmkid = c.id
                JOIN {course} d
                    ON d.id = c.courseid
                JOIN {user_enrolments} ue 
                    ON ue.enrolid IN (
                        SELECT e.id FROM {enrol} e WHERE e.courseid = d.id
                    )
                JOIN {user} u 
                    ON u.id = ue.userid
                JOIN {context} ctx
                    ON ctx.instanceid = d.id AND ctx.contextlevel = 50
                JOIN {role_assignments} ra 
                    ON ra.userid = u.id AND ra.contextid = ctx.id
                JOIN {role} r 
                    ON r.id = ra.roleid
                LEFT JOIN {logstore_standard_log} l 
                    ON l.userid = u.id
                    AND l.contextinstanceid = m.coursemoduleid 
                    AND l.target = 'course_module'
                    AND l.courseid = c.courseid
                    AND l.timecreated <= (
                        SELECT MAX(q.timeclose)
                        FROM {local_cpmk_to_quiz} cq
                        JOIN {quiz} q ON q.id = cq.quizid
                        WHERE cq.cpmkid = c.id
                    )
                WHERE c.id = :cpmkid
                AND r.shortname = 'student'
                GROUP BY u.id, namee, c.cpmk_name, d.fullname
                ORDER BY visits DESC";
    
        return $DB->get_records_sql($sql, ['cpmkid' => $cpmkid]);
    }
     
     public static function get_least_visited($cpmkid) {
        global $DB;

        $sql = "SELECT 
                    u.id, 
                    CONCAT(u.firstname, ' ', u.lastname) AS namee, 
                    c.cpmk_name, 
                    COUNT(l.id) AS visits,
                    d.fullname AS course_fullname
                FROM {local_cpmk} c
                JOIN {local_cpmk_to_modules} m 
                    ON m.cpmkid = c.id
                JOIN {course} d
                    ON d.id = c.courseid
                JOIN {user_enrolments} ue 
                    ON ue.enrolid IN (
                        SELECT e.id FROM {enrol} e WHERE e.courseid = d.id
                    )
                JOIN {user} u 
                    ON u.id = ue.userid
                JOIN {context} ctx
                    ON ctx.instanceid = d.id AND ctx.contextlevel = 50
                JOIN {role_assignments} ra 
                    ON ra.userid = u.id AND ra.contextid = ctx.id
                JOIN {role} r 
                    ON r.id = ra.roleid
                LEFT JOIN {logstore_standard_log} l 
                    ON l.userid = u.id
                    AND l.contextinstanceid = m.coursemoduleid 
                    AND l.target = 'course_module'
                    AND l.courseid = c.courseid
                    AND l.timecreated <= (
                        SELECT MAX(q.timeclose)
                        FROM {local_cpmk_to_quiz} cq
                        JOIN {quiz} q ON q.id = cq.quizid
                        WHERE cq.cpmkid = c.id
                    )
                WHERE c.id = :cpmkid
                AND r.shortname = 'student'
                GROUP BY u.id, namee, c.cpmk_name, d.fullname
                ORDER BY visits ASC";    

        return $DB->get_records_sql($sql, ['cpmkid' => $cpmkid]);
    }

    public static function get_students_not_accessing($cpmkid) {
        global $DB;
    
        $sql = "SELECT 
                    u.id,
                    CONCAT(u.firstname, ' ', u.lastname) AS name,
                    c.cpmk_name,
                    d.fullname AS course_fullname,
                    0 AS visits
                FROM {local_cpmk} c
                JOIN {local_cpmk_to_modules} m ON m.cpmkid = c.id
                JOIN {course} d ON d.id = c.courseid
                JOIN {user_enrolments} ue ON ue.enrolid IN (
                    SELECT e.id FROM {enrol} e WHERE e.courseid = d.id
                )
                JOIN {user} u ON u.id = ue.userid
                JOIN {context} ctx 
                    ON ctx.instanceid = d.id AND ctx.contextlevel = 50
                JOIN {role_assignments} ra 
                    ON ra.userid = u.id AND ra.contextid = ctx.id
                JOIN {role} r 
                    ON r.id = ra.roleid
                LEFT JOIN {logstore_standard_log} l 
                    ON l.contextinstanceid = m.coursemoduleid 
                    AND l.target = 'course_module'
                    AND l.courseid = c.courseid
                    AND l.userid = u.id
                WHERE c.id = :cpmkid
                AND r.shortname = 'student'
                GROUP BY u.id, name, c.cpmk_name, d.fullname
                HAVING COUNT(l.id) = 0";
    
        return $DB->get_records_sql($sql, ['cpmkid' => $cpmkid]);
    }

    public static function get_access_time_data($cpmkid) {
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
    
        // Step 2: Fetch access counts between start_date and latest_deadline (only for students)
        $sql = "SELECT 
                    DATE(FROM_UNIXTIME(l.timecreated)) AS access_date,
                    COUNT(l.id) AS access_count
                FROM {logstore_standard_log} l
                JOIN {local_cpmk_to_modules} m ON m.coursemoduleid = l.contextinstanceid
                JOIN {context} ctx ON ctx.instanceid = l.courseid AND ctx.contextlevel = 50
                JOIN {role_assignments} ra ON ra.userid = l.userid AND ra.contextid = ctx.id
                JOIN {role} r ON r.id = ra.roleid
                WHERE m.cpmkid = :cpmkid
                  AND l.target = 'course_module'
                  AND r.shortname = 'student'
                  AND l.timecreated BETWEEN UNIX_TIMESTAMP(:start_date) AND UNIX_TIMESTAMP(:end_date)
                GROUP BY access_date
                ORDER BY access_date ASC";
    
        $access_data = $DB->get_records_sql($sql, [
            'cpmkid' => $cpmkid,
            'start_date' => $start_date . ' 00:00:00',
            'end_date' => $latest_deadline_date . ' 23:59:59',
        ]);
    
        // Step 3: Map access_data into key-value [access_date => count]
        $access_map = [];
        foreach ($access_data as $data) {
            $access_map[$data->access_date] = $data->access_count;
        }
    
        // Step 4: Fill the full date range
        $records = [];
        $labels = [];
        $counts = [];
    
        $current = strtotime($start_date);
        $end = strtotime($latest_deadline_date);
    
        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $count = isset($access_map[$date]) ? $access_map[$date] : 0;
    
            $labels[] = $date;
            $counts[] = $count;
            $current = strtotime('+1 day', $current);
        }
    
        // Step 5: Return for Mustache and Chart
        return [
            'records' => $access_data, // optional: not required for charting if only using labels/counts
            'labels' => $labels,
            'counts' => $counts,
        ];
    }

    public static function get_course_and_cpmk_name($cpmkid) {
        global $DB;
    
        $sql = "SELECT 
                    c.cpmk_name, 
                    d.fullname AS course_fullname
                FROM {local_cpmk} c
                JOIN {course} d ON d.id = c.courseid
                WHERE c.id = :cpmkid";
    
        $result = $DB->get_record_sql($sql, ['cpmkid' => $cpmkid]);
    
        if (!$result) {
            return [
                'cpmk_name' => '',
                'course_fullname' => ''
            ];
        }
    
        return [
            'cpmk_name' => $result->cpmk_name,
            'course_fullname' => $result->course_fullname
        ];
    }

    public static function get_quiz_deadline($cpmkid) {
        global $DB;
    
        // Fetch the quiz deadline for the CPMK and the quiz name
        $sql = "SELECT 
                    DATE(FROM_UNIXTIME(q.timeclose)) AS quiz_deadline,
                    q.name AS quiz_name
                FROM mdl_local_cpmk_to_quiz cq
                JOIN mdl_quiz q ON q.id = cq.quizid
                WHERE cq.cpmkid = :cpmkid
                ORDER BY q.timeclose";
    
        $quiz_deadline_data = $DB->get_records_sql($sql, ['cpmkid' => $cpmkid]);
    
        // Initialize arrays to store results
        $quiz_deadline = [];
        $quiz_name = [];
    
        foreach ($quiz_deadline_data as $data) {
            $quiz_deadline[] = $data->quiz_deadline; 
            $quiz_name[] = $data->quiz_name;
        }

        // Flatten: Only take the first quiz (you can adjust if needed)
        $deadline = [];
        for ($i = 0; $i < count($quiz_deadline); $i++) {
            $deadline[] = [
                'quiz_deadline' => $quiz_deadline[$i],
                'quiz_name' => $quiz_name[$i],
            ];
        }
        // Return neatly
        return $deadline;
    }
    
     public static function get_summatif_data($cpmkid) {
        global $DB;
    
        $sql = "SELECT 
                    u.id AS userid,
                    u.firstname AS name,
                    c.cpmk_name AS cpmk_name,
                    c.id AS cpmkid,
                    qt.coursemoduleid AS coursemoduleid,
                    IFNULL(qg.grade, 0) AS grades,
                    q.grade AS max_grades,
                    qt.weight AS weight
                FROM mdl_local_cpmk c
                JOIN mdl_local_cpmk_to_quiz qt ON qt.cpmkid = c.id
                JOIN mdl_quiz q ON q.id = qt.quizid
                JOIN mdl_course_modules cm ON cm.id = qt.coursemoduleid
                JOIN mdl_enrol e ON e.courseid = cm.course
                JOIN mdl_user_enrolments ue ON ue.enrolid = e.id
                JOIN mdl_user u ON u.id = ue.userid
                LEFT JOIN mdl_quiz_grades qg ON qg.quiz = q.id AND qg.userid = u.id
                WHERE c.id = :cpmkid1
        
                UNION ALL
        
                SELECT 
                    u.id AS userid,
                    u.firstname AS name,
                    c.cpmk_name AS cpmk_name,
                    c.id AS cpmkid,
                    at.coursemoduleid AS coursemoduleid,
                    IFNULL(ag.grade, 0) AS grades,
                    a.grade AS max_grades,
                    at.weight AS weight
                FROM mdl_local_cpmk c
                JOIN mdl_local_cpmk_to_assign at ON at.cpmkid = c.id
                JOIN mdl_assign a ON a.id = at.assignid
                JOIN mdl_course_modules cm ON cm.id = at.coursemoduleid
                JOIN mdl_enrol e ON e.courseid = cm.course
                JOIN mdl_user_enrolments ue ON ue.enrolid = e.id
                JOIN mdl_user u ON u.id = ue.userid
                LEFT JOIN mdl_assign_grades ag ON ag.assignment = a.id AND ag.userid = u.id
                WHERE c.id = :cpmkid2
            ";
    
             // ⛔ DO NOT use get_records_sql() because it uses userid as key and will overwrite.
            $raw = $DB->get_recordset_sql($sql, ['cpmkid1' => $cpmkid, 'cpmkid2' => $cpmkid]);

            // ✅ Convert the recordset to a simple indexed array
            $results = [];
            foreach ($raw as $record) {
                $results[] = $record;
            }

            $raw->close(); // Cleanup the recordset

            return $results;
        
    }

    public static function calculate_final_grades($grades_raw) {
        $result = [];
    
        foreach ($grades_raw as $row) {
            $userid = $row->userid;
            $cpmkid = $row->cpmkid;
    
            // Initialize
            if (!isset($result[$userid][$cpmkid])) {
                $result[$userid][$cpmkid] = [
                    'total_weight' => 0,
                    'weighted_score' => 0,
                    'name' => $row->name,
                    'cpmk_name' => $row->cpmk_name
                ];
            }
    
            // Nulls are 0
            $grade = $row->grades ?? 0;
            $max_grade = $row->max_grades ?? 100;
            $weight = $row->weight ?? 0;
    
            $normalized = ($max_grade > 0) ? ($grade / $max_grade) : 0;
            $score_contribution = $normalized * $weight;
    
            $result[$userid][$cpmkid]['weighted_score'] += $score_contribution;
            $result[$userid][$cpmkid]['total_weight'] += $weight;
        }
    
        // 🎯 Calculate final score
        $final_scores = [];
        foreach ($result as $userid => $cpmks) {
            foreach ($cpmks as $cpmkid => $data) {
                $total_weight = $data['total_weight'];
                $score = ($total_weight > 0) ? ($data['weighted_score'] / $total_weight) * 100 : 0;
    
                $final_scores[] = [
                    'userid' => $userid,
                    'name' => $data['name'],
                    'cpmkid' => $cpmkid,
                    'cpmk_name' => $data['cpmk_name'],
                    'final_score' => round($score, 2)
                ];
            }
        }
    
        return $final_scores;
    }

    public static function get_most_visited_with_score($cpmkid) {
        $visits = self::get_most_visited($cpmkid);
        $grades_raw = self::get_summatif_data($cpmkid);
        $final_scored = self::calculate_final_grades($grades_raw);

        // Step 1: Index final scores by userid
        $scored_indexed = [];
        foreach ($final_scored as $entry) {
            $scored_indexed[$entry['userid']] = $entry['final_score'];
        }

        // Step 2: Match and update visits
        foreach ($visits as $id => $v) {
            if (isset($scored_indexed[$v->id])) {
                $visits[$id]->final_score = $scored_indexed[$v->id];
            } else {
                $visits[$id]->final_score = 0;
            }
        }

        return $visits;
    }

    public static function get_least_visited_with_score($cpmkid) {
        $visits = self::get_least_visited($cpmkid);
        $grades_raw = self::get_summatif_data($cpmkid);
        $final_scored = self::calculate_final_grades($grades_raw);

        // Step 1: Index final scores by userid
        $scored_indexed = [];
        foreach ($final_scored as $entry) {
            $scored_indexed[$entry['userid']] = $entry['final_score'];
        }

        // Step 2: Match and update visits
        foreach ($visits as $id => $v) {
            if (isset($scored_indexed[$v->id])) {
                $visits[$id]->final_score = $scored_indexed[$v->id];
            } else {
                $visits[$id]->final_score = 0;
            }
        }

        return $visits;
    }

    public static function get_zero_visited_with_score($cpmkid) {
        $visits = self::get_students_not_accessing($cpmkid);
        $grades_raw = self::get_summatif_data($cpmkid);
        $final_scored = self::calculate_final_grades($grades_raw);

        // Step 1: Index final scores by userid
        $scored_indexed = [];
        foreach ($final_scored as $entry) {
            $scored_indexed[$entry['userid']] = $entry['final_score'];
        }

        // Step 2: Match and update visits
        foreach ($visits as $id => $v) {
            if (isset($scored_indexed[$v->id])) {
                $visits[$id]->final_score = $scored_indexed[$v->id];
            } else {
                $visits[$id]->final_score = 0;
            }
        }

        return $visits;
    }
}
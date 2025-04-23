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
                    CONCAT(u.firstname, ' ', u.lastname) AS name, 
                    c.cpmk_name, 
                    COUNT(l.id) AS visits,
                    d.fullname AS course_fullname
                FROM {local_cpmk} c
                JOIN {local_cpmk_to_modules} m 
                    ON m.cpmkid = c.id
                JOIN {course} d
                    ON d.id = c.courseid
                JOIN {logstore_standard_log} l 
                    ON l.contextinstanceid = m.coursemoduleid 
                    AND l.target = 'course_module'
                    AND l.courseid = c.courseid
                JOIN {user} u 
                    ON u.id = l.userid
                WHERE c.id = :cpmkid
                AND l.timecreated <= (
                    SELECT MAX(q.timeclose)
                    FROM {local_cpmk_to_quiz} cq
                    JOIN {quiz} q ON q.id = cq.quizid
                    WHERE cq.cpmkid = c.id
                )
                GROUP BY u.id, name, c.cpmk_name, d.fullname
            ";    
 
         return $DB->get_records_sql($sql, ['cpmkid' => $cpmkid]);
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
    
    public static function get_students_not_accessing($cpmkid) {
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
    public static function get_access_time_data($cpmkid) {
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
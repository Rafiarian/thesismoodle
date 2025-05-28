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
session_start();

$step = optional_param('step', 1, PARAM_INT);
$PAGE->set_url(new moodle_url('/local/edulog/wizard.php', ['step' => $step]));
$PAGE->set_context(context_system::instance());
if ($step > 4) {
    $PAGE->set_title("Review & Finish");
} else {
    $PAGE->set_title("Step {$step} of 4");
}

global $DB, $USER;

echo $OUTPUT->header();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 1:
            $_SESSION['wizard']['courseid'] = required_param('courseid', PARAM_INT);
            redirect(new moodle_url('/local/edulog/wizard.php', ['step' => 2]));
            break;
        case 2:
            $materialids = required_param_array('materialids', PARAM_INT);
            $_SESSION['wizard']['materialids'] = $materialids;
            redirect(new moodle_url('/local/edulog/wizard.php', ['step' => 3]));
            break;
        case 3:
            $assignmentids = required_param_array('assignmentids', PARAM_INT);
            $assignmentinstanceids = optional_param_array('assignmentinstanceid', [], PARAM_INT); // optional to avoid errors if empty
        
            $combined_assignments = [];
            foreach ($assignmentids as $index => $cmid) {
                // Ensure both cmid and instanceid are present
                if (isset($assignmentinstanceids[$index])) {
                    $combined_assignments[] = [
                        'cmid' => $cmid,
                        'instanceid' => $assignmentinstanceids[$index],
                    ];
                }
            }
        
            $_SESSION['wizard']['assignmentids'] = $combined_assignments;
            redirect(new moodle_url('/local/edulog/wizard.php', ['step' => 4]));
            break;
        case 4:
            $summary = $_SESSION['wizard'] ?? [];
            $courseid = $summary['courseid'] ?? null;
            $quizids = optional_param_array('quizids', [], PARAM_INT);
            $modinfo = get_fast_modinfo($courseid); // make sure $courseid is already set

            $combined_quizzes = [];

            foreach ($quizids as $cmid) {
                if (isset($modinfo->cms[$cmid])) {
                    $instanceid = $modinfo->cms[$cmid]->instance;
                    $combined_quizzes[] = [
                        'cmid' => $cmid,
                        'instanceid' => $instanceid,
                    ];
                }
            }

            $_SESSION['wizard']['quizids'] = $combined_quizzes; 
            redirect(new moodle_url('/local/edulog/wizard.php', ['step' => 5]));
            break;

        case 5:
            
            // Grab CPMK name
            $cpmkname = required_param('cpmkname', PARAM_TEXT);
            $_SESSION['wizard']['cpmkname'] = $cpmkname;

            // Grab nested weights arrays from $_POST manually
            $weights = $_POST['weights'] ?? []; // Read the full nested array
            $assignweights = $weights['assignment'] ?? [];
            $quizweights = $weights['quiz'] ?? [];

            // Save weights into session for later use
            $_SESSION['wizard']['assignweights'] = $assignweights;
            $_SESSION['wizard']['quizweights'] = $quizweights;

            // Final insert to DB
            $summary = $_SESSION['wizard'];
            $timecreated = time();
            $assignweights = $summary['assignweights'];
            $quizweights   = $summary['quizweights'];
            
           // Step 1: Insert main record
            $mainrecord = (object)[
                'courseid'     => $summary['courseid'],
                'cpmk_name'     => $summary['cpmkname'] ?? '',
                'userid'       => $USER->id,
                'timecreated'  => $timecreated
            ];

            $mainid = $DB->insert_record('local_cpmk', $mainrecord);  // You can use this ID as a foreign key

            // Step 2: Insert into materials table
            foreach ($summary['materialids'] as $cmid) {
                $DB->insert_record('local_cpmk_to_modules', (object)[
                    'cpmkid'        => $mainid,
                    'coursemoduleid'  => $cmid,
                    'assignid' => '0',
                    'weight' => '0',
                ]);
            }

            // Step 3: Insert assignments with weights (matched by instanceid)
            foreach ($summary['assignmentids'] as $assignment) {
                $instanceid = $assignment['instanceid'];
                $weight     = $assignweights[$instanceid] ?? 0;

                $DB->insert_record('local_cpmk_to_assign', (object)[
                    'cpmkid'         => $mainid,
                    'coursemoduleid' => $assignment['cmid'],
                    'assignid'       => $instanceid,
                    'weight'         => $weight,
                ]);
            }

            // Step 4: Insert quizzes with weights (matched by instanceid)
            foreach ($summary['quizids'] as $quiz) {
                $instanceid = $quiz['instanceid'];
                $weight     = $quizweights[$instanceid] ?? 0;

                $DB->insert_record('local_cpmk_to_quiz', (object)[
                    'cpmkid'         => $mainid,
                    'coursemoduleid' => $quiz['cmid'],
                    'quizid'         => $instanceid,
                    'weight'         => $weight,
                ]);
            }

            // Finally: cleanup & redirect
            unset($_SESSION['wizard']);
            redirect(new moodle_url('/local/edulog/index.php'), 'Message inserted!', 2);
                }
            }

//Step Display//
if ($step <= 4) {
    echo "<h2 class='mb-3'>Step {$step} of 4</h2>";
}

switch ($step) {
    case 1:
        $courses = enrol_get_users_courses($USER->id);
        $templatecontext = [
            'courses' => array_values($courses)
        ];
        echo $OUTPUT->render_from_template('local_edulog/choosecourse', $templatecontext);
        break;

    case 2:
        $courseid = $_SESSION['wizard']['courseid'] ?? null;
        if (!$courseid) {
            redirect(new moodle_url('/local/edulog/wizard.php', ['step' => 1]));
        }

        $excluded_mods = ['assign', 'forum'];
        $modinfo = get_fast_modinfo($courseid);
        $materials = [];

        foreach ($modinfo->cms as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
        if (in_array($cm->modname, $excluded_mods)) {
            continue;
        }
            $materials[] = [
                'id' => $cm->id,
                'name' => $cm->name,
                'modname' => $cm->modname
            ];
        }

        $coursename = $DB->get_field('course', 'fullname', ['id' => $courseid]);
        $itemsPerPage = 20;
        $page = optional_param('page', 1, PARAM_INT);
        $totalMaterials = count($materials);
        $totalPages = ceil($totalMaterials / $itemsPerPage);

        // Slice only the current page materials
        $pagedMaterials = array_slice($materials, ($page - 1) * $itemsPerPage, $itemsPerPage);

        $templatecontext = [
            'materials' => $pagedMaterials,
            'coursename' => $coursename,
            'sesskey' => sesskey(),
            'page' => $page,
            'hasprev' => $page > 1,
            'hasnext' => $page < $totalPages,
            'prevpage' => $page - 1,
            'nextpage' => $page + 1,
            'hasmaterials' => count($materials) > 0,
        ];
        echo $OUTPUT->render_from_template('local_edulog/choosemodule', $templatecontext);
        break;

    case 3:
        $courseid = $_SESSION['wizard']['courseid'] ?? null;
        if (!$courseid) {
            redirect(new moodle_url('/local/edulog/wizard.php', ['step' => 1]));
        }

        $allowed_mods = ['assign', 'assignment']; // Only show these types
        $modinfo = get_fast_modinfo($courseid);
        $assignmentss = [];

        foreach ($modinfo->cms as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            if (strtolower($cm->modname) !== 'assign') {
                continue;
            }
            $assignments[] = [
                    'instanceid' => $cm->instance, //get assignment id for quiz grade
                    'id' => $cm->id,  //get assignment id for showing quiz name
                    'name' => $cm->name,
                    'modname' => $cm->modname
                ];
            }

        $coursename = $DB->get_field('course', 'fullname', ['id' => $courseid]);
        $itemsPerPage = 20;
        $page = optional_param('page', 1, PARAM_INT);
        $totalAssignment = count($assignments);
        $totalPages = ceil($totalAssignment / $itemsPerPage);

        // Slice only the current page materials
        $pagedAssignment = array_slice($assignments, ($page - 1) * $itemsPerPage, $itemsPerPage);

        $templatecontext = [
            'assignment' => $pagedAssignment,
            'coursename' => $coursename,
            'sesskey' => sesskey(),
            'page' => $page,
            'hasprev' => $page > 1,
            'hasnext' => $page < $totalPages,
            'prevpage' => $page - 1,
            'nextpage' => $page + 1,
            'hasmaterials' => count($assignments) > 0,
        ];
        echo $OUTPUT->render_from_template('local_edulog/chooseassignment', $templatecontext);
        break;

    case 4:
        $courseid = $_SESSION['wizard']['courseid'] ?? null;
        if (!$courseid) {
            redirect(new moodle_url('/local/edulog/wizard.php', ['step' => 1]));
        }

        $allowed_mods = ['quiz']; // Only show these types
        $modinfo = get_fast_modinfo($courseid);
        $quizs = [];

        foreach ($modinfo->cms as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            if (strtolower($cm->modname) !== 'quiz') {
                continue;
            }
                $quizs[] = [
                    'instanceid' => $cm->instance, //get quiz id for quiz grade
                    'id' => $cm->id,  //get modules id for showing quiz name
                    'name' => $cm->name,
                    'modname' => $cm->modname
                ];
            }

        $coursename = $DB->get_field('course', 'fullname', ['id' => $courseid]);
        $itemsPerPage = 20;
        $page = optional_param('page', 1, PARAM_INT);
        $totalquizs = count($quizs);
        $totalPages = ceil($totalquizs / $itemsPerPage);

        // Slice only the current page materials
        $pagedquizs = array_slice($quizs, ($page - 1) * $itemsPerPage, $itemsPerPage);

        $templatecontext = [
            'quizs' => $pagedquizs,
            'coursename' => $coursename,
            'sesskey' => sesskey(),
            'page' => $page,
            'hasprev' => $page > 1,
            'hasnext' => $page < $totalPages,
            'prevpage' => $page - 1,
            'nextpage' => $page + 1,
            'hasmaterials' => count($quizs) > 0,
        ];
        echo $OUTPUT->render_from_template('local_edulog/choosequiz', $templatecontext);
        break;

    case 5:
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty(optional_param('cpmkname', '', PARAM_TEXT))) {
            $_SESSION['wizard']['cpmkname'] = required_param('cpmkname', PARAM_TEXT);
        }    
        
        $summary = $_SESSION['wizard'];
        $coursename = $DB->get_field('course', 'fullname', ['id' => $summary['courseid']]);
        $modinfo = get_fast_modinfo($summary['courseid']);
    
        $get_modules = function ($items, $default_weight = null) use ($modinfo) {
            $modules = [];
        
            foreach ((array)$items as $item) {
                $cmid = $item['cmid'] ?? null;
                
        
                if ($cmid && isset($modinfo->cms[$cmid])) {
                    $cm = $modinfo->cms[$cmid];
                    $modules[] = [
                        'id' => $cmid,
                        'instanceid' => $cm->instance,
                        'name' => $modinfo->cms[$cmid]->name,
                        'default_weight' => $default_weight,
                    ];
                }
            }
        
            return $modules;
        };

        $get_names = function ($ids) use ($modinfo) {
            $names = [];
            foreach ((array)$ids as $id) {
                if (isset($modinfo->cms[$id])) {
                    $names[] = $modinfo->cms[$id]->name;
                }
            }
            return $names;
        };
    
        $templatecontext = [
            'coursename' => $coursename,
            'messagetext' => $summary['messagetext'],
            'sesskey' => sesskey()
        ];
    
        $total_items = 0;
        if (!empty($summary['assignmentids'])) {
            $total_items += count($summary['assignmentids']);
        }
        if (!empty($summary['quizids'])) {
            $total_items += count($summary['quizids']);
        }


        // Distribute 100% evenly
        $even_weight = $total_items > 0 ? floor(100 / $total_items) : 0;
        $leftover = 100 - ($even_weight * $total_items); // to distribute leftover later if needed

        if (!empty($summary['materialids'])) {
            $templatecontext['materials'] = ['items' => $get_names($summary['materialids'])];
        }

        if (!empty($summary['assignmentids'])) {
            $templatecontext['assignments'] = ['items' => $get_modules($summary['assignmentids'], $even_weight)];
        }

        if (!empty($summary['quizids'])) {
            $templatecontext['quizzes'] = ['items' => $get_modules($summary['quizids'], $even_weight)];
        };
    
        echo $OUTPUT->render_from_template('local_edulog/confirm', $templatecontext);
        break;

    default:
        redirect(new moodle_url('/local/edulog/index.php'));
        break;
}

echo $OUTPUT->footer();
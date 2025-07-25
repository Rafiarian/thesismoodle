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
        case 1: // Handle course selection
            $_SESSION['wizard']['courseid'] = required_param('courseid', PARAM_INT);
            redirect(new moodle_url('/local/edulog/wizard.php', ['step' => 2]));
            break;
        case 2: // Handle materials
            $materialids = required_param_array('materialids', PARAM_INT);
            $_SESSION['wizard']['materialids'] = $materialids;
            redirect(new moodle_url('/local/edulog/wizard.php', ['step' => 3]));
            break;
        case 3: // Handle assignments
            $assignmentdata = required_param_array('assignmentdata', PARAM_RAW); 
            $combined_assignments = [];

            foreach ($assignmentdata as $data) {
                [$cmid, $instanceid] = explode(':', $data);
                $combined_assignments[] = [
                    'cmid' => (int)$cmid,
                    'instanceid' => (int)$instanceid,
                ];
            }

            $_SESSION['wizard']['assignmentids'] = $combined_assignments;
            redirect(new moodle_url('/local/edulog/wizard.php', ['step' => 4]));
            break;

        case 4: // Handle quizzes
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

        case 5: // Handle final confirmation and save
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
            render_choose_course();
            break;
        case 2:
            render_choose_modules();
            break;
        case 3:
            render_choose_assignment();
            break;
        case 4:
            render_choose_quiz();
            break;
        case 5:
            render_confirmation();
            break;
        default:
            redirect(new moodle_url('/local/edulog/index.php'));
            break;
    }


function render_choose_course() {
    global $USER, $OUTPUT;
    $courses = enrol_get_users_courses($USER->id);
    $templatecontext = ['courses' => array_values($courses)];
    echo $OUTPUT->render_from_template('local_edulog/choosecourse', $templatecontext);
}

function render_choose_modules() {
    global $DB, $OUTPUT;
    $courseid = $_SESSION['wizard']['courseid'] ?? null;
    if (!$courseid) {
        redirect(new moodle_url('/local/edulog/wizard.php', ['step' => 1]));
    }

    $excluded_mods = ['assign', 'forum'];
    $modinfo = get_fast_modinfo($courseid);
    $materials = [];

    foreach ($modinfo->cms as $cm) {
        if (!$cm->uservisible || in_array($cm->modname, $excluded_mods)) {
            continue;
        }
        $materials[] = [
            'id' => $cm->id,
            'name' => $cm->name,
            'modname' => $cm->modname
        ];
    }

    $coursename = $DB->get_field('course', 'fullname', ['id' => $courseid]);
    $itemsPerPage = 300;
    $page = optional_param('page', 1, PARAM_INT);
    $totalMaterials = count($materials);
    $totalPages = ceil($totalMaterials / $itemsPerPage);

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
}

function render_choose_assignment() {
    global $DB, $USER, $OUTPUT;
    $courseid = $_SESSION['wizard']['courseid'] ?? null;
    if (!$courseid) {
        redirect(new moodle_url('/local/edulog/wizard.php', ['step' => 1]));
    }

    $modinfo = get_fast_modinfo($courseid);
    $assignments = [];

    foreach ($modinfo->cms as $cm) {
        if (!$cm->uservisible || strtolower($cm->modname) !== 'assign') {
            continue;
        }
        $assignments[] = [
            'instanceid' => $cm->instance,
            'id' => $cm->id,
            'name' => $cm->name,
            'modname' => $cm->modname
        ];
    }

    $coursename = $DB->get_field('course', 'fullname', ['id' => $courseid]);
    $itemsPerPage = 300;
    $page = optional_param('page', 1, PARAM_INT);
    $totalAssignment = count($assignments);
    $totalPages = ceil($totalAssignment / $itemsPerPage);
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
}

function render_choose_quiz() {
    global $DB, $OUTPUT;
    $courseid = $_SESSION['wizard']['courseid'] ?? null;
    if (!$courseid) {
        redirect(new moodle_url('/local/edulog/wizard.php', ['step' => 1]));
    }

    $modinfo = get_fast_modinfo($courseid);
    $quizzes = [];

    foreach ($modinfo->cms as $cm) {
        if (!$cm->uservisible || strtolower($cm->modname) !== 'quiz') {
            continue;
        }
        $quizzes[] = [
            'instanceid' => $cm->instance,
            'id' => $cm->id,
            'name' => $cm->name,
            'modname' => $cm->modname
        ];
    }

    $coursename = $DB->get_field('course', 'fullname', ['id' => $courseid]);
    $itemsPerPage = 300;
    $page = optional_param('page', 1, PARAM_INT);
    $totalQuizzes = count($quizzes);
    $totalPages = ceil($totalQuizzes / $itemsPerPage);
    $pagedQuizzes = array_slice($quizzes, ($page - 1) * $itemsPerPage, $itemsPerPage);

    $templatecontext = [
        'quizs' => $pagedQuizzes,
        'coursename' => $coursename,
        'sesskey' => sesskey(),
        'page' => $page,
        'hasprev' => $page > 1,
        'hasnext' => $page < $totalPages,
        'prevpage' => $page - 1,
        'nextpage' => $page + 1,
        'hasmaterials' => count($quizzes) > 0,
    ];
    echo $OUTPUT->render_from_template('local_edulog/choosequiz', $templatecontext);
}

function render_confirmation() {
    global $DB, $OUTPUT;
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

    $even_weight = $total_items > 0 ? floor(100 / $total_items) : 0;

    if (!empty($summary['materialids'])) {
        $templatecontext['materials'] = ['items' => $get_names($summary['materialids'])];
    }

    if (!empty($summary['assignmentids'])) {
        $templatecontext['assignments'] = ['items' => $get_modules($summary['assignmentids'], $even_weight)];
    }

    if (!empty($summary['quizids'])) {
        $templatecontext['quizzes'] = ['items' => $get_modules($summary['quizids'], $even_weight)];
    }

    echo $OUTPUT->render_from_template('local_edulog/confirm', $templatecontext);
}


echo $OUTPUT->footer();
<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Main plugin logic.
 *
 * @package     report_course_analysis
 * @copyright   2024 Vaiva Šarauskytė <vaiva.sarauskyte@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/report/course_analysis/lib.php');
require_once($CFG->libdir . '/tablelib.php');
require_once($CFG->libdir . '/completionlib.php');

use core\report_helper;

// Getting current course.
$id = required_param('course_id', PARAM_INT);
try {
    $course = get_course($id);
} catch (dml_missing_record_exception $e) {
    // This will show a less technical error message to the user.
    throw new moodle_exception('invalidcourseid');
}

// Checking if the user has access to the plugin.
require_login($course);
$context = context_course::instance($course->id);
require_capability('report/course_analysis:view', $context);

// Setting up the page.
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/report/course_analysis/index.php', ['course_id' => $id]));

// Layout used for reports within Moodle.
$PAGE->set_pagelayout('report');

// Navigation and the page header.
$PAGE->set_title(format_string($course->fullname) . ': ' . get_string('pluginname', 'report_course_analysis'));
$PAGE->set_heading(format_string($course->fullname));
echo $OUTPUT->header();

$completion = new completion_info($course);
if (!$completion->is_enabled()) {
    echo html_writer::start_div('alert alert-warning', ['role' => 'alert']);
    echo html_writer::tag('i', '', ['class' => 'icon fa fa-info-circle fa-fw']);
    echo html_writer::span(get_string('completion-not-enabled', 'report_course_analysis'));
    echo html_writer::end_div();
} else {
    // Dropdown with all reports.
    $pluginname = get_string('pluginname', 'report_course_analysis');
    report_helper::print_report_selector($pluginname);

    echo html_writer::start_div('content-hide-on-mobile');

    // Defining the tabs.
    $tabs = [
        new tabobject('completiontable', new moodle_url('/report/course_analysis/index.php', [
            'course_id' => $id,
            'tab' => 'completiontable',
        ]), get_string('completion-table', 'report_course_analysis')),
        new tabobject('completiongraph', new moodle_url('/report/course_analysis/index.php', [
            'course_id' => $id,
            'tab' => 'completiongraph',
        ]), get_string('completion-graph', 'report_course_analysis')),
        new tabobject('views', new moodle_url('/report/course_analysis/index.php', [
            'course_id' => $id,
            'tab' => 'views',
        ]), get_string('views', 'report_course_analysis')),
        new tabobject('posts', new moodle_url('/report/course_analysis/index.php', [
            'course_id' => $id,
            'tab' => 'posts',
        ]), get_string('posts', 'report_course_analysis')),
    ];

    $currenttab = optional_param('tab', 'completiontable', PARAM_ALPHA);
    print_tabs([$tabs], $currenttab);

    // Adding content based on the selected tab.
    switch ($currenttab) {
        case 'completiontable':
            echo html_writer::tag('h3', get_string('completion-table', 'report_course_analysis'), ['class' => 'tab-title']);

            $studentrole = $DB->get_record('role', ['shortname' => 'student']);
            $studentscount = count_role_users($studentrole->id, $context);

            $description = get_string('completion-table-description', 'report_course_analysis');
            $studentcountmessage = get_string('course-student-count', 'report_course_analysis', $studentscount);

            $content = html_writer::div($description, 'tab-description') .
                html_writer::div($studentcountmessage, 'course-students');

            echo html_writer::div($content, 'generalbox custom-box');

            if ($studentscount == 0) {
                echo html_writer::start_div('alert alert-warning', ['role' => 'alert']);
                echo html_writer::tag('i', '', ['class' => 'icon fa fa-info-circle fa-fw']);
                echo html_writer::span(get_string('no-students', 'report_course_analysis'));
                echo html_writer::end_div();
            } else {

                // Query to find all unique activity types in the course that have completion enabled.
                $sql = "SELECT DISTINCT m.name AS modname
                    FROM {course_modules} cm
                    JOIN {modules} m ON cm.module = m.id
                    WHERE cm.course = :courseid AND cm.completion != 0";
                $activities = $DB->get_records_sql($sql, ['courseid' => $id]);
                // Course sections.
                $sections = $DB->get_records('course_sections', ['course' => $id], 'section', 'id, name, section, visible');

                // Set up the table.
                $table = new html_table();
                $table->attributes['class'] = 'generaltable boxaligncenter';

                // Table header with activity types.
                $table->head = [get_string('course-section-module-type', 'report_course_analysis')];
                foreach ($activities as $activity) {
                    // Get the formal, localized name of the module.
                    $moduleformalname = get_string('pluginname', 'mod_' . $activity->modname);
                    $table->head[] = $moduleformalname;
                }

                $studentroleid = $DB->get_record('role', ['shortname' => 'student'])->id;
                $contextlevel = CONTEXT_COURSE;

                foreach ($sections as $section) {
                    $sectionname = get_section_name($course, $section);
                    if ($section->visible) {
                        $row = new html_table_row();
                        $row->cells[] = new html_table_cell(html_writer::span($sectionname, 'sectionname'));

                        foreach ($activities as $activity) {
                            $activitycell = new html_table_cell();
                            $activitycell->attributes['class'] = 'completiondata';

                            $sql = "SELECT act.name, COALESCE(SUM(
                                (cmc.completionstate = :complete OR
                                cmc.completionstate = :completepass)
                                AND (ra.roleid = :studentrole OR ra.roleid IS NULL)
                                ), 0) as completioncount
                                FROM {" . $activity->modname . "} act
                                JOIN {course_modules} cm ON act.id = cm.instance
                                JOIN {modules} m ON cm.module = m.id
                                LEFT JOIN {course_modules_completion} cmc ON cm.id = cmc.coursemoduleid
                                LEFT JOIN {context} ctx ON ctx.instanceid = cm.course AND ctx.contextlevel = :courselevel
                                LEFT JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = cmc.userid
                                WHERE cm.course = :courseid
                                    AND cm.completion != 0
                                    AND cm.section = :sectionid
                                    AND m.name = :modname
                                GROUP BY act.name";
                            $params = [
                                'complete' => COMPLETION_COMPLETE,
                                'completepass' => COMPLETION_COMPLETE_PASS,
                                'studentrole' => $studentroleid,
                                'courselevel' => CONTEXT_COURSE,
                                'courseid' => $id,
                                'sectionid' => $section->id,
                                'modname' => $activity->modname,
                            ];
                            $activityinstances = $DB->get_records_sql($sql, $params);

                            $activityinfo = [];
                            foreach ($activityinstances as $instance) {
                                $activityname = format_string($instance->name);
                                $completioncount = $instance->completioncount;
                                $percentage = round(($completioncount / $studentscount) * 100);
                                $activityinfo[] = "{$activityname}: {$completioncount} <span class='hover-info'>({$percentage}%)</span>";
                            }
                            $activitycell->text = join('<br>', $activityinfo);
                            $row->cells[] = $activitycell;
                        }
                        $table->data[] = $row;
                    }
                }
                echo html_writer::table($table);
            }
            break;

        case 'completiongraph':
            echo html_writer::tag('h3', get_string('completion-graph', 'report_course_analysis'), ['class' => 'tab-title']);

            $studentrole = $DB->get_record('role', ['shortname' => 'student']);
            $studentscount = count_role_users($studentrole->id, $context);

            $description = get_string('completion-graph-description', 'report_course_analysis');
            $studentcountmessage = get_string('course-student-count', 'report_course_analysis', $studentscount);

            $content = html_writer::div($description, 'tab-description') .
                html_writer::div($studentcountmessage, 'course-students');

            echo html_writer::div($content, 'generalbox custom-box');

            // Initialize chart data arrays.
            $chart = new \core\chart_bar();
            $sectionnames = [];
            $activitytypesdata = [];

            // Get the course sections.
            $sections = $DB->get_records('course_sections', ['course' => $id], 'section', 'id, name, section, visible');
            foreach ($sections as $section) {
                $sectionname = format_string($section->name ? $section->name : get_section_name($course, $section));
                $sectionnames[] = $sectionname;
            }

            // Activity types with completion enabled in the course.
            $activitytypes = $DB->get_records_sql("
                            SELECT DISTINCT m.name
                            FROM {course_modules} cm
                            JOIN {modules} m ON cm.module = m.id
                            WHERE cm.course = :courseid AND cm.completion != 0
                        ", ['courseid' => $id]);

            foreach ($activitytypes as $activitytype) {
                $activitytypename = $activitytype->name;
                $activitytypesdata[$activitytypename] = array_fill_keys($sectionnames, 0);
            }

            foreach (array_keys($activitytypesdata) as $type) {
                $sql = "SELECT cs.id, cs.name, COUNT(cmc.id) AS completioncount
                        FROM {course_modules} cm
                        JOIN {modules} m ON cm.module = m.id
                        JOIN {{$type}} act ON cm.instance = act.id
                        JOIN {course_sections} cs ON cm.section = cs.id
                        LEFT JOIN {course_modules_completion} cmc ON cm.id = cmc.coursemoduleid
                        LEFT JOIN {context} ctx ON ctx.instanceid = cm.course AND ctx.contextlevel = 50
                        LEFT JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = cmc.userid
                        WHERE cm.course = :courseid
                        AND m.name = :modname
                        AND cmc.completionstate IN (1, 2)
                        AND ra.roleid = (SELECT id FROM {role} WHERE shortname = 'student')
                        GROUP BY cs.id, cs.name
                        ORDER BY cs.section ASC";

                $params = [
                    'courseid' => $id,
                    'modname' => $type,
                ];

                $completiondata = $DB->get_records_sql($sql, $params);
                foreach ($completiondata as $datapoint) {
                    $sectionname = format_string($datapoint->name ? $datapoint->name : get_section_name($course, $datapoint->id));
                    $activitytypesdata[$type][$sectionname] = (int) $datapoint->completioncount;
                }
            }

            // Add series to the chart for each activity type.
            foreach ($activitytypesdata as $type => $data) {
                $series = new \core\chart_series(get_string('pluginname', 'mod_' . $type), array_values($data));
                $chart->add_series($series);
            }

            // Set up the X-axis labels (course sections).
            $chart->set_labels($sectionnames);
            $chart->set_title('Course activity completion bar chart');

            echo $OUTPUT->render_chart($chart, true);
            break;

        case 'views':
            echo html_writer::tag('h3', get_string('views', 'report_course_analysis'), ['class' => 'tab-title']);

            $studentrole = $DB->get_record('role', ['shortname' => 'student']);
            $studentscount = count_role_users($studentrole->id, $context);

            $description = get_string('views-description', 'report_course_analysis');
            $studentcountmessage = get_string('course-student-count', 'report_course_analysis', $studentscount);

            $content = html_writer::div($description, 'tab-description') .
                html_writer::div($studentcountmessage, 'course-students');

            echo html_writer::div($content, 'generalbox custom-box');

            // SQL query to fetch all distinct module names used in the course.
            $sqlmoduletypes = "SELECT DISTINCT m.name
                                       FROM {course_modules} cm
                                       JOIN {modules} m ON cm.module = m.id
                                       WHERE cm.course = :courseid";
            $paramsmoduletypes = ['courseid' => $id];
            $moduletypes = $DB->get_records_sql($sqlmoduletypes, $paramsmoduletypes);

            foreach ($moduletypes as $moduletype) {
                // Skip generating the table for "Text and media areas views".
                if ($moduletype->name === 'label') {
                    continue;
                }
                $allviewcounts = [];

                $moduletypeheading = get_string('modulenameplural', $moduletype->name);
                echo html_writer::tag('h4', $moduletypeheading . ' views', ['class' => 'table-name']);
                echo html_writer::tag('p',
                    get_string('views-table-description', 'report_course_analysis', strtolower($moduletypeheading)),
                    ['class' => 'module-description']);

                // Fetch all instances of this module type.
                $sqlmoduleinstances = "SELECT cm.id, cm.instance, m_instance.name AS modulename, COALESCE(cs.name, CONCAT('Topic ', cs.section)) AS sectionname
                                        FROM {course_modules} cm
                                        JOIN {modules} m ON cm.module = m.id
                                        JOIN {course_sections} cs ON cm.section = cs.id
                                        LEFT JOIN {" . $moduletype->name . "} m_instance ON cm.instance = m_instance.id
                                        WHERE cm.course = :courseid AND m.name = :modulename";
                $paramsmoduleinstances = [
                    'courseid' => $id,
                    'modulename' => $moduletype->name,
                ];
                $moduleinstances = $DB->get_records_sql($sqlmoduleinstances, $paramsmoduleinstances);

                foreach ($moduleinstances as $instance) {
                    $sqlviewcount = "SELECT u.id, COUNT(*) AS viewcount
                                        FROM {logstore_standard_log} l
                                        JOIN {user} u ON u.id = l.userid
                                        JOIN {role_assignments} ra ON ra.userid = u.id
                                        JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                                        WHERE l.courseid = :courseid
                                        AND l.contextinstanceid = :cmid
                                        AND l.action = 'viewed'
                                        AND l.component = :component
                                        AND ra.roleid = :roleid
                                        AND ctx.instanceid = :courseid2
                                        GROUP BY u.id";
                    $paramsviewcount = [
                        'courseid' => $id,
                        'courseid2' => $id,
                        'cmid' => $instance->id,
                        'component' => 'mod_' . $moduletype->name,
                        'roleid' => $studentrole->id,
                    ];

                    $viewcounts = $DB->get_records_sql($sqlviewcount, $paramsviewcount);
                    foreach ($viewcounts as $viewcount) {
                        $count = (int)$viewcount->viewcount;
                        if (!isset($allviewcounts[$count])) {
                            $allviewcounts[$count] = 0;
                        }
                        $allviewcounts[$count]++;
                    }
                }

                // Set up the table with headers based on the max view count for this module type.
                $intervalheaders = get_dynamic_interval_bounds($allviewcounts);
                $table = new html_table();
                $table->attributes['class'] = 'generaltable mod_index_table';
                $table->head = [
                    get_string('module-name', 'report_course_analysis'),
                    get_string('course-section', 'report_course_analysis'),
                    get_string('total-views', 'report_course_analysis'),
                ];
                foreach ($intervalheaders as $header) {
                    $table->head[] = $header;
                }

                foreach ($moduleinstances as $instance) {
                    // Fetch views for this specific instance.
                    $sqlviewcount = "SELECT u.id, COUNT(*) AS viewcount
                                         FROM {logstore_standard_log} l
                                         JOIN {user} u ON u.id = l.userid
                                         JOIN {role_assignments} ra ON ra.userid = u.id
                                         JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                                         WHERE l.courseid = :courseid
                                           AND l.contextinstanceid = :cmid
                                           AND l.action = 'viewed'
                                           AND l.component = :component
                                           AND ra.roleid = :roleid
                                           AND ctx.instanceid = :courseid2
                                         GROUP BY u.id";
                    $paramsviewcount = [
                        'courseid' => $id,
                        'courseid2' => $id,
                        'cmid' => $instance->id,
                        'component' => 'mod_' . $moduletype->name,
                        'roleid' => $studentrole->id,
                    ];
                    $viewcounts = $DB->get_records_sql($sqlviewcount, $paramsviewcount);

                    $individualintervalcounts = get_interval_counts($viewcounts, $intervalheaders, $studentscount);
                    $totalviews = array_sum(array_map(function ($item) {
                        return $item->viewcount;
                    }, $viewcounts));

                    $rowcells = [
                        new html_table_cell($instance->modulename),
                        new html_table_cell($instance->sectionname),
                        new html_table_cell($totalviews),
                    ];

                    foreach ($intervalheaders as $interval) {
                        $rowcells[] = new html_table_cell($individualintervalcounts[$interval] ?? 0);
                    }

                    $table->data[] = new html_table_row($rowcells);
                }
                // Output the table.
                echo html_writer::table($table);
            }
            break;

        case 'posts':
            echo html_writer::tag('h3', get_string('posts', 'report_course_analysis'), ['class' => 'tab-title']);

            $studentrole = $DB->get_record('role', ['shortname' => 'student']);
            $studentscount = count_role_users($studentrole->id, $context);

            $description = get_string('posts-description', 'report_course_analysis');
            $studentcountmessage = get_string('course-student-count', 'report_course_analysis', $studentscount);

            $content = html_writer::div($description, 'tab-description') .
                html_writer::div($studentcountmessage, 'course-students');

            echo html_writer::div($content, 'generalbox custom-box');

            // Fetch the student role ID.
            $studentroleid = $studentrole->id;
            $coursecontextlevel = CONTEXT_COURSE;

            $sqlmoduleswithposts = "SELECT DISTINCT m.name AS module_name
                                                FROM {logstore_standard_log} l
                                                JOIN {course_modules} cm ON l.contextinstanceid = cm.id
                                                JOIN {modules} m ON cm.module = m.id
                                                JOIN {context} ctx ON ctx.instanceid = cm.course AND ctx.contextlevel = :contextlevel
                                                JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.roleid = :studentrole
                                                WHERE l.courseid = :courseid
                                                  AND ra.roleid = :studentrole2
                                                  AND l.action IN ('created', 'submitted', 'updated', 'uploaded')
                                                GROUP BY m.name
                                                HAVING COUNT(*) > 0";
            $paramsmodules = [
                'courseid' => $id,
                'studentrole' => $studentroleid,
                'studentrole2' => $studentroleid,
                'contextlevel' => $coursecontextlevel,
            ];
            $moduleswithposts = $DB->get_records_sql($sqlmoduleswithposts, $paramsmodules);

            // Fetch posts data for each module type.
            foreach ($moduleswithposts as $module) {
                if ($module->module_name === 'label') {
                    continue;
                }
                $moduletypeheading = get_string('modulenameplural', $module->module_name);
                echo html_writer::tag('h4', $moduletypeheading . ' posts', ['class' => 'table-name']);
                echo html_writer::tag('p',
                    get_string('posts-table-description', 'report_course_analysis', strtolower($moduletypeheading)),
                    ['class' => 'module-description']);

                $sqlpostsdetails = "SELECT mi.name AS modulename, COALESCE(cs.name, CONCAT('Topic ', cs.section)) AS sectionname, COUNT(*) AS post_count
                        FROM {logstore_standard_log} l
                        JOIN {course_modules} cm ON cm.id = l.contextinstanceid
                        JOIN {modules} m ON m.id = cm.module
                        JOIN {" . $module->module_name . "} mi ON mi.id = cm.instance
                        LEFT JOIN {course_sections} cs ON cm.section = cs.id
                        JOIN {context} ctx ON ctx.instanceid = cm.course
                        JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = l.userid
                        WHERE l.courseid = :courseid
                          AND ra.roleid = :studentrole
                          AND l.action IN ('created', 'submitted', 'updated', 'uploaded')
                          AND m.name = :modulename
                        GROUP BY mi.name, cs.name";
                $paramspostsdetails = [
                    'courseid' => $id,
                    'modulename' => $module->module_name,
                    'studentrole' => $studentroleid,
                ];
                $postsdetails = $DB->get_records_sql($sqlpostsdetails, $paramspostsdetails);

                if (!empty($postsdetails)) {
                    // Prepare a table for each module type.
                    $table = new html_table();
                    $table->attributes['class'] = 'generaltable mod_index_table';
                    $table->head = [
                        get_string('module-name', 'report_course_analysis'),
                        get_string('course-section', 'report_course_analysis'),
                        get_string('total-posts', 'report_course_analysis'),
                    ];

                    foreach ($postsdetails as $modulename => $details) {
                        $row = new html_table_row();
                        $row->cells[] = new html_table_cell($details->modulename);
                        $row->cells[] = new html_table_cell($details->sectionname);
                        $row->cells[] = new html_table_cell($details->post_count);
                        $table->data[] = $row;
                    }
                    echo html_writer::table($table);
                } else {
                    echo html_writer::tag('p',
                    get_string('no-posts', 'report_course_analysis', $module->module_name),
                    ['class' => 'alert alert-info']);
                }
            }
            break;
    }
    echo html_writer::end_div();

    // Responsive message for screens smaller than a typical tablet.
    echo html_writer::start_div('message-show-on-mobile alert alert-info');
    echo html_writer::tag('i', '', ['class' => 'icon fa fa-info-circle fa-fw']); // Using FontAwesome icon.
    echo html_writer::span(get_string('message-small-screen', 'report_course_analysis'));
    echo html_writer::end_div();
}

// Output page footer.
echo $OUTPUT->footer();

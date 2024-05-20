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
 * Helping functions are defined here.
 *
 * @package     report_course_analysis
 * @copyright   2024 Vaiva Šarauskytė <vaiva.sarauskyte@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Extends the course navigation to include the report link.
 *
 * @param navigation_node $navigation The navigation node to extend.
 * @param stdClass $course The course object.
 * @param context_course $context The context of the course.
 */
function report_course_analysis_extend_navigation_course($navigation, $course, $context) {
    global $CFG;

    require_once($CFG->libdir . '/completionlib.php');

    $showonnavigation = has_capability('report/course_analysis:view', $context);

    $completion = new completion_info($course);
    $showonnavigation = ($showonnavigation && $completion->is_enabled() && $completion->has_activities());
    if ($showonnavigation) {
        $url = new moodle_url('/report/course_analysis/index.php', ['course_id' => $course->id]);
        $navigation->add(
            get_string('pluginname', 'report_course_analysis'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            null,
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Calculates the interval counts for the views.
 *
 * @param array $viewcounts An array of view counts.
 * @param array $intervals An array of intervals.
 * @param int $totalstudents The total number of students.
 * @return array The calculated interval counts.
 */
function get_interval_counts($viewcounts, $intervals, $totalstudents) {
    $intervalcounts = array_fill_keys($intervals, 0);
    $intervalcounts['0 views'] = $totalstudents; // Assume initially all students are in "0 views".

    foreach ($viewcounts as $view) {
        $count = (int)$view->viewcount;
        if ($count == 0) {
            continue; // Skip if count is zero since it's already included.
        }

        $found = false;
        foreach ($intervals as $interval) {
            if ($interval === '0 views') {
                continue; // Skip the "0 views" label during this loop.
            }
            if (strpos($interval, '-') !== false) {
                // Interval with a range.
                list($lower, $upper) = explode('-', str_replace(' views', '', $interval));
                if ($count >= $lower && $count <= $upper) {
                    $intervalcounts[$interval]++;
                    $intervalcounts['0 views']--; // Decrement "0 views" because this view is counted.
                    $found = true;
                    break;
                }
            } else {
                // Single value interval, e.g., "1 view" or "2 views".
                $singlecount = intval(preg_replace('/ views?/', '', $interval));
                if ($count == $singlecount) {
                    $intervalcounts[$interval]++;
                    $intervalcounts['0 views']--; // Decrement "0 views" because this view is counted.
                    $found = true;
                    break;
                }
            }
        }
        if (!$found && $count > 0) {
            // If no interval was found (which theoretically should never happen).
            $intervalcounts['0 views']--; // Ensure to decrement "0 views" for any counted view.
        }
    }

    return $intervalcounts;
}

/**
 * Generates dynamic interval bounds based on the view counts.
 *
 * @param array $viewcounts An array of view counts.
 * @return array The generated interval bounds.
 */
function get_dynamic_interval_bounds($viewcounts) {
    $values = array_keys($viewcounts);
    $intervals = ['0 views']; // Always include "0 views" as the first interval.
    if (empty($values) || max($values) == 0) {
        return $intervals; // Only return "0 views" if no views or max is zero.
    }

    $maxcount = max($values);
    $mincount = min(array_filter($values)); // Filter out zeros for minimum count calculation.
    $numberofintervals = max(1, min(10, floor(sqrt($maxcount - $mincount + 1))));

    if ($numberofintervals == 1 || $maxcount - $mincount < $numberofintervals) {
        if ($mincount == $maxcount) {
            $intervals[] = $mincount == 1 ? "1 view" : "$mincount views";
        } else {
            $intervals[] = "$mincount-$maxcount views";
        }
    } else {
        $intervalsize = ceil(($maxcount - $mincount + 1) / $numberofintervals);
        for ($i = 0; $i < $numberofintervals; $i++) {
            $lower = $mincount + $i * $intervalsize;
            $upper = min($maxcount, $lower + $intervalsize - 1);
            if ($lower == $upper) {
                $intervals[] = $lower == 1 ? "$lower view" : "$lower views";
            } else {
                $intervals[] = "$lower-$upper views";
            }
        }
    }
    return $intervals;
}

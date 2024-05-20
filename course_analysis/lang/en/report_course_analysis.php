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
 * Plugin strings are defined here.
 *
 * @package     report_course_analysis
 * @category    string
 * @copyright   2024 Vaiva Šarauskytė <vaiva.sarauskyte@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Course learners behaviour report';
$string['completion-not-enabled'] = 'Activity completion is not enabled for this course';
$string['message-small-screen'] = 'Plugin is only available at larger screen sizes';

$string['completion-table'] = 'Activity completion table';
$string['completion-graph'] = 'Activity completion chart';
$string['views'] = 'Modules views';
$string['posts'] = 'Modules posts';
$string['course-student-count'] = 'This course has <strong>{$a}</strong> students.';

// TAB 1.
$string['completion-table-description'] = 'A table showing students completion rates of activities and resources with activity completion turned on for each of the course sections.';
$string['course-section-module-type'] = 'Course Section / Module Type';
$string['no-students'] = 'There are no students enrolled in this course.';

// TAB 2.
$string['completion-graph-description'] = 'A bar chart illustrating activity completion in the course across different activity types.';

// TAB 3.
$string['views-description'] = 'Tables showing how many students viewed these activities and resources a certain number of times.';
$string['views-table-description'] = 'Amounts of students who viewed these {$a} a certain amount of times.';
$string['module-name'] = 'Module name';
$string['course-section'] = 'Course section';
$string['total-views'] = 'Total number of views';

// TAB 4.
$string['posts-description'] = 'Tables showing students post action (created, submitted, updated, uploaded) total counts in these modules.';
$string['posts-table-description'] = 'Amounts of students who have posted in these {$a}.';
$string['total-posts'] = 'Total number of posts';
$string['no-posts'] = 'No posts have been recorded for {$a}.';

<?php

// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This filter finds hyperlinks that wrap an image whose url is one of the activites in the course
 * when it matches, it adds a span/icon inside the hyperlink with the completion state of the activity
 * the span/icon can be styled via the theme's custom css
 *
 * @package    filter
 * @subpackage activitycompletionimage
 * @copyright  2018 tim.stclair@gmail.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once("{$CFG->libdir}/completionlib.php");
/**
 * Activity name filtering
 */
class filter_activitycompletionimage extends moodle_text_filter {
    // Trivial-cache - keyed on $cachedcourseid and $cacheduserid.
    static $activitylist = null;
    static $cachedcourseid;
    static $cacheduserid;

    function filter($text, array $options = array()) {
        global $USER, $COURSE; // Since 2.7 we can finally start using globals in filters.

        $coursectx = $this->context->get_course_context(false);
        if (!$coursectx) {
            return $text;
        }
        $courseid = $coursectx->instanceid;

        // Initialise/invalidate our trivial cache if dealing with a different course.
        if (!isset(self::$cachedcourseid) || self::$cachedcourseid !== (int)$courseid) {
            self::$activitylist = null;
        }
        self::$cachedcourseid = (int)$courseid;
        // And the same for user id.
        if (!isset(self::$cacheduserid) || self::$cacheduserid !== (int)$USER->id) {
            self::$activitylist = null;
        }
        self::$cacheduserid = (int)$USER->id;

        /// It may be cached

        if (is_null(self::$activitylist)) {
            self::$activitylist = array();
            $modinfo = get_fast_modinfo($courseid);
            $completioninfo = new completion_info($COURSE);
            if (!empty($modinfo->cms)) {
                foreach ($modinfo->cms as $cm) {
                    if ($cm->visible and $cm->has_view()) { //  and $cm->uservisible) {
                        $actinfo = $completioninfo->get_data($cm, false, self::$cacheduserid);
                        self::$activitylist[$cm->url->out()] = (object)array(
                            'name' => $cm->name,
                            'id' => $cm->id,
                            'uservisible' => $cm->uservisible,
                            'completed' => $actinfo->completionstate,
                        );
                    }
                }
            }
            $foundLinks = array_keys(self::$activitylist);
            $dom = new DOMDocument();
            $dom->loadHTML($text, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $hrefs = $dom->getElementsByTagName("a");
            foreach ($hrefs as $href) {
                if (!array_key_exists($href->getAttribute("href"), self::$activitylist)) continue;
                $info = self::$activitylist[$href->getAttribute("href")]; // find this <a href> in the activity list
                if (isset($info) && $href->hasChildNodes()) { // if this hyperlink is found in the activity list and its got child nodes
                    $img = $href->getElementsByTagName("img");
                    if ($img->length === 1) { // and those child nodes are an image
                        $img[0]->removeAttribute("style"); //
                        $i = $dom->createElement("i");
                        $span = $dom->createElement("span");
                        $href->setAttribute("data-cmid", $info->id);
                        $href->setAttribute("class","completion-info");
                        $span->setAttribute("title",get_string("incomplete", "filter_activitycompletionimage"));
                        $span->setAttribute("class","state-incomplete");
                        $icon = "fa-circle-o";
                        if (!$info->uservisible) {
                            $icon = "fa-ban";
                            $span->setAttribute("class","state-unavailable");
                            $span->setAttribute("title",get_string("unavailable", "filter_activitycompletionimage"));
                        }
                        if ($info->completed) {
                            $icon = "fa-check-circle-o";
                            $span->setAttribute("class","state-completed");
                            $span->setAttribute("title",get_string("completed", "filter_activitycompletionimage"));
                        }
                        $i->setAttribute("class","fa {$icon}");
                        $span->appendChild($i);
                        $href->insertBefore($span, $img[0]);
                    }
                }
            }
            $text = $dom->saveHTML();
        }
        return $text;
    }
}

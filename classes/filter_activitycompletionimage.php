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
 * Adds a completion state icon to activity image links in course content.
 *
 * @package    filter_activitycompletionimage
 * @copyright  2018-2025 Tim St Clair <tim.stclair@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once("{$CFG->libdir}/completionlib.php");

class filter_activitycompletionimage extends moodle_text_filter {
    // Simple cache keyed on course and user.
    private static $activitylist = null;
    private static $cachedcourseid;
    private static $cacheduserid;

    private static function get_relative_url($url) {
      if (preg_match('#https?://[^/]+(/.*)$#i', $url, $matches)) {
          return $matches[1];
      }
      if (strpos($url, '/') === 0) {
          return $url;
      }
      return '';
    }

    public function filter($text, array $options = []): string {
        global $USER, $COURSE;

        $coursectx = $this->context->get_course_context(false);
        if (!$coursectx) {
            return $text;
        }
        $courseid = $coursectx->instanceid;

        // Invalidate cache if course or user changes.
        if (!isset(self::$cachedcourseid) || self::$cachedcourseid !== (int)$courseid) {
            self::$activitylist = null;
        }
        self::$cachedcourseid = (int)$courseid;

        if (!isset(self::$cacheduserid) || self::$cacheduserid !== (int)$USER->id) {
            self::$activitylist = null;
        }
        self::$cacheduserid = (int)$USER->id;

        // Build activity list if not cached.
        if (is_null(self::$activitylist)) {
            self::$activitylist = [];
            $modinfo = get_fast_modinfo($courseid);
            $completioninfo = new completion_info($COURSE);
            if (!empty($modinfo->cms)) {
                foreach ($modinfo->cms as $cm) {
                    if ($cm->visible && $cm->has_view()) {
                        $actinfo = $completioninfo->get_data($cm, false, self::$cacheduserid);
                        $urlpath = self::get_relative_url($cm->url->out());
                        self::$activitylist[$urlpath] = (object)[
                            'name' => $cm->name,
                            'id' => $cm->id,
                            'uservisible' => $cm->uservisible,
                            'completed' => $actinfo->completionstate,
                        ];
                    }
                }
            }
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();

        // Convert to HTML entities for DOM parsing (PHP 8.4 compatible).
        $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        // $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);

        $dom->loadHTML($text, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $hrefs = $dom->getElementsByTagName("a");
        foreach ($hrefs as $href) {
            $hrefkey = trim($href->getAttribute("href"));
            $hrefpath = self::get_relative_url($hrefkey);
            if (!array_key_exists($hrefpath, self::$activitylist)) {
                continue;
            }
            $info = self::$activitylist[$hrefpath];
            if ($info && $href->hasChildNodes()) {
                $img = $href->getElementsByTagName("img");
                if ($img->length === 1) {
                    $img[0]->removeAttribute("style");
                    $href->setAttribute("data-cmid", $info->id);
                    $href->setAttribute("class", "completion-info");

                    $span = $dom->createElement("span");
                    $span->setAttribute("title", get_string("incomplete", "filter_activitycompletionimage"));
                    $span->setAttribute("class", "state-incomplete");

                    $icon = "fa-circle-o";
                    if (!$info->uservisible) {
                        $icon = "fa-ban";
                        $span->setAttribute("class", "state-unavailable");
                        $span->setAttribute("title", get_string("unavailable", "filter_activitycompletionimage"));
                    } else if ($info->completed) {
                        $icon = "fa-check-circle-o";
                        $span->setAttribute("class", "state-completed");
                        $span->setAttribute("title", get_string("completed", "filter_activitycompletionimage"));
                    }

                    $i = $dom->createElement("i");
                    $i->setAttribute("class", "fa {$icon}");
                    $span->appendChild($i);
                    $href->insertBefore($span, $img[0]);
                }
            }
        }
        return $dom->saveHTML();
    }
}

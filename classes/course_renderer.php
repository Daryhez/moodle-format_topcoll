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
 * Collapsed Topics Information
 *
 * A topic based format that solves the issue of the 'Scroll of Death' when a course has many topics. All topics
 * except zero have a toggle that displays that topic. One or more topics can be displayed at any given time.
 * Toggles are persistent on a per browser session per course basis but can be made to persist longer by a small
 * code change. Full installation instructions, code adaptions and credits are included in the 'Readme.txt' file.
 *
 * @package    format_topcoll
 * @version    See the value of '$plugin->version' in version.php.
 * @copyright  &copy; 2018-onwards G J Barnard in respect to modifications of standard topics format.
 * @author     G J Barnard - {@link http://moodle.org/user/profile.php?id=442195}
 * @link       http://docs.moodle.org/en/Collapsed_Topics_course_format
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 *
 */

defined('MOODLE_INTERNAL') || die();

class format_topcoll_course_renderer extends \core_course_renderer {

    /**
     * Renders html to display a name with the link to the course module on a course page
     *
     * If module is unavailable for user but still needs to be displayed
     * in the list, just the name is returned without a link
     *
     * Note, that for course modules that never have separate pages (i.e. labels)
     * this function return an empty string
     *
     * @param cm_info $mod
     * @param array $displayoptions
     * @return string
     */
    public function course_section_cm_name(cm_info $mod, $displayoptions = array()) {
        if (!$mod->is_visible_on_course_page() || !$mod->url) {
            // Nothing to be displayed to the user.
            return '';
        }

        list($linkclasses, $textclasses) = $this->course_section_cm_classes($mod);
        $groupinglabel = $mod->get_grouping_label($textclasses);

        /* Render element that allows to edit activity name inline. It calls {@link course_section_cm_name_title()}
           to get the display title of the activity. */
        $tmpl = new \format_topcoll\output\course_module_name($mod, $this->page->user_is_editing(), $displayoptions);
        return $this->output->render_from_template('core/inplace_editable', $tmpl->export_for_template($this->output)) .
            $groupinglabel;
    }

    /**
     * Renders html to display a name with the link to the course module on a course page
     *
     * If module is unavailable for user but still needs to be displayed
     * in the list, just the name is returned without a link
     *
     * Note, that for course modules that never have separate pages (i.e. labels)
     * this function return an empty string
     *
     * @param cm_info $mod
     * @param array $displayoptions
     * @return string
     */
    public function course_section_cm_name_title(cm_info $mod, $displayoptions = array()) {
        $output = '';
        $url = $mod->url;
        if (!$mod->is_visible_on_course_page() || !$url) {
            // Nothing to be displayed to the user.
            return $output;
        }
        // Accessibility: for files get description via icon, this is very ugly hack!
        $instancename = $mod->get_formatted_name();
        $altname = $mod->modfullname;
        /* Avoid unnecessary duplication: if e.g. a forum name already
           includes the word forum (or Forum, etc) then it is unhelpful
           to include that in the accessible description that is added. */
        if (false !== strpos(core_text::strtolower($instancename),
                core_text::strtolower($altname))) {
            $altname = '';
        }
        // File type after name, for alphabetic lists (screen reader).
        if ($altname) {
            $altname = get_accesshide(' '.$altname);
        }

        list($linkclasses, $textclasses) = $this->course_section_cm_classes($mod);

        /* Get on-click attribute value if specified and decode the onclick - it
           has already been encoded for display. */
        $onclick = htmlspecialchars_decode($mod->onclick, ENT_QUOTES);

        // Display link itself.
        if (array_key_exists('sr', $displayoptions)) {
            // Add in the section return if the module was displayed on a single section page.
            $url->param('section', $displayoptions['sr']);
        }
        $activitylink = html_writer::empty_tag('img', array('src' => $mod->get_icon_url(),
            'class' => 'iconlarge activityicon', 'alt' => ' ', 'role' => 'presentation')) .
            html_writer::tag('span', $instancename . $altname, array('class' => 'instancename'));
        if ($mod->uservisible) {
            $output .= html_writer::link($url, $activitylink, array('class' => $linkclasses, 'onclick' => $onclick));
        } else {
            /* We may be displaying this just in order to show information
               about visibility, without the actual link ($mod->is_visible_on_course_page()). */
            $output .= html_writer::tag('div', $activitylink, array('class' => $textclasses));
        }
        return $output;
    }

    /* New / overridden methods added for activity styling below.
       Adapted from snap theme by Moodleroooms and Adaptable theme.*/
    /**
     * Renders HTML to display one course module in a course section
     *
     * This includes link, content, availability, completion info and additional information
     * that module type wants to display (i.e. number of unread forum posts)
     *
     * This function calls:
     * {@link core_course_renderer::course_section_cm_name()}
     * {@link core_course_renderer::course_section_cm_text()}
     * {@link core_course_renderer::course_section_cm_availability()}
     * {@link core_course_renderer::course_section_cm_completion()}
     * {@link course_get_cm_edit_actions()}
     * {@link core_course_renderer::course_section_cm_edit_actions()}
     *
     * @param stdClass $course
     * @param completion_info $completioninfo
     * @param cm_info $mod
     * @param int|null $sectionreturn
     * @param array $displayoptions
     * @return string
     */
    public function course_section_cm($course, &$completioninfo, cm_info $mod, $sectionreturn, $displayoptions = array()) {
        if ($this->page->user_is_editing()) { // Don't display the activity meta when editing so that drag and drop is not broken.
            return parent::course_section_cm($course, $completioninfo, $mod, $sectionreturn, $displayoptions);
        }
        global $USER;

        $output = '';
        /* We return empty string (because course module will not be displayed at all)
           if:
           1) The activity is not visible to users
           and
           2) The 'availableinfo' is empty, i.e. the activity was
              hidden in a way that leaves no info, such as using the
              eye icon. */
        if (!$mod->is_visible_on_course_page()) {
            return $output;
        }

        $indentclasses = 'mod-indent';
        if (!empty($mod->indent)) {
            $indentclasses .= ' mod-indent-'.$mod->indent;
            if ($mod->indent > 15) {
                $indentclasses .= ' mod-indent-huge';
            }
        }

        $output .= html_writer::start_tag('div');

        $output .= html_writer::start_tag('div', array('class' => 'mod-indent-outer'));

        // This div is used to indent the content.
        $output .= html_writer::div('', $indentclasses);

        // Start a wrapper for the actual content to keep the indentation consistent.
        $output .= html_writer::start_tag('div');

        // Display the link to the module (or do nothing if module has no url).
        $cmname = $this->course_section_cm_name($mod, $displayoptions);

        if (!empty($cmname)) {
            // Start the div for the activity title, excluding the edit icons.
            $output .= html_writer::start_tag('div', array('class' => 'activityinstance'));
            $output .= $cmname;

            // Module can put text after the link (e.g. forum unread).
            $output .= $mod->afterlink;

            // Closing the tag which contains everything but edit icons. Content part of the module should not be part of this.
            $output .= html_writer::end_tag('div');
        }

        /* If there is content but NO link (eg label), then display the
           content here (BEFORE any icons). In this case cons must be
           displayed after the content so that it makes more sense visually
           and for accessibility reasons, e.g. if you have a one-line label
           it should work similarly (at least in terms of ordering) to an
           activity. */
        $contentpart = $this->course_section_cm_text($mod, $displayoptions);
        $url = $mod->url;
        if (empty($url)) {
            $output .= $contentpart;
        }

        // Fetch completion details.
        $showcompletionconditions = $course->showcompletionconditions == COMPLETION_SHOW_CONDITIONS;
        $completiondetails = \core_completion\cm_completion_details::get_instance($mod, $USER->id, $showcompletionconditions);
        $ismanualcompletion = $completiondetails->has_completion() && !$completiondetails->is_automatic();

        // Fetch activity dates.
        $activitydates = [];
        if ($course->showactivitydates) {
            $activitydates = \core\activity_dates::get_dates_for_module($mod, $USER->id);
        }

        /* Show the activity information if:
           - The course's showcompletionconditions setting is enabled; or
           - The activity tracks completion manually; or
           - There are activity dates to be shown. */
        if ($showcompletionconditions || $ismanualcompletion || $activitydates) {
            $output .= $this->output->activity_information($mod, $completiondetails, $activitydates);
        }

        // Show availability info (if module is not available).
        $output .= $this->course_section_cm_availability($mod, $displayoptions);

        // Get further information.
        $courseformat = course_get_format($course);
        $tcsettings = $courseformat->get_settings();
        if ((!empty($tcsettings['showadditionalmoddata'])) && ($tcsettings['showadditionalmoddata'] == 2)) {
            $settingname = 'coursesectionactivityfurtherinformation'.$mod->modname;
            $setting = get_config('format_topcoll', $settingname);
            if ((!empty($setting)) && ($setting == 2)) {
                $cmmetaoutput = $this->course_section_cm_get_meta($mod);
                if (!empty($cmmetaoutput)) {
                    $output .= html_writer::start_tag('div', array('class' => 'ct-activity-meta-container'));
                    $output .= $cmmetaoutput;
                    $output .= html_writer::end_tag('div');
                }
            }
        }

        /* If there is content AND a link, then display the content here
           (AFTER any icons). Otherwise it was displayed before. */
        if (!empty($url)) {
            $output .= $contentpart;
        }

        $output .= html_writer::end_tag('div');

        // End of indentation div.
        $output .= html_writer::end_tag('div');

        $output .= html_writer::end_tag('div');

        return $output;
    }

    /**
     * Get the module meta data for a specific module.
     *
     * @param cm_info $mod
     * @return string
     */
    protected function course_section_cm_get_meta(cm_info $mod) {
        global $COURSE;

        if (is_guest(context_course::instance($COURSE->id))) {
            return '';
        }

        // If module is not visible to the user then don't bother getting meta data.
        if (!$mod->uservisible) {
            return '';
        }

        // Do we have an activity function for this module for returning meta data?
        $meta = \format_topcoll\activity::module_meta($mod);
        if ($meta == null) {
            // Can't get meta data for this module.
            return '';
        }
        $content = '';

        if ($meta->isteacher) {
            // Teacher - useful teacher meta data.
            $engagementmeta = array();

            if (!$meta->submissionnotrequired) {
                /* Below, != 0 means we would get x out of 0 submissions, so at least show something as
                   the module could now be hidden, but there is still useful information. */
                if ($meta->numparticipants != 0) {
                    $engagementmeta[] = get_string('xofy'.$meta->submitstrkey, 'format_topcoll',
                        (object) array(
                            'completed' => $meta->numsubmissions,
                            'participants' => $meta->numparticipants
                        )
                    );
                } else {
                    $engagementmeta[] = get_string('x'.$meta->submitstrkey, 'format_topcoll',
                        (object) array(
                            'completed' => $meta->numsubmissions
                        )
                    );
                }
            }

            if ($meta->numrequiregrading) {
                $engagementmeta[] = get_string('xungraded', 'format_topcoll', $meta->numrequiregrading);
            }
            if (!empty($engagementmeta)) {
                $engagementstr = implode(', ', $engagementmeta);

                $params = array(
                    'action' => 'grading',
                    'id' => $mod->id,
                    'tsort' => 'timesubmitted',
                    'filter' => 'require_grading'
                );
                $url = new moodle_url("/mod/{$mod->modname}/view.php", $params);

                $icon = $this->output->pix_icon('docs', get_string('info'));
                $content .= html_writer::start_tag('div', array('class' => 'ct-activity-mod-engagement'));
                $content .= html_writer::link($url, $icon.$engagementstr, array('class' => 'ct-activity-action'));
                $content .= html_writer::end_tag('div');
            }
        } else {
            // Feedback meta.
            if (!empty($meta->grade)) {
                   $url = new \moodle_url('/grade/report/user/index.php', ['id' => $COURSE->id]);
                if (in_array($mod->modname, ['quiz', 'assign'])) {
                    $url = new \moodle_url('/mod/'.$mod->modname.'/view.php?id='.$mod->id);
                }
                $content .= html_writer::start_tag('span', array('class' => 'ct-activity-mod-feedback'));

                $feedbackavailable = $this->output->pix_icon('t/message', get_string('feedback')) .
                    get_string('feedbackavailable', 'format_topcoll');
                $content .= html_writer::link($url, $feedbackavailable);
                $content .= html_writer::end_tag('span');
            }
        }

        return $content;
    }
}

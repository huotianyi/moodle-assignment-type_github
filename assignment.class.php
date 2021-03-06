<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/formslib.php');
require_once($CFG->dirroot.'/mod/assignment/lib.php');
require_once(dirname(__FILE__).'/config.php');

class assignment_github extends assignment_base {

    private $group;

    private $git;

    private $capability = array();

    function assignment_github($cmid='staticonly', $assignment=NULL, $cm=NULL, $course=NULL) {
        parent::assignment_base($cmid, $assignment, $cm, $course);
        $this->type = 'github';

        if ($cmid != 'staticonly') {
            $this->group = new stdClass();
            $this->init_group();
            $this->init_permission();
        }
    }

    function view() {

        global $PAGE;
        require_capability('mod/assignment:view', $this->context);
        add_to_log($this->course->id, "assignment", "view", "view.php?id={$this->cm->id}", $this->assignment->id, $this->cm->id);
        $PAGE->requires->css('/mod/assignment/type/'.$this->type.'/styles.css');

        $this->view_header();

        $this->view_intro();

        $this->view_repos();

        $this->view_dates();

        $this->view_feedback();

        $this->view_footer();
    }

    function view_header() {

        parent::view_header();

        $url = new moodle_url("/mod/assignment/type/github/list.php?id={$this->cm->id}");
        $link = html_writer::link($url, get_string('viewrepolist', 'assignment_github'));
        echo '<div class="git_checklink reportlink">'.$link.'</div>';
        echo '<div class="clearer"></div>';
    }

    /**
     * Init group settings. It will init the private varible group
     * $this->group object
     *   mode: if this assignment is group mode
     *   id: group id
     *   ismember: if current user is in this group
     *   members: array
     */
    private function init_group() {
        global $USER;

        $this->group->mode = groups_get_activity_groupmode($this->cm);
        $aag = has_capability('moodle/site:accessallgroups', $this->context);

        if ($this->group->mode == VISIBLEGROUPS or $aag) {
            $allowedgroups = groups_get_all_groups($this->cm->course, 0, $this->cm->groupingid); // only assigned groups
        } else {
            $allowedgroups = groups_get_all_groups($this->cm->course, $USER->id, $this->cm->groupingid); // only assigned groups
        }

        $this->group->id = groups_get_activity_group($this->cm, true, $allowedgroups);
        $this->group->ismember = groups_is_member($this->group->id);

        if ($this->group->id) {
            $this->group->members = $this->get_members_by_id($this->group->id);
        }
    }

    private function init_permission() {

        if (has_capability('mod/assignment:grade', $this->context)) {
            $this->capability['view'] = true;
            $this->capability['edit'] = true;
            $this->capability['grade'] = true;
            return;
        } else {
            $this->capability['grade'] = false;
        }

        if ($this->group->mode == VISIBLEGROUPS) {
            $this->capability['view'] = true;
            if ($this->group->ismember) {
                $this->capability['edit'] = true;
            } else {
                $this->capability['edit'] = false;
            }
            return;
        }

        if ($this->group->mode == SEPARATEGROUPS) {
            if ($this->group->ismember) {
                $this->capability['view'] = true;
                $this->capability['edit'] = true;
            } else {
                $this->capability['view'] = false;
                $this->capability['edit'] = false;
            }
            return;
        }

        // view edit
    }

    /**
     * Try to get the github repository of this assignment
     *
     * @return object
     */
    private function get_repo() {
        global $USER;

        if (!$this->git) {
            $this->git = new git($this->course->id, $this->assignment->id, $USER->id, $this->group->id);
        }

        if($this->group->mode) {
            return $this->git->get_by_group($this->group->id);
        } else {
            return $this->git->get_by_user($USER->id);
        }
    }

    function list_all() {
        global $USER;

        if (!$this->git) {
            $this->git = new git($this->course->id, $this->assignment->id, $USER->id, $this->group->id);
        }

        return $this->git->list_all($this->group->mode);
    }

    private function view_repos() {
        global $USER, $OUTPUT, $PAGE;

        $editmode = optional_param('edit', 0, PARAM_BOOL);
        $repo = $this->get_repo();
        
        echo $OUTPUT->box_start('generalbox boxaligncenter', 'intro');
        echo html_writer::tag('h3', get_string('githubreposetting', 'assignment_github'), array('class' => 'git_h3'));
        echo $OUTPUT->box_start('generalbox boxaligncenter git_box');

        $mform = new mod_assignment_github_edit_form(null, array('group' => $this->group, 'repo' => null, 'submission' => null));
        if (!$mform->is_cancelled() && $github_info = $mform->get_submitted_data()) {
            try {
                $repo = $this->save_repo($repo, $github_info);
            } catch (Exception $e) {
                echo html_writer::start_tag('div', array('class' => 'git_error')) .
                     $OUTPUT->notification($e->getMessage()) .
                     html_writer::link('javascript:history.go(-1);', get_string('back'), array('class' => 'git_back_link')) .
                     html_writer::end_tag('div');
            }
        }

        if(!$repo || $editmode) {
            $this->edit_form($repo);
        } else {
            $this->show_repo($repo);
        }
        echo $OUTPUT->box_end();
        echo $OUTPUT->box_end();
    }

    /**
     * Show a table contains the repository's infomation. If repository is not set, 
     * show a message to the guest.
     *
     * @param object $repository is optional, default to null. If it's null, this method
     *               will call get_repo to get the repository's infomation
     */
    private function show_repo($repository = null) {
        global $USER, $OUTPUT, $PAGE, $CFG;

        if (!$repository) {
            $repository = $this->get_repo();
        }

        if ($repository) {
            $service = $this->git->get_api_service($repository->server);

            echo html_writer::tag('h4', get_string('project', 'assignment_github'));
            $table = new html_table();

            $repository_row = new html_table_row();
            $repository_cell_header = new html_table_cell();
            $repository_cell_content = new html_table_cell();

            $repository_cell_header->text = get_string('projecthome', 'assignment_github');
            $repository_cell_header->header = true;

            $links = $service->generate_http_from_git($repository->url);
            $repo_link = html_writer::link($links['repo'],
                                           $links['repo'],
                                           array('target' => '_blank'));

            $repository_cell_content->text = $repo_link;

            $repository_row->cells = array($repository_cell_header, $repository_cell_content);

            $table->data = array($repository_row);
            echo html_writer::table($table);

            if (!empty($this->group->members) && $this->capability['view']) {
                echo html_writer::tag('h4', get_string('memberlist', 'assignment_github'));
                $member_table = new html_table();
                foreach($this->group->members as $id => $member) {
                    $member_row = new html_table_row();
                    $member_cell_picture = new html_table_cell();
                    $member_cell_name = new html_table_cell();
                    $member_cell_picture->header = true;
                    $member_cell_picture->text = $OUTPUT->user_picture($member);

                    if ($USER->id != $id) {
                        $member_cell_name->text = html_writer::link($CFG->wwwroot.'/message/index.php?id='.$id,
                                                                    fullname($member),
                                                                    array('title' => get_string('messageselectadd')));
                    } else {
                        $member_cell_name->text = fullname($member);
                    }
                    $member_row->cells = array($member_cell_picture, $member_cell_name);

                    if ($this->capability['edit']) {
                        $member_cell_content = new html_table_cell();

                        // get email from submission
                        $submission = $this->get_submission($id);
                        if (!empty($submission)) {
                            $email = $submission->data1;
                        } else {
                            $email = null;
                        }

                        if ($email) {
                            $member_cell_content->text = html_writer::link('mailto:' . $email,
                                                                           $email,
                                                                           array('target' => '_blank'));
                        } else {
                            $member_cell_content->text = get_string('emailnotset', 'assignment_github');
                        }
                        $member_row->cells[] = $member_cell_content;
                    }
                    $member_table->data[] = $member_row;
                }
                echo html_writer::table($member_table);
            }

            if (!$this->capability['edit'] || !$this->isopen()) {
                return;
            }

            $url = $PAGE->url;
            if ($this->group->mode) {
                $url->param('group', $this->group->id);
            }
            $url->param('sesskey', sesskey());
            $url->param('edit', '1');
            echo $OUTPUT->single_button($url, get_string('turneditingon'));
            return;
        }

        if ($this->group->mode && !$this->group->id) {
            echo $OUTPUT->notification(get_string('choosegroup', 'assignment_github'));
            return;
        }

        echo $OUTPUT->notification(get_string('repohasnotset', 'assignment_github'));
    }

    /**
     * Save user's github repository settings.
     *
     * @param integer $repoid optional, default to null. If it's null, add a new record, else not, update
     *                the record which id is $repoid.
     * @param object $github_info is the data submitted from edit form.
     * @return object repository if successfully saved, else false.
     */
    private function save_repo($repo = null, $github_info) {
        global $USER;

        if (!$this->isopen()) {
            return null;
        }

        if ($repo) {
            $repoid = $repo->id;
        } else {
            $repoid = null;
        }
        $groupmode = $this->group->mode;
        $members = array();

        // Group mode, check permission
        if (!$this->capability['edit']) {
            return null;
        }

        $result = false;
        if ($repoid) {
            $result = $this->git->update_repo($repoid, $github_info->url, $members);
        } else {
            $result = $this->git->add_repo($github_info->url, $members, $groupmode);
        }
        $repo = $this->get_repo();

        // if update failed or this user is teacher
        if (!$result || ($groupmode && !$this->group->ismember)) {
            return $repo;
        }

        $service =& $this->git->get_api_service($repo->server);
        $data = new stdClass();
        $urls = $service->generate_http_from_git($repo->url);
        $data->email = $github_info->email;
        $this->update_submission($USER->id, $data);

        return $repo;
    }

    /**
     * Display a edit form to user
     *
     * @param object $repo optional, if it's not null, repo's infomation will be filled in the form
     */
    private function edit_form($repo = null) {
        global $PAGE, $USER;

        // Group mode, check permission
        if (($this->capability['view'] && !$this->capability['edit']) || !$this->group->id || !$this->isopen()) {
            return $this->show_repo($repo);
        }

        $url = $PAGE->url;
        $url->remove_params('edit');

        $submission = $this->get_submission($USER->id);
        $mform = new mod_assignment_github_edit_form($url->out(), array('group' => $this->group, 'repo' => $repo, 'submission' => $submission));
        $mform->display();
    }

    private function get_members_by_id($id, $fields = array(), $sort = 'lastname ASC') {

        if(is_array($fields) && $fields) {
            foreach($fields as $k => $v) {
                if (substr($v, 0, 2) !== 'u.') {
                    $fields[$k] = 'u.'.$v;
                }
            }
            $fields = implode($fields, ',');
        } else {
            $fields = 'u.*';
        }

        return groups_get_members($id, $fields, $sort);
    }

    private function update_submission($userid = 0, $data) {
        global $USER, $DB;

        if (!$userid) {
            $userid = $USER->id;
        }

        $submission = $this->get_submission($userid, true);

        $update = new stdClass();
        $update->id           = $submission->id;
        $update->data1        = $data->email;
        $update->timemodified = time();

        $DB->update_record('assignment_submissions', $update);

        $submission = $this->get_submission($userid);
        $this->update_grade($submission);
        return $submission;
    }

    public function print_student_answer($userid, $return=false) {
        global $OUTPUT;

        if (!$submission = $this->get_submission($userid)) {
            return '';
        }

        if (!$this->git) {
            $this->git = new git($this->course->id, $this->assignment->id);
        }

        $email = $submission->data1;
        $output = '<div>';
        if ($this->group->mode) {

            // user may in more than one groups. show all repos of these groups.
            $groups = groups_get_all_groups($this->cm->course, $userid, $this->cm->groupingid);
            foreach($groups as $id => $group) {
                $repo = $this->git->get_by_group($id);
                if (!empty($repo)) {
                    $service =& $this->git->get_api_service($repo->server);
                    $url = $service->generate_http_from_git($repo->url);
                    $link = html_writer::link($url['repo'], $repo->repo, array('target' => '_blank'));
                    $output .= '<span>'.$link.'</span><br/>';
                }
            }
        } else {
            $repo = $this->git->get_by_user($userid);
            if (!empty($repo)) {
                $service =& $this->git->get_api_service($repo->server);
                $url = $service->generate_http_from_git($repo->url);
                $link = html_writer::link($url['repo'], $repo->repo, array('target' => '_blank'));
                $output .= '<span>'.$link.'</span><br/>';
            }
        }

        $output .= '<span>' . $email . '</span></div>';
        return $output;
    }

    function print_user_files($userid, $return=false) {
        if ($return) {
            return $this->print_student_answer($userid);
        } else {
            echo $this->print_student_answer($userid);
        }
    }

    /**
     * Display the assignment dates
     */
    function view_dates() {
        global $DB, $OUTPUT;
        if (!$this->assignment->timeavailable && !$this->assignment->timedue) {
            return;
        }

        echo $OUTPUT->box_start('generalbox boxaligncenter', 'dates');
        echo '<table>';
        if ($this->assignment->timeavailable) {
            echo '<tr><td class="c0">'.get_string('availabledate','assignment').':</td>';
            echo '    <td class="c1">'.userdate($this->assignment->timeavailable).'</td></tr>';
        }
        if ($this->assignment->timedue) {
            echo '<tr><td class="c0">'.get_string('duedate','assignment').':</td>';
            echo '    <td class="c1">'.userdate($this->assignment->timedue).'</td></tr>';
        }
        if ($repo = $this->get_repo()) {
            if ($repo->updated_user && $repo->updated) {
                $user = $DB->get_record('user', array('id'=>$repo->updated_user));
                $lastmodified = $repo->updated;
            } else {
                $user = $DB->get_record('user', array('id'=>$repo->created_user));
                $lastmodified = $repo->created;
            }
            echo '<tr><td class="c0">'.get_string('lastedited').':</td>';
            echo '    <td class="c1">'.userdate($lastmodified).' '.fullname($user);
        }
        echo '</table>';
        echo $OUTPUT->box_end();
    }
}

class mod_assignment_github_edit_form extends moodleform {

    function definition() {
        global $USER;

        $mform = $this->_form;
        $repo = $this->_customdata['repo'];
        $group = $this->_customdata['group'];
        $submission = $this->_customdata['submission'];

        // visible elements
        $mform->addElement('text', 'url', get_string('repositoryrourl', 'assignment_github'));
        $mform->addHelpButton('url', 'repositoryrourl', 'assignment_github');
        $mform->setType('url', PARAM_TEXT);
        $mform->addRule('url', get_string('required'), 'required', null, 'client');

        // teacher is not allowed to edit students' email
        if ($group->mode && $group->ismember || !$group->mode) {
            $mform->addElement('text', 'email', get_string('memberemail', 'assignment_github', fullname($USER)));
            $mform->addHelpButton('email', 'memberemail', 'assignment_github');
            $mform->setType('email', PARAM_EMAIL);
            $mform->addRule('email', get_string('required'), 'required', null, 'client');

            if ($submission && $submission->data1) {
                $email = $submission->data1;
            } else {
                $email = $USER->email;
            }
            $mform->setDefault('email', $email);
        }

        if ($repo) {
            $mform->setDefault('url', $repo->url);
        }

        // Submit button. No cancel
        $this->add_action_buttons(false);
    }
}

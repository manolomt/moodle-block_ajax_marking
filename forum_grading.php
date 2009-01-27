<?php

require_login(1, false);

class forum_functions extends module_base {
    function forum_functions(&$reference) {
        $this->reference = $reference;
        // must be the same as th DB modulename
        $this->type = 'forum';
        $this->capability = 'mod/forum:viewhiddentimedposts';
    }


     /**
     * Gets all unmarked forum posts, but defines unmarked as not marked by the current account. If
     * another teacher has marked it, that is a problem.
     * @return <type> gets all unmarked forum discussions for all courses
     */
    function get_all_unmarked() {
        global $CFG, $USER;

        
            $sql = "
                SELECT p.id as postid, p.userid, d.id, f.id, f.name, f.course, c.id as cmid
                FROM
                    {$CFG->prefix}forum f
                INNER JOIN {$CFG->prefix}course_modules c
                     ON f.id = c.instance
                INNER JOIN {$CFG->prefix}forum_discussions d
                     ON d.forum = f.id
                INNER JOIN {$CFG->prefix}forum_posts p
                     ON p.discussion = d.id
                LEFT JOIN {$CFG->prefix}forum_ratings r
                     ON  p.id = r.post
                WHERE p.userid <> $USER->id
                    AND (((r.userid <> $USER->id) AND (r.userid NOT IN ({$this->reference->teachers}))) OR r.userid IS NULL)
                    AND c.module = {$this->reference->module_ids['forum']->id}
                    AND c.visible = 1
                    AND f.course IN ({$this->reference->course_ids})
                    AND ((f.type <> 'eachuser') OR (f.type = 'eachuser' AND p.id = d.firstpost))
                    AND f.assessed > 0
                ORDER BY f.id
            ";

            $submissions = get_records_sql($sql);
            return $submissions;
     
    }


    function get_all_course_unmarked($courseid) {

        global $CFG, $USER;
        $unmarked = '';

        $sql = "SELECT p.id as post_id, p.userid, d.firstpost, f.type, f.id, f.name, f.intro as description, c.id as cmid
                FROM
                    {$CFG->prefix}forum f
                INNER JOIN {$CFG->prefix}course_modules c
                     ON f.id = c.instance
                INNER JOIN {$CFG->prefix}forum_discussions d
                     ON d.forum = f.id
                INNER JOIN {$CFG->prefix}forum_posts p
                     ON p.discussion = d.id
                LEFT JOIN {$CFG->prefix}forum_ratings r
                     ON  p.id = r.post
                WHERE p.userid <> $USER->id
                    AND p.userid IN ({$this->reference->student_ids->$courseid})
                    AND (((r.userid <> $USER->id) AND (r.userid NOT IN ({$this->reference->teachers}))) OR r.userid IS NULL)

                    AND ((f.type <> 'eachuser') OR (f.type = 'eachuser' AND p.id = d.firstpost))
                    AND c.module = {$this->reference->module_ids['forum']->id}
                    AND c.visible = 1
                    AND f.course = $courseid
                    AND f.assessed > 0
                ORDER BY f.id
          ";
        $unmarked = get_records_sql($sql, $this->type);
        return $unmarked;


    }


    /**
     * function to make nodes for forum submissions
     */

    function submissions() {

        global $CFG, $USER;

        $discussions = '';
        $forum = get_record('forum', 'id', $this->reference->id);
        $courseid = $forum->course;
        $this->reference->get_course_students($courseid);

        $discussions = get_records('forum_discussions', 'forum', $this->reference->id);
        if (!$discussions) {
            return;
        }

        // get ready to fetch all the unrated posts
        $sql = "SELECT p.id, p.userid, p.created, p.message, d.id as discussionid
                FROM {$CFG->prefix}forum_discussions d ";

        if ($forum->type == 'eachuser') {
            // add a bit to link to forum so we can check the type is correct
            $sql .= "INNER JOIN {$CFG->prefix}forum f ON d.forum = f.id "  ;
        }

        $sql .= "INNER JOIN {$CFG->prefix}forum_posts p
                     ON p.discussion = d.id
                 LEFT JOIN {$CFG->prefix}forum_ratings r
                     ON  p.id = r.post
                 WHERE d.forum = {$this->reference->id}
                 AND p.userid <> {$USER->id}
                 AND p.userid IN ({$this->reference->student_ids->$courseid})
                 AND (((r.userid <> {$USER->id})  AND (r.userid NOT IN ({$this->reference->teachers}))) OR r.userid IS NULL)
                 ";

        if ($forum->type == 'eachuser') {
            // make sure that it is just the first posts that we get
            $sql .= " AND (f.type = 'eachuser' AND p.id = d.firstpost)";
        }

        $posts = get_records_sql($sql);

        if ($posts) {
            foreach ($posts as $key=>$post) {

              // sort for obvious exclusions
              if (!isset($post->userid)) {
                   unset($posts[$key]);
                   continue;
               }
               // Maybe this forum doesn't rate posts earlier than X time, so we check.
               if ($forum->assesstimestart != 0) {

                    if (!($post->created > $forum->assesstimestart))  {
                        unset($posts[$key]);
                        continue;
                    }
                }
                // Same for later cut-off time
                if ($forum->assesstimefinish != 0) {
                    if (!($post->created < $forum->assesstimefinish)) { // it also has a later limit, so check that too.
                        unset($posts[$key]);
                        continue;
                     }
                }
            }

            // Check to see if group nodes need to be made instead of submissions

            if(!$this->reference->group) {
                   $group_filter = $this->reference->assessment_groups_filter($posts, $this->type, $forum->id);
                   if (!$group_filter) {
                       return;
                   }
            }

            // Submissions nodes are needed, so make one per discussion
            $this->reference->output = '[{"type":"submissions"}';      // begin json object.

            // we may have excluded all of them now, so check again
            if (count($posts) > 0) {
                foreach ($discussions as $discussion) {

                    $firstpost = '';
                   // $settings =
                    //check_submission_display_settings($check, $userid)
                    if ($this->reference->group && !$this->reference->check_group_membership($this->reference->group, $discussion->userid)) {
                    //if ($this->group && !groups_is_member($this->group, $discussion->userid)) {
                        continue;
                    }

                    $count = 0;
                    $sid = 0; // this variable will hold the id of the first post which is unrated, so it can be used
                                              // in the link to load the pop up with the discussion page at that position.
                    $time = time(); // start seconds at current time so we can compare with time created to find the oldest as we cycle through

                    // if this forum is set to 'each student posts one discussion', we want to only grade the first one, which is the only one returned.
                    if ($forum->type == 'eachuser') {
                         $count = 1;
                    } else {

                        // any other type of graded forum, we can grade any posts that are not yet graded
                        // this means counting them first.

                        $time = time(); // start seconds at current time so we can compare with time created to find the oldest as we cycle through

                        $firsttime = '';
                        foreach ($posts as $key=>$post) {

                            if ($discussion->id == $post->discussionid) {
                                //post is relevant
                                $count++;

                                // link needs the id of the earliest post, so store time if this is the first post; check and modify for subsequent ones
                                if ($firstpost) {
                                    if ($post->created > $firstpost) {
                                        $firstpost = $post;
                                    }
                                } else {
                                    $firstpost = $post;
                                }
                                // store the time created for the tooltip if its the oldest post yet for this discussion
                                if ($firsttime) {
                                    if ($post->created < $time) {
                                        $time = $post->created;
                                    }
                                } else {
                                    $firsttime = $post->created;
                                }
                            }
                        } // end foreach posts
                    } // end any other graded forum

                    // add the node if there were any posts -  the node is the discussion with a count of the number of unrated posts
                    if ($count > 0) {

                        // make all the variables ready to put them together into the array
                        $seconds = time() - $discussion->timemodified;

                        if ($forum->type == 'eachuser') { // we will show the student name as the node name as there is only one post that matters
                            $name = $this->reference->get_fullname($firstpost->userid);

                        } else { // the name will be the name of the discussion
                                $name = $discussion->name;

                        }

                        $sum = strip_tags($firstpost->message);

                        $shortsum = substr($sum, 0, 100);
                        if (strlen($shortsum) < strlen($sum)) {$shortsum .= "...";}
                        $timesum = $this->reference->make_time_summary($seconds, true);
                        if (!isset($discuss)) {
                            $discuss = get_string('discussion', 'block_ajax_marking');
                        }
                        $summary = $discuss.": ".$shortsum."<br />".$timesum;

                        $this->reference->output .= $this->reference->make_submission_node($name, $firstpost->id, $discussion->id, $summary, 'discussion', $seconds, $time, $count);

                    }
                }// end foreach discussion
            }
            $this->reference->output .= "]"; // end JSON array
        }// if discussions
    } // end function

} // end class

?>
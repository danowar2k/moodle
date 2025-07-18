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
 * Code for handling and processing questions.
 *
 * This is code that is module independent, i.e., can be used by any module that
 * uses questions, like quiz, lesson, etc.
 * This script also loads the questiontype classes.
 * Code for handling the editing of questions is in question/editlib.php
 *
 * @package    core
 * @subpackage questionbank
 * @copyright  1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_question\local\bank\question_version_status;
use core_question\question_reference_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/question/type/questiontypebase.php');



// CONSTANTS.

/**
 * Constant determines the number of answer boxes supplied in the editing
 * form for multiple choice and similar question types.
 */
define("QUESTION_NUMANS", 10);

/**
 * Constant determines the number of answer boxes supplied in the editing
 * form for multiple choice and similar question types to start with, with
 * the option of adding QUESTION_NUMANS_ADD more answers.
 */
define("QUESTION_NUMANS_START", 3);

/**
 * Constant determines the number of answer boxes to add in the editing
 * form for multiple choice and similar question types when the user presses
 * 'add form fields button'.
 */
define("QUESTION_NUMANS_ADD", 3);

/**
 * Move one question type in a list of question types. If you try to move one element
 * off of the end, nothing will change.
 *
 * @param array $sortedqtypes An array $qtype => anything.
 * @param string $tomove one of the keys from $sortedqtypes
 * @param integer $direction +1 or -1
 * @return array an array $index => $qtype, with $index from 0 to n in order, and
 *      the $qtypes in the same order as $sortedqtypes, except that $tomove will
 *      have been moved one place.
 */
function question_reorder_qtypes($sortedqtypes, $tomove, $direction): array {
    $neworder = array_keys($sortedqtypes);
    // Find the element to move.
    $key = array_search($tomove, $neworder);
    if ($key === false) {
        return $neworder;
    }
    // Work out the other index.
    $otherkey = $key + $direction;
    if (!isset($neworder[$otherkey])) {
        return $neworder;
    }
    // Do the swap.
    $swap = $neworder[$otherkey];
    $neworder[$otherkey] = $neworder[$key];
    $neworder[$key] = $swap;
    return $neworder;
}

/**
 * Save a new question type order to the config_plugins table.
 *
 * @param array $neworder An arra $index => $qtype. Indices should start at 0 and be in order.
 * @param object $config get_config('question'), if you happen to have it around, to save one DB query.
 */
function question_save_qtype_order($neworder, $config = null): void {
    if (is_null($config)) {
        $config = get_config('question');
    }

    foreach ($neworder as $index => $qtype) {
        $sortvar = $qtype . '_sortorder';
        if (!isset($config->$sortvar) || $config->$sortvar != $index + 1) {
            set_config($sortvar, $index + 1, 'question');
        }
    }
}

// FUNCTIONS.

/**
 * Check if the question is used.
 *
 * @param array $questionids of question ids.
 * @return boolean whether any of these questions are being used by any part of Moodle.
 */
function questions_in_use($questionids): bool {

    // Are they used by the core question system?
    if (question_engine::questions_in_use($questionids)) {
        return true;
    }

    if (question_reference_manager::questions_with_references($questionids)) {
        return true;
    }

    // Check if any plugins are using these questions.
    $callbacksbytype = get_plugins_with_function('questions_in_use');
    foreach ($callbacksbytype as $callbacks) {
        foreach ($callbacks as $function) {
            if ($function($questionids)) {
                return true;
            }
        }
    }

    // Finally check legacy callback.
    $legacycallbacks = get_plugin_list_with_function('mod', 'question_list_instances');
    foreach ($legacycallbacks as $plugin => $function) {
        debugging($plugin . ' implements deprecated method ' . $function .
                '. ' . $plugin . '_questions_in_use should be implemented instead.', DEBUG_DEVELOPER);

        if (isset($callbacksbytype['mod'][substr($plugin, 4)])) {
            continue; // Already done.
        }

        foreach ($questionids as $questionid) {
            if (!empty($function($questionid))) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Determine whether there are any questions belonging to this context, that is whether any of its
 * question categories contain any questions. This will return true even if all the questions are
 * hidden.
 *
 * @param mixed $context either a context object, or a context id.
 * @return boolean whether any of the question categories beloning to this context have
 *         any questions in them.
 */
function question_context_has_any_questions($context): bool {
    global $DB;
    if (is_object($context)) {
        $contextid = $context->id;
    } else if (is_numeric($context)) {
        $contextid = $context;
    } else {
        throw new moodle_exception('invalidcontextinhasanyquestions', 'question');
    }
    $sql = 'SELECT qbe.*
              FROM {question_bank_entries} qbe
              JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
             WHERE qc.contextid = ?';
    return $DB->record_exists_sql($sql, [$contextid]);
}

/**
 * Check whether a given grade is one of a list of allowed options. If not,
 * depending on $matchgrades, either return the nearest match, or return false
 * to signal an error.
 *
 * @param array $gradeoptionsfull list of valid options
 * @param int $grade grade to be tested
 * @param string $matchgrades 'error' or 'nearest'
 * @return false|int|string either 'fixed' value or false if error.
 */
function match_grade_options($gradeoptionsfull, $grade, $matchgrades = 'error') {

    if ($matchgrades == 'error') {
        // ...(Almost) exact match, or an error.
        foreach ($gradeoptionsfull as $value => $option) {
            // Slightly fuzzy test, never check floats for equality.
            if (abs($grade - $value) < 0.00001) {
                return $value; // Be sure the return the proper value.
            }
        }
        // Didn't find a match so that's an error.
        return false;

    } else if ($matchgrades == 'nearest') {
        // Work out nearest value.
        $best = false;
        $bestmismatch = 2;
        foreach ($gradeoptionsfull as $value => $option) {
            $newmismatch = abs($grade - $value);
            if ($newmismatch < $bestmismatch) {
                $best = $value;
                $bestmismatch = $newmismatch;
            }
        }
        return $best;

    } else {
        // Unknow option passed.
        throw new coding_exception('Unknown $matchgrades ' . $matchgrades .
                ' passed to match_grade_options');
    }
}

/**
 * Category is about to be deleted,
 * 1/ All questions are deleted for this question category.
 * 2/ Any questions that can't be deleted are moved to a new category
 * NOTE: this function is called from lib/db/upgrade.php
 *
 * @param object|core_course_category $category course category object
 * @param bool $coursedeletion Is the course this category is under being deleted? If so, move saved questions to the site course.
 */
function question_category_delete_safe($category, bool $coursedeletion = false): void {
    global $DB;
    $criteria = ['questioncategoryid' => $category->id];
    $context = context::instance_by_id($category->contextid, IGNORE_MISSING);
    $rescue = null; // See the code around the call to question_save_from_deletion.

    // Deal with any questions in the category.
    if ($questionentries = $DB->get_records('question_bank_entries', $criteria, '', 'id')) {

        foreach ($questionentries as $questionentry) {
            $questionids = $DB->get_records('question_versions',
                                                ['questionbankentryid' => $questionentry->id], '', 'questionid');

            // Try to delete each question.
            foreach ($questionids as $questionid) {
                question_delete_question($questionid->questionid, $category->contextid);
            }
        }

        // Check to see if there were any questions that were kept because
        // they are still in use somehow, even though quizzes in courses
        // in this category will already have been deleted. This could
        // happen, for example, if questions are added to a course,
        // and then that course is moved to another category (MDL-14802).
        $questionids = [];
        foreach ($questionentries as $questionentry) {
            $versions = $DB->get_records('question_versions', ['questionbankentryid' => $questionentry->id], '', 'questionid');
            foreach ($versions as $key => $version) {
                $questionids[$key] = $version;
            }
        }
        if (!empty($questionids)) {
            $name = get_string('unknown', 'question');
            if ($context !== false) {
                $name = $context->get_context_name();
                $parentcontext = $context->get_course_context(false);
                $course = ($parentcontext && !$coursedeletion) ? get_course($parentcontext->instanceid) : get_site();
            }
            $qbank = core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
            question_save_from_deletion(array_keys($questionids), $qbank->context->id, $name, $rescue);
        }
    }

    // Now delete the category.
    $DB->delete_records('question_categories', ['id' => $category->id]);
}

/**
 * Tests whether any question in a category is used by any part of Moodle.
 *
 * @param integer $categoryid a question category id.
 * @param boolean $recursive whether to check child categories too.
 * @return boolean whether any question in this category is in use.
 */
function question_category_in_use($categoryid, $recursive = false): bool {
    global $DB;

    // Look at each question in the category.
    $questionids = question_bank::get_finder()->get_questions_from_categories([$categoryid], null);
    if ($questionids) {
        if (questions_in_use(array_keys($questionids))) {
            return true;
        }
    }
    if (!$recursive) {
        return false;
    }

    // Look under child categories recursively.
    if ($children = $DB->get_records('question_categories',
            ['parent' => $categoryid], '', 'id, 1')) {
        foreach ($children as $child) {
            if (question_category_in_use($child->id, $recursive)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Check if there is more versions left for the entry.
 * If not delete the entry.
 *
 * @param int $entryid
 */
function delete_question_bank_entry($entryid): void {
    global $DB;
    if (!$DB->record_exists('question_versions', ['questionbankentryid' => $entryid])) {
        $DB->delete_records('question_bank_entries', ['id' => $entryid]);
    }
}

/**
 * Deletes question and all associated data from the database
 *
 * It will not delete a question if it is used somewhere, instead it will just delete the reference.
 *
 * @param int $questionid The id of the question being deleted
 */
function question_delete_question($questionid): void {
    global $DB;

    $question = $DB->get_record('question', ['id' => $questionid]);
    if (!$question) {
        // In some situations, for example if this was a child of a
        // Cloze question that was previously deleted, the question may already
        // have gone. In this case, just do nothing.
        return;
    }

    $sql = 'SELECT qv.id as versionid,
                   qv.version,
                   qbe.id as entryid,
                   qc.id as categoryid,
                   ctx.id as contextid
              FROM {question} q
              LEFT JOIN {question_versions} qv ON qv.questionid = q.id
              LEFT JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              LEFT JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              LEFT JOIN {context} ctx ON ctx.id = qc.contextid
             WHERE q.id = ?';
    $questiondata = $DB->get_record_sql($sql, [$question->id]);

    $questionstocheck = [$question->id];

    if ($question->parent) {
        $questionstocheck[] = $question->parent;
    }

    // Do not delete a question if it is used by an activity module. Just mark the version hidden.
    if (questions_in_use($questionstocheck)) {
        $DB->set_field('question_versions', 'status',
                question_version_status::QUESTION_STATUS_HIDDEN, ['questionid' => $questionid]);
        return;
    }

    // This sometimes happens in old sites with bad data.
    if (!$questiondata->contextid) {
        debugging('Deleting question ' . $question->id . ' which is no longer linked to a context. ' .
            'Assuming system context to avoid errors, but this may mean that some data like files, ' .
            'tags, are not cleaned up.');
        $questiondata->contextid = context_system::instance()->id;
        $questiondata->categoryid = 0;
    }

    // Delete previews of the question.
    $dm = new question_engine_data_mapper();
    $dm->delete_previews($question->id);

    // Delete questiontype-specific data.
    question_bank::get_qtype($question->qtype, false)->delete_question($question->id, $questiondata->contextid);

    // Now recursively delete all child questions
    if ($children = $DB->get_records('question',
            array('parent' => $questionid), '', 'id, qtype')) {
        foreach ($children as $child) {
            if ($child->id != $questionid) {
                question_delete_question($child->id);
            }
        }
    }

    // Finally delete the question record itself.
    $DB->delete_records('question', ['id' => $question->id]);
    $DB->delete_records('question_versions', ['id' => $questiondata->versionid]);
    $DB->delete_records('question_references',
        [
            'version' => $questiondata->version,
            'questionbankentryid' => $questiondata->entryid,
        ]);
    delete_question_bank_entry($questiondata->entryid);
    question_bank::notify_question_edited($question->id);

    // Log the deletion of this question.
    // Any qbank plugins storing additional question data should observe this event and perform the necessary deletion.
    $question->category = $questiondata->categoryid;
    $question->contextid = $questiondata->contextid;
    $event = \core\event\question_deleted::create_from_question_instance($question);
    $event->add_record_snapshot('question', $question);
    $event->trigger();
}

/**
 * All question categories and their questions are deleted for this context id.
 *
 * @param int $contextid The contextid to delete question categories from
 * @param bool $coursedeletion Are we calling this as part of deleting the course the context is under?
 * @return array only returns an empty array for backwards compatibility.
 */
function question_delete_context($contextid, bool $coursedeletion = false): array {
    global $DB;

    $fields = 'id, parent, name, contextid';
    if ($categories = $DB->get_records('question_categories', ['contextid' => $contextid], 'parent', $fields)) {
        // Sort categories following their tree (parent-child) relationships this will make the feedback more readable.
        $categories = sort_categories_by_tree($categories);
        foreach ($categories as $category) {
            question_category_delete_safe($category, $coursedeletion);
        }
    }
    return [];
}

/**
 * Creates a new category to save the questions in use.
 *
 * @param array $questionids of question ids
 * @param int $newcontextid the context to create the saved category in.
 * @param string $oldplace a textual description of the think being deleted,
 *      e.g. from get_context_name
 * @param object $newcategory
 * @return mixed false on
 */
function question_save_from_deletion($questionids, $newcontextid, $oldplace, $newcategory = null) {
    global $DB;

    $newcontext = context::instance_by_id($newcontextid);
    if ($newcontext->contextlevel !== CONTEXT_MODULE) {
        throw new moodle_exception("Invalid contextlevel: {$newcontext->contextlevel} for \$newcontextid {$newcontextid}");
    }

    // Make a category in the parent context to move the questions to.
    if (is_null($newcategory)) {
        $newcategory = new stdClass();
        $newcategory->parent = question_get_top_category($newcontextid, true)->id;
        $newcategory->contextid = $newcontextid;
        // Max length of column name in question_categories is 255.
        $newcategory->name = shorten_text(get_string('questionsrescuedfrom', 'question', $oldplace), 255);
        $newcategory->info = get_string('questionsrescuedfrominfo', 'question', $oldplace);
        $newcategory->sortorder = 999;
        $newcategory->stamp = make_unique_id_code();
        $newcategory->id = $DB->insert_record('question_categories', $newcategory);
    }

    // Move any remaining questions to the 'saved' category.
    if (!question_move_questions_to_category($questionids, $newcategory->id)) {
        return false;
    }
    return $newcategory;
}

/**
 * All question categories and their questions are deleted for this activity.
 *
 * @param object $cm the course module object representing the activity
 * @param bool $notused the argument is not used any more. Kept for backwards compatibility.
 * @param bool $coursedeletion Are we calling this as part of deleting the course the activity belongs to?
 * @return boolean
 */
function question_delete_activity($cm, $notused = false, bool $coursedeletion = false): bool {
    $modcontext = context_module::instance($cm->id);
    question_delete_context($modcontext->id, $coursedeletion);
    return true;
}

/**
 * This function will handle moving all tag instances to a new context for a
 * given list of questions.
 *
 * @param stdClass[] $questions The list of question being moved (must include
 *                              the id and contextid)
 * @param context $newcontext The Moodle context the questions are being moved to, must be module context.
 */
function question_move_question_tags_to_new_context(array $questions, context $newcontext): void {

    if ($newcontext->contextlevel !== CONTEXT_MODULE) {
        debugging("Invalid contextlevel: {$newcontext->contextlevel}", DEBUG_DEVELOPER);
    }

    $instancesfornewcontext = [];
    $questionids = array_map(function($question) {
        return $question->id;
    }, $questions);
    $questionstagobjects = core_tag_tag::get_items_tags('core_question', 'question', $questionids);

    foreach ($questions as $question) {
        $tagobjects = $questionstagobjects[$question->id] ?? [];

        foreach ($tagobjects as $tagobject) {
            $tagid = $tagobject->taginstanceid;
            $tagcontextid = $tagobject->taginstancecontextid;
            $istaginnewcontext = $tagcontextid == $newcontext->id;

            if ($istaginnewcontext) {
                // This tag instance is already in the correct context so we can
                // ignore it.
                continue;
            }

            $instancesfornewcontext[] = $tagid;
        }
    }

    if (!empty($instancesfornewcontext)) {
        // Update the tag instances to the new context id.
        core_tag_tag::change_instances_context($instancesfornewcontext, $newcontext);
    }
}

/**
 * Check if an idnumber exist in the category.
 *
 * @param int $questionidnumber
 * @param int $categoryid
 * @param int $limitfrom
 * @param int $limitnum
 * @return array
 */
function idnumber_exist_in_question_category($questionidnumber, $categoryid, $limitfrom = 0, $limitnum = 1): array {
    global $DB;
    $response  = false;
    $record = [];
    // Check if the idnumber exist in the category.
    $sql = 'SELECT qbe.idnumber
              FROM {question_bank_entries} qbe
             WHERE qbe.idnumber LIKE ?
               AND qbe.questioncategoryid = ?
          ORDER BY qbe.idnumber DESC';
    $questionrecord = $DB->record_exists_sql($sql, [$questionidnumber, $categoryid]);
    if ((string) $questionidnumber !== '' && $questionrecord) {
        $record = $DB->get_records_sql($sql, [$questionidnumber . '_%', $categoryid], 0, 1);
        $response  = true;
    }

    return [$response, $record];
}

/**
 * This function should be considered private to the question bank, it is called from
 * question/editlib.php question/contextmoveq.php and a few similar places to to the
 * work of actually moving questions and associated data. However, callers of this
 * function also have to do other work, which is why you should not call this method
 * directly from outside the questionbank.
 *
 * @param array $questionids of question ids.
 * @param integer $newcategoryid the id of the category to move to.
 * @return bool
 */
function question_move_questions_to_category($questionids, $newcategoryid): bool {
    global $DB;

    $newcategorydata = $DB->get_record('question_categories', ['id' => $newcategoryid]);
    if (!$newcategorydata) {
        return false;
    }
    list($questionidcondition, $params) = $DB->get_in_or_equal($questionids);

    $sql = "SELECT qv.id as versionid,
                   qbe.id as entryid,
                   qc.id as category,
                   qc.contextid as contextid,
                   q.id,
                   q.qtype,
                   qbe.idnumber
              FROM {question} q
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
             WHERE q.id $questionidcondition
                   OR (q.parent <> 0 AND q.parent $questionidcondition)";

    // Also, we need to move children questions.
    $params = array_merge($params, $params);
    $questions = $DB->get_records_sql($sql, $params);
    foreach ($questions as $question) {
        if ($newcategorydata->contextid != $question->contextid) {
            question_bank::get_qtype($question->qtype)->move_files(
                    $question->id, $question->contextid, $newcategorydata->contextid);
        }
        // Check whether there could be a clash of idnumbers in the new category.
        list($idnumberclash, $rec) = idnumber_exist_in_question_category($question->idnumber, $newcategoryid);
        if ($idnumberclash) {
            $unique = 1;
            if (count($rec)) {
                $rec = reset($rec);
                $idnumber = $rec->idnumber;
                if (strpos($idnumber, '_') !== false) {
                    $unique = substr($idnumber, strpos($idnumber, '_') + 1) + 1;
                }
            }
            // For the move process, add a numerical increment to the idnumber. This means that if a question is
            // mistakenly moved then the idnumber will not be completely lost.
            $qbankentry = new stdClass();
            $qbankentry->id = $question->entryid;
            $qbankentry->idnumber = $question->idnumber . '_' . $unique;
            $DB->update_record('question_bank_entries', $qbankentry);
        }

        // Update the entry to the new category id.
        $entry = new stdClass();
        $entry->id = $question->entryid;
        $entry->questioncategoryid = $newcategorydata->id;
        $DB->update_record('question_bank_entries', $entry);

        // Log this question move.
        $event = \core\event\question_moved::create_from_question_instance($question, context::instance_by_id($question->contextid),
                ['oldcategoryid' => $question->category, 'newcategoryid' => $newcategorydata->id]);
        $event->trigger();
    }

    $newcontext = context::instance_by_id($newcategorydata->contextid);
    question_move_question_tags_to_new_context($questions, $newcontext);

    // TODO Deal with datasets.

    // Purge these questions from the cache.
    foreach ($questions as $question) {
        question_bank::notify_question_edited($question->id);
    }

    return true;
}

/**
 * Update the questioncontextid field for all question_set_references records given a new context id
 *
 * @param int $oldcategoryid Old category to be moved.
 * @param int $newcatgoryid New category that will receive the questions.
 * @param int $oldcontextid Old context to be moved.
 * @param int $newcontextid New context that will receive the questions.
 * @param bool $delete If the action is delete.
 * @throws dml_exception
 */
function move_question_set_references(int $oldcategoryid, int $newcatgoryid,
                                      int $oldcontextid, int $newcontextid, bool $delete = false): void {
    global $DB;

    if ($delete || $oldcontextid !== $newcontextid) {
        $setreferences = $DB->get_recordset('question_set_references', ['questionscontextid' => $oldcontextid]);
        foreach ($setreferences as $setreference) {
            $filter = json_decode($setreference->filtercondition, true);
            if (isset($filter['questioncategoryid'])) {
                $filter = question_reference_manager::convert_legacy_set_reference_filter_condition($filter);
            }
            if ((int)$filter['filter']['category']['values'][0] === $oldcategoryid) {
                $setreference->questionscontextid = $newcontextid;
                if ($oldcategoryid !== $newcatgoryid) {
                    $filter['filter']['category']['values'][0] = $newcatgoryid;
                    $setreference->filtercondition = json_encode($filter);
                }
                $DB->update_record('question_set_references', $setreference);
            }
        }
        $setreferences->close();
    }
}

/**
 * This function helps move a question cateogry to a new context by moving all
 * the files belonging to all the questions to the new context.
 * Also moves subcategories.
 * @param integer $categoryid the id of the category being moved.
 * @param integer $oldcontextid the old context id.
 * @param integer $newcontextid the new context id.
 */
function question_move_category_to_context($categoryid, $oldcontextid, $newcontextid): void {
    global $DB;

    $newcontext = context::instance_by_id($newcontextid);
    if ($newcontext->contextlevel !== CONTEXT_MODULE) {
        debugging("Invalid contextlevel: {$newcontext->contextlevel}, must use CONTEXT_MODULE", DEBUG_DEVELOPER);
    }

    $questions = [];
    $sql = "SELECT q.id, q.qtype
              FROM {question} q
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
             WHERE qbe.questioncategoryid = ?";

    $questionids = $DB->get_records_sql_menu($sql, [$categoryid]);
    foreach ($questionids as $questionid => $qtype) {

        // If the question type is invalid, use "missingtype" so we have a valid qtype to call move_files() on.
        if (!\question_bank::is_qtype_installed($qtype)) {
            $qtype = 'missingtype';
        }

        question_bank::get_qtype($qtype)->move_files($questionid, $oldcontextid, $newcontextid);
        // Purge this question from the cache.
        question_bank::notify_question_edited($questionid);

        $questions[] = (object) [
            'id' => $questionid,
            'contextid' => $oldcontextid
        ];
    }

    question_move_question_tags_to_new_context($questions, $newcontext);

    $subcatids = $DB->get_records_menu('question_categories', ['parent' => $categoryid], '', 'id,1');
    foreach ($subcatids as $subcatid => $notused) {
        move_question_set_references($subcatid, $subcatid, $oldcontextid, $newcontext->id);
        $DB->set_field('question_categories', 'contextid', $newcontextid, ['id' => $subcatid]);
        question_move_category_to_context($subcatid, $oldcontextid, $newcontextid);
    }
}

/**
 * Given a list of ids, load the basic information about a set of questions from
 * the questions table. The $join and $extrafields arguments can be used together
 * to pull in extra data. See, for example, the usage in {@see \mod_quiz\quiz_attempt}, and
 * read the code below to see how the SQL is assembled. Throws exceptions on error.
 *
 * @param array $questionids array of question ids to load. If null, then all
 * questions matched by $join will be loaded.
 * @param string $extrafields extra SQL code to be added to the query.
 * @param string $join extra SQL code to be added to the query.
 * @param array $extraparams values for any placeholders in $join.
 * You must use named placeholders.
 * @param string $orderby what to order the results by. Optional, default is unspecified order.
 *
 * @return array partially complete question objects. You need to call get_question_options
 * on them before they can be properly used.
 */
function question_preload_questions($questionids = null, $extrafields = '', $join = '', $extraparams = [], $orderby = ''): array {
    global $DB;

    if ($questionids === null) {
        $extracondition = '';
        $params = [];
    } else {
        if (empty($questionids)) {
            return [];
        }

        list($questionidcondition, $params) = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'qid0000');
        $extracondition = 'WHERE q.id ' . $questionidcondition;
    }

    if ($join) {
        $join = 'JOIN ' . $join;
    }

    if ($extrafields) {
        $extrafields = ', ' . $extrafields;
    }

    if ($orderby) {
        $orderby = 'ORDER BY ' . $orderby;
    }

    $sql = "SELECT q.*,
                   qc.id as category,
                   qv.status,
                   qv.id as versionid,
                   qv.version,
                   qv.questionbankentryid,
                   qc.contextid as contextid
                   {$extrafields}
              FROM {question} q
              JOIN {question_versions} qv
                ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe
                ON qbe.id = qv.questionbankentryid
              JOIN {question_categories} qc
                ON qc.id = qbe.questioncategoryid
              {$join}
              {$extracondition}
              {$orderby}";

    // Load the questions.
    $questions = $DB->get_records_sql($sql, $extraparams + $params);
    foreach ($questions as $question) {
        $question->_partiallyloaded = true;
    }

    return $questions;
}

/**
 * Load a set of questions, given a list of ids. The $join and $extrafields arguments can be used
 * together to pull in extra data. See, for example, the usage in mod/quiz/attempt.php, and
 * read the code below to see how the SQL is assembled. Throws exceptions on error.
 *
 * @param array $questionids array of question ids.
 * @param string $extrafields extra SQL code to be added to the query.
 * @param string $join extra SQL code to be added to the query.
 * @return array|string question objects.
 */
function question_load_questions($questionids, $extrafields = '', $join = '') {
    $questions = question_preload_questions($questionids, $extrafields, $join);

    // Load the question type specific information.
    if (!get_question_options($questions)) {
        return get_string('questionloaderror', 'question');
    }

    return $questions;
}

/**
 * Private function to factor common code out of get_question_options().
 *
 * @param object $question the question to tidy.
 * @param stdClass $category The question_categories record for the given $question.
 * @param \core_tag_tag[]|null $tagobjects The tags for the given $question.
 * @param stdClass[]|null $filtercourses deprecated argument and should not be used
 */
function _tidy_question($question, $category, ?array $tagobjects = null, ?array $filtercourses = null): void {

    if ($filtercourses !== null) {
        debugging("Filtercourses is a deprecated argument in " . __FUNCTION__, DEBUG_DEVELOPER);
    }

    // Convert numeric fields to float. This prevents these being displayed as 1.0000000.
    $question->defaultmark += 0;
    $question->penalty += 0;

    // Indicate the question is now fully initialised.
    if (isset($question->_partiallyloaded)) {
        unset($question->_partiallyloaded);
    }

    $question->categoryobject = $category;

    // Add any tags we have been passed.
    if (!is_null($tagobjects)) {
        $categorycontext = context::instance_by_id($category->contextid);
        $sortedtagobjects = question_sort_tags($tagobjects, $categorycontext, $filtercourses);
        $question->tagobjects = $sortedtagobjects->tagobjects;
        $question->tags = $sortedtagobjects->tags;
    }

    // Load question-type specific fields.
    if (question_bank::is_qtype_installed($question->qtype)) {
        question_bank::get_qtype($question->qtype)->get_question_options($question);
    } else {
        $question->questiontext = html_writer::tag('p', get_string('warningmissingtype',
                'qtype_missingtype')) . $question->questiontext;
    }
}

/**
 * Updates the question objects with question type specific
 * information by calling {@see get_question_options()}
 *
 * Can be called either with an array of question objects or with a single
 * question object.
 *
 * @param mixed $questions Either an array of question objects to be updated
 *         or just a single question object
 * @param bool $loadtags load the question tags from the tags table. Optional, default false.
 * @param stdClass[] $filtercourses deprecated argument and should not be used
 * @return bool Indicates success or failure.
 */
function get_question_options(&$questions, $loadtags = false, $filtercourses = null) {
    global $DB;

    if ($filtercourses !== null) {
        debugging("Filtercourses is a deprecated argument in " . __FUNCTION__, DEBUG_DEVELOPER);
    }

    $questionlist = is_array($questions) ? $questions : [$questions];
    $categoryids = [];
    $questionids = [];

    if (empty($questionlist)) {
        return true;
    }

    foreach ($questionlist as $question) {
        $questionids[] = $question->id;
        if (isset($question->category)) {
            $qcategoryid = $question->category;
        } else {
            $qcategoryid = get_question_bank_entry($question->id)->questioncategoryid;
            $question->questioncategoryid = $qcategoryid;
        }

        if (!in_array($qcategoryid, $categoryids)) {
            $categoryids[] = $qcategoryid;
        }
    }

    $categories = $DB->get_records_list('question_categories', 'id', $categoryids);

    if ($loadtags && core_tag_tag::is_enabled('core_question', 'question')) {
        $tagobjectsbyquestion = core_tag_tag::get_items_tags('core_question', 'question', $questionids);
    } else {
        $tagobjectsbyquestion = null;
    }

    foreach ($questionlist as $question) {
        if (is_null($tagobjectsbyquestion)) {
            $tagobjects = null;
        } else {
            $tagobjects = $tagobjectsbyquestion[$question->id];
        }
        $qcategoryid = $question->category ?? $question->questioncategoryid ??
            get_question_bank_entry($question->id)->questioncategoryid;

        _tidy_question($question, $categories[$qcategoryid], $tagobjects, $filtercourses);
    }

    return true;
}

/**
 * Sort question tags by course or normal tags.
 *
 * This function also search tag instances that may have a context id that don't match either a course or
 * question context and fix the data setting the correct context id.
 *
 * @param \core_tag_tag[] $tagobjects The tags for the given $question.
 * @param stdClass $categorycontext The question categories context.
 * @param stdClass[]|null $filtercourses deprecated argument and should not be used.
 * @return stdClass $sortedtagobjects Sorted tag objects.
 */
function question_sort_tags($tagobjects, $categorycontext, $filtercourses = null): stdClass {

    if ($filtercourses !== null) {
        debugging("Filtercourses is a deprecated argument in " . __FUNCTION__, DEBUG_DEVELOPER);
    }

    $sortedtagobjects = new stdClass();
    $sortedtagobjects->tagobjects = [];
    $sortedtagobjects->tags = [];
    $taginstanceidstonormalise = [];

    foreach ($tagobjects as $tagobject) {
        $tagcontextid = $tagobject->taginstancecontextid;
        $tagcontext = context::instance_by_id($tagcontextid);
            // All tag instances belong to the context that the question was created in.
            $sortedtagobjects->tagobjects[] = $tagobject;
            $sortedtagobjects->tags[$tagobject->id] = $tagobject->get_display_name();

            // Due to legacy tag implementations that don't force the recording
            // of a context id, some tag instances may have context ids that don't
            // match either a course context or the question context. In this case
            // we should take the opportunity to fix up the data and set the correct
            // context id.
            if ($tagcontext->id != $categorycontext->id) {
                $taginstanceidstonormalise[] = $tagobject->taginstanceid;
                // Update the object properties to reflect the DB update that will
                // happen below.
                $tagobject->taginstancecontextid = $categorycontext->id;
            }

    }

    if (!empty($taginstanceidstonormalise)) {
        // If we found any tag instances with incorrect context id data then we can
        // correct those values now by setting them to the question context id.
        core_tag_tag::change_instances_context($taginstanceidstonormalise, $categorycontext);
    }

    return $sortedtagobjects;
}

/**
 * Print the icon for the question type
 *
 * @param object $question The question object for which the icon is required.
 *       Only $question->qtype is used.
 * @return string the HTML for the img tag.
 */
function print_question_icon($question): string {
    global $PAGE;

    if (gettype($question->qtype) == 'object') {
        $qtype = $question->qtype->name();
    } else {
        // Assume string.
        $qtype = $question->qtype;
    }

    return $PAGE->get_renderer('question', 'bank')->qtype_icon($qtype);
}

// CATEGORY FUNCTIONS.

/**
 * Returns the categories with their names ordered following parent-child relationships.
 * finally it tries to return pending categories (those being orphaned, whose parent is
 * incorrect) to avoid missing any category from original array.
 *
 * @param array $categories
 * @param int $id
 * @param int $level
 * @return array
 */
function sort_categories_by_tree(&$categories, $id = 0, $level = 1): array {
    global $DB;

    $children = [];
    $keys = array_keys($categories);

    foreach ($keys as $key) {
        if (!isset($categories[$key]->processed) && $categories[$key]->parent == $id) {
            $children[$key] = $categories[$key];
            $categories[$key]->processed = true;
            $children = $children + sort_categories_by_tree(
                    $categories, $children[$key]->id, $level + 1);
        }
    }
    // If level = 1, we have finished, try to look for non processed categories (bad parent) and sort them too.
    if ($level == 1) {
        foreach ($keys as $key) {
            // If not processed and it's a good candidate to start (because its parent doesn't exist in the course).
            if (!isset($categories[$key]->processed) && !$DB->record_exists('question_categories',
                    array('contextid' => $categories[$key]->contextid,
                            'id' => $categories[$key]->parent))) {
                $children[$key] = $categories[$key];
                $categories[$key]->processed = true;
                $children = $children + sort_categories_by_tree(
                        $categories, $children[$key]->id, $level + 1);
            }
        }
    }
    return $children;
}

/**
 * Get the default category for the context. Optionally create one if it does not exist.
 *
 * @param int $contextid a context id.
 * @param bool $createifnotexists create the default catagory if it does not exist.
 * @return stdClass|bool the default question category for that context, or false if none.
 */
function question_get_default_category($contextid, bool $createifnotexists = false) {
    global $DB;

    $context = \core\context::instance_by_id($contextid);
    if ($context->contextlevel !== CONTEXT_MODULE) {
        debugging(
            "Invalid context level {$context->contextlevel} for default category. Please use CONTEXT_MODULE",
            DEBUG_DEVELOPER
        );
        return false;
    }

    $defaultcats = $DB->get_records_select('question_categories', 'contextid = ? AND parent <> 0', [$contextid], 'id', '*', 0, 1);

    $defaultcat = reset($defaultcats);

    if (empty($defaultcat) && $createifnotexists) {

        // We need to make a top category first if it doesn't exist.
        $topcategory = question_get_top_category($context->id, true);

        // We don't have one, so we need to make one.
        $defaultcat = new stdClass();
        $contextname = $context->get_context_name(false, true);
        // Max length of name field is 255.
        $defaultcat->name = shorten_text(get_string('defaultfor', 'question', $contextname), 255);
        $defaultcat->info = get_string('defaultinfofor', 'question', $contextname);
        $defaultcat->contextid = $context->id;
        $defaultcat->parent = $topcategory->id;
        // By default, all categories get this number, and are sorted alphabetically.
        $defaultcat->sortorder = 999;
        $defaultcat->stamp = make_unique_id_code();
        $defaultcat->id = $DB->insert_record('question_categories', $defaultcat);
    }

    return $defaultcat;
}

/**
 * Gets the top category in the given context.
 * This function can optionally create the top category if it doesn't exist.
 *
 * @param int $contextid A context id.
 * @param bool $create Whether create a top category if it doesn't exist.
 * @return bool|stdClass The top question category for that context, or false if none.
 */
function question_get_top_category($contextid, $create = false) {
    global $DB;
    $category = $DB->get_record('question_categories', ['contextid' => $contextid, 'parent' => 0]);

    $context = context::instance_by_id($contextid);
    if ($context->contextlevel !== CONTEXT_MODULE) {
        debugging(
            "Invalid context level: {$context->contextlevel} for question_get_top_category, must be CONTEXT_MODULE",
            DEBUG_DEVELOPER
        );
        return false;
    }

    if (!$category && $create) {
        // We need to make one.
        $category = new stdClass();
        $category->name = 'top'; // A non-real name for the top category. It will be localised at the display time.
        $category->info = '';
        $category->contextid = $contextid;
        $category->parent = 0;
        $category->sortorder = 0;
        $category->stamp = make_unique_id_code();
        $category->id = $DB->insert_record('question_categories', $category);
    }

    return $category;
}

/**
 * Gets the list of top categories in the given contexts in the array("categoryid,categorycontextid") format.
 *
 * @param array $contextids List of context ids
 * @return array
 */
function question_get_top_categories_for_contexts($contextids): array {
    global $DB;

    $concatsql = $DB->sql_concat_join("','", ['id', 'contextid']);
    list($insql, $params) = $DB->get_in_or_equal($contextids);
    $sql = "SELECT $concatsql
              FROM {question_categories}
             WHERE contextid $insql
               AND parent = 0";

    $topcategories = $DB->get_fieldset_sql($sql, $params);

    return $topcategories;
}

/**
 * Get the list of categories.
 *
 * @param int $categoryid
 * @return array of question category ids of the category and all subcategories.
 */
function question_categorylist($categoryid): array {
    global $DB;

    // Final list of category IDs.
    $categorylist = [];

    // A list of category IDs to check for any sub-categories.
    $subcategories = [$categoryid];
    $contextid = $DB->get_field('question_categories', 'contextid', ['id' => $categoryid]);

    while ($subcategories) {
        foreach ($subcategories as $subcategory) {
            // If anything from the temporary list was added already, then we have a loop.
            if (isset($categorylist[$subcategory])) {
                throw new coding_exception("Category id=$subcategory is already on the list - loop of categories detected.");
            }
            $categorylist[$subcategory] = $subcategory;
        }

        [$in, $params] = $DB->get_in_or_equal($subcategories);
        $params[] = $contextid;

        // Order by id is not strictly needed, but it will be cheap, and makes the results deterministic.
        $subcategories = $DB->get_records_select_menu('question_categories',
                "parent $in AND contextid = ?", $params, 'id', 'id,id AS id2');
    }

    return $categorylist;
}

/**
 * Get all parent categories of a given question category in descending order.
 *
 * @param int $categoryid for which you want to find the parents.
 * @return array of question category ids of all parents categories.
 */
function question_categorylist_parents(int $categoryid): array {
    global $DB;

    $category = $DB->get_record('question_categories', ['id' => $categoryid]);
    $contextid = $category->contextid;

    $categorylist = [];
    while ($category->parent) {
        $category = $DB->get_record('question_categories', ['id' => $category->parent]);
        if (!$category || $category->contextid != $contextid) {
            break;
        }
        $categorylist[] = $category->id;
    }

    // Present the list in descending order (the top category at the top).
    return array_reverse($categorylist);
}

// Import/Export Functions.

/**
 * Get list of available import or export formats
 * @param string $type 'import' if import list, otherwise export list assumed
 * @return array sorted list of import/export formats available
 */
function get_import_export_formats($type): array {
    global $CFG;
    require_once($CFG->dirroot . '/question/format.php');

    $formatclasses = core_component::get_plugin_list_with_class('qformat', '', 'format.php');

    $fileformatname = array();
    foreach ($formatclasses as $component => $formatclass) {

        $format = new $formatclass();
        if ($type == 'import') {
            $provided = $format->provide_import();
        } else {
            $provided = $format->provide_export();
        }

        if ($provided) {
            list($notused, $fileformat) = explode('_', $component, 2);
            $fileformatnames[$fileformat] = get_string('pluginname', $component);
        }
    }

    core_collator::asort($fileformatnames);
    return $fileformatnames;
}


/**
 * Create a reasonable default file name for exporting questions from a particular
 * category.
 * @param object $course the course the questions are in.
 * @param object $category the question category.
 * @return string the filename.
 */
function question_default_export_filename($course, $category): string {
    // We build a string that is an appropriate name (questions) from the lang pack,
    // then the corse shortname, then the question category name, then a timestamp.

    $base = clean_filename(get_string('exportfilename', 'question'));

    $dateformat = str_replace(' ', '_', get_string('exportnameformat', 'question'));
    $timestamp = clean_filename(userdate(time(), $dateformat, 99, false));

    $shortname = clean_filename($course->shortname);
    if ($shortname == '' || $shortname == '_' ) {
        $shortname = $course->id;
    }

    $categoryname = clean_filename(format_string($category->name));

    return "{$base}-{$shortname}-{$categoryname}-{$timestamp}";
}

/**
 * Check capability on category.
 *
 * @param int|stdClass|question_definition $questionorid object or id.
 *      If an object is passed, it should include ->contextid and ->createdby.
 * @param string $cap 'add', 'edit', 'view', 'use', 'move' or 'tag'.
 * @param int $notused no longer used.
 * @return bool this user has the capability $cap for this question $question?
 */
function question_has_capability_on($questionorid, $cap, $notused = -1): bool {
    global $USER, $DB;

    if (is_numeric($questionorid)) {
        $questionid = (int)$questionorid;
    } else if (is_object($questionorid)) {
        // All we really need in this function is the contextid and author of the question.
        // We won't bother fetching other details of the question if these 2 fields are provided.
        if (isset($questionorid->contextid) && property_exists($questionorid, 'createdby')) {
            $question = $questionorid;
        } else if (!empty($questionorid->id)) {
            $questionid = $questionorid->id;
        }
    }

    // At this point, either $question or $questionid is expected to be set.
    if (isset($questionid)) {
        try {
            $question = question_bank::load_question_data($questionid);
        } catch (Exception $e) {
            // Let's log the exception for future debugging,
            // but not during Behat, or we can't test these cases.
            if (!defined('BEHAT_SITE_RUNNING')) {
                debugging($e->getMessage(), DEBUG_NORMAL, $e->getTrace());
            }

            $sql = 'SELECT q.id,
                           q.createdby,
                           qc.contextid
                      FROM {question} q
                      JOIN {question_versions} qv
                        ON qv.questionid = q.id
                      JOIN {question_bank_entries} qbe
                        ON qbe.id = qv.questionbankentryid
                      JOIN {question_categories} qc
                        ON qc.id = qbe.questioncategoryid
                     WHERE q.id = :id';

            // Well, at least we tried. Seems that we really have to read from DB.
            $question = $DB->get_record_sql($sql, ['id' => $questionid], MUST_EXIST);
        }
    }

    if (!isset($question)) {
        throw new coding_exception('$questionorid parameter needs to be an integer or an object.');
    }

    $context = context::instance_by_id($question->contextid);

    // These are existing questions capabilities that are set per category.
    // Each of these has a 'mine' and 'all' version that is appended to the capability name.
    $capabilitieswithallandmine = ['edit' => 1, 'view' => 1, 'use' => 1, 'move' => 1, 'tag' => 1, 'comment' => 1];

    if (!isset($capabilitieswithallandmine[$cap])) {
        return has_capability('moodle/question:' . $cap, $context);
    } else {
        return has_capability('moodle/question:' . $cap . 'all', $context) ||
            ($question->createdby == $USER->id && has_capability('moodle/question:' . $cap . 'mine', $context));
    }
}

/**
 * Require capability on question.
 *
 * @param int|stdClass|question_definition $question object or id.
 *      If an object is passed, it should include ->contextid and ->createdby.
 * @param string $cap 'add', 'edit', 'view', 'use', 'move' or 'tag'.
 * @return bool true if the user has the capability. Throws exception if not.
 */
function question_require_capability_on($question, $cap): bool {
    if (!question_has_capability_on($question, $cap)) {
        throw new moodle_exception('nopermissions', '', '', $cap);
    }
    return true;
}

/**
 * Gets the question edit url.
 *
 * @param object $context a context
 * @return string|bool|void A URL for editing questions in this context.
 */
function question_edit_url($context) {
    global $CFG, $SITE;
    if (!has_any_capability(question_get_question_capabilities(), $context)) {
        return false;
    }

    if ($context->contextlevel !== CONTEXT_MODULE) {
        debugging(
            "Invalid contextlevel: {$context->contextlevel} provided for question_edit_url, must be CONTEXT_MODULE",
            DEBUG_DEVELOPER
        );
        return false;
    }

    $baseurl = $CFG->wwwroot . '/question/edit.php?';
    $defaultcategory = question_get_default_category($context->id, true);
    if ($defaultcategory) {
        $baseurl .= 'cat=' . $defaultcategory->id . ',' . $context->id . '&amp;';
    }
    switch ($context->contextlevel) {
        case CONTEXT_SYSTEM:
            return $baseurl . 'courseid=' . $SITE->id;
        case CONTEXT_COURSECAT:
            // This is nasty, becuase we can only edit questions in a course
            // context at the moment, so for now we just return false.
            return false;
        case CONTEXT_COURSE:
            return $baseurl . 'courseid=' . $context->instanceid;
        case CONTEXT_MODULE:
            return $baseurl . 'cmid=' . $context->instanceid;
    }

    return $baseurl . 'cmid=' . $context->instanceid;
}

/**
 * Adds question bank setting links to the given navigation node if caps are met
 * and loads the navigation from the plugins.
 * Qbank plugins can extend the navigation_plugin_base and add their own navigation node,
 * this method will help to autoload those nodes in the question bank navigation.
 *
 * @param navigation_node $navigationnode The navigation node to add the question branch to
 * @param object $context
 * @param string $baseurl the url of the base where the api is implemented from
 * @return navigation_node Returns the question branch that was added
 */
function question_extend_settings_navigation(navigation_node $navigationnode, $context, $baseurl = '/question/edit.php') {
    global $PAGE;

    $iscourse = $context->contextlevel === CONTEXT_COURSE;

    if ($iscourse) {
        $params = ['courseid' => $context->instanceid];
    } else if ($context->contextlevel == CONTEXT_MODULE) {
        $params = ['cmid' => $context->instanceid];
    } else {
        return;
    }

    if (($cat = $PAGE->url->param('cat')) && preg_match('~\d+,\d+~', $cat)) {
        $params['cat'] = $cat;
    }

    $questionnode = $navigationnode->add(get_string($iscourse ? 'questionbank_plural' : 'questionbank', 'question'),
            new moodle_url($baseurl, $params), navigation_node::TYPE_CONTAINER, null, 'questionbank');

    $corenavigations = [
            'questions' => [
                    'title' => get_string('questions', 'question'),
                    'url' => new moodle_url($baseurl)
            ],
            'categories' => [],
            'import' => [],
            'export' => []
    ];

    $plugins = \core_component::get_plugin_list_with_class('qbank', 'plugin_feature', 'plugin_feature.php');
    foreach ($plugins as $componentname => $plugin) {
        $pluginentrypoint = new $plugin();
        $pluginentrypointobject = $pluginentrypoint->get_navigation_node();
        // Don't need the plugins without navigation node.
        if ($pluginentrypointobject === null) {
            unset($plugins[$componentname]);
            continue;
        }
        foreach ($corenavigations as $key => $corenavigation) {
            if ($pluginentrypointobject->get_navigation_key() === $key) {
                unset($plugins[$componentname]);
                if (!\core\plugininfo\qbank::is_plugin_enabled($componentname)) {
                    unset($corenavigations[$key]);
                    break;
                }
                $corenavigations[$key] = [
                    'title' => $pluginentrypointobject->get_navigation_title(),
                    'url'   => $pluginentrypointobject->get_navigation_url()
                ];
            }
        }
    }

    // Mitigate the risk of regression.
    foreach ($corenavigations as $node => $corenavigation) {
        if (empty($corenavigation)) {
            unset($corenavigations[$node]);
        }
    }

    // Community/additional plugins have navigation node.
    $pluginnavigations = [];
    foreach ($plugins as $componentname => $plugin) {
        $pluginentrypoint = new $plugin();
        $pluginentrypointobject = $pluginentrypoint->get_navigation_node();
        // Don't need the plugins without navigation node.
        if ($pluginentrypointobject === null || !\core\plugininfo\qbank::is_plugin_enabled($componentname)) {
            unset($plugins[$componentname]);
            continue;
        }
        $pluginnavigations[$pluginentrypointobject->get_navigation_key()] = [
            'title' => $pluginentrypointobject->get_navigation_title(),
            'url'   => $pluginentrypointobject->get_navigation_url(),
            'capabilities' => $pluginentrypointobject->get_navigation_capabilities()
        ];
    }

    $contexts = new core_question\local\bank\question_edit_contexts($context);
    foreach ($corenavigations as $key => $corenavigation) {
        if ($contexts->have_one_edit_tab_cap($key)) {
            $questionnode->add($corenavigation['title'], new moodle_url(
                    $corenavigation['url'], $params), navigation_node::TYPE_SETTING, null, $key);
        }
    }

    foreach ($pluginnavigations as $key => $pluginnavigation) {
        if (is_array($pluginnavigation['capabilities'])) {
            if (!$contexts->have_one_cap($pluginnavigation['capabilities'])) {
                continue;
            }
        }
        $questionnode->add($pluginnavigation['title'], new moodle_url(
                $pluginnavigation['url'], $params), navigation_node::TYPE_SETTING, null, $key);
    }

    return $questionnode;
}

/**
 * Get the array of capabilities for question.
 *
 * @return array all the capabilities that relate to accessing particular questions.
 */
function question_get_question_capabilities(): array {
    return [
        'moodle/question:add',
        'moodle/question:editmine',
        'moodle/question:editall',
        'moodle/question:viewmine',
        'moodle/question:viewall',
        'moodle/question:usemine',
        'moodle/question:useall',
        'moodle/question:movemine',
        'moodle/question:moveall',
        'moodle/question:tagmine',
        'moodle/question:tagall',
        'moodle/question:commentmine',
        'moodle/question:commentall',
    ];
}

/**
 * Get the question bank caps.
 *
 * @return array all the question bank capabilities.
 */
function question_get_all_capabilities(): array {
    $caps = question_get_question_capabilities();
    $caps[] = 'moodle/question:managecategory';
    $caps[] = 'moodle/question:flag';
    return $caps;
}

/**
 * Helps call file_rewrite_pluginfile_urls with the right parameters.
 *
 * @package  core_question
 * @category files
 * @param string $text text being processed
 * @param string $file the php script used to serve files
 * @param int $contextid context ID
 * @param string $component component
 * @param string $filearea filearea
 * @param array $ids other IDs will be used to check file permission
 * @param int $itemid item ID
 * @param array $options options
 * @return string
 */
function question_rewrite_question_urls($text, $file, $contextid, $component, $filearea,
                                        array $ids, $itemid, ?array $options=null): string {

    $idsstr = '';
    if (!empty($ids)) {
        $idsstr .= implode('/', $ids);
    }
    if ($itemid !== null) {
        $idsstr .= '/' . $itemid;
    }
    return file_rewrite_pluginfile_urls($text, $file, $contextid, $component,
            $filearea, $idsstr, $options);
}

/**
 * Rewrite the PLUGINFILE urls in part of the content of a question, for use when
 * viewing the question outside an attempt (for example, in the question bank
 * listing or in the quiz statistics report).
 *
 * @param string $text the question text.
 * @param int $questionid the question id.
 * @param int $filecontextid the context id of the question being displayed.
 * @param string $filecomponent the component that owns the file area.
 * @param string $filearea the file area name.
 * @param int|null $itemid the file's itemid
 * @param int $previewcontextid the context id where the preview is being displayed.
 * @param string $previewcomponent component responsible for displaying the preview.
 * @param array $options text and file options ('forcehttps'=>false)
 * @return string $questiontext with URLs rewritten.
 */
function question_rewrite_question_preview_urls($text, $questionid, $filecontextid, $filecomponent, $filearea, $itemid,
                                                $previewcontextid, $previewcomponent, $options = null): string {

    $path = "preview/$previewcontextid/$previewcomponent/$questionid";
    if ($itemid) {
        $path .= '/' . $itemid;
    }

    return file_rewrite_pluginfile_urls($text, 'pluginfile.php', $filecontextid,
            $filecomponent, $filearea, $path, $options);
}

/**
 * Called by pluginfile.php to serve files related to the 'question' core
 * component and for files belonging to qtypes.
 *
 * For files that relate to questions in a question_attempt, then we delegate to
 * a function in the component that owns the attempt (for example in the quiz,
 * or in core question preview) to get necessary inforation.
 *
 * (Note that, at the moment, all question file areas relate to questions in
 * attempts, so the If at the start of the last paragraph is always true.)
 *
 * Does not return, either calls send_file_not_found(); or serves the file.
 *
 * @category files
 * @param stdClass $course course settings object
 * @param stdClass $context context object
 * @param string $component the name of the component we are serving files for.
 * @param string $filearea the name of the file area.
 * @param array $args the remaining bits of the file path.
 * @param bool $forcedownload whether the user must be forced to download the file.
 * @param array $options additional options affecting the file serving
 * @return array|bool
 */
function question_pluginfile($course, $context, $component, $filearea, $args, $forcedownload, $options = []) {
    global $DB, $CFG;

    // Special case, sending a question bank export.
    if ($filearea === 'export') {
        list($context, $course, $cm) = get_context_info_array($context->id);
        require_login($course, false, $cm);

        require_once($CFG->dirroot . '/question/editlib.php');
        $contexts = new core_question\local\bank\question_edit_contexts($context);
        // Check export capability.
        $contexts->require_one_edit_tab_cap('export');
        $categoryid = (int)array_shift($args);
        $format      = array_shift($args);
        $cattofile   = array_shift($args);
        $contexttofile = array_shift($args);
        $filename    = array_shift($args);

        // Load parent class for import/export.
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/editlib.php');
        require_once($CFG->dirroot . '/question/format/' . $format . '/format.php');

        $classname = 'qformat_' . $format;
        if (!class_exists($classname)) {
            send_file_not_found();
        }

        $qformat = new $classname();

        if (!$category = $DB->get_record('question_categories', array('id' => $categoryid))) {
            send_file_not_found();
        }

        $qformat->setCategory($category);
        $qformat->setContexts($contexts->having_one_edit_tab_cap('export'));
        $qformat->setCourse($course);

        if ($cattofile == 'withcategories') {
            $qformat->setCattofile(true);
        } else {
            $qformat->setCattofile(false);
        }

        if ($contexttofile == 'withcontexts') {
            $qformat->setContexttofile(true);
        } else {
            $qformat->setContexttofile(false);
        }

        if (!$qformat->exportpreprocess()) {
            send_file_not_found();
            throw new moodle_exception('exporterror', 'question', $thispageurl->out());
        }

        // Export data to moodle file pool.
        if (!$content = $qformat->exportprocess()) {
            send_file_not_found();
        }

        send_file($content, $filename, 0, 0, true, true, $qformat->mime_type());
    }

    // Normal case, a file belonging to a question.
    $qubaidorpreview = array_shift($args);

    // Two sub-cases: 1. A question being previewed outside an attempt/usage.
    if ($qubaidorpreview === 'preview') {
        $previewcontextid = (int)array_shift($args);
        $previewcomponent = array_shift($args);
        $questionid = (int) array_shift($args);
        $previewcontext = context_helper::instance_by_id($previewcontextid);

        $result = component_callback($previewcomponent, 'question_preview_pluginfile', array(
                $previewcontext, $questionid,
                $context, $component, $filearea, $args,
                $forcedownload, $options), 'callbackmissing');

        if ($result === 'callbackmissing') {
            throw new coding_exception("Component {$previewcomponent} does not define the callback " .
                    "{$previewcomponent}_question_preview_pluginfile callback. " .
                    "Which is required if you are using question_rewrite_question_preview_urls.", DEBUG_DEVELOPER);
        }

        send_file_not_found();
    }

    // 2. A question being attempted in the normal way.
    $qubaid = (int)$qubaidorpreview;
    $slot = (int)array_shift($args);

    $module = $DB->get_field('question_usages', 'component',
            array('id' => $qubaid));
    if (!$module) {
        send_file_not_found();
    }

    if ($module === 'core_question_preview') {
        return qbank_previewquestion\helper::question_preview_question_pluginfile($course, $context,
                $component, $filearea, $qubaid, $slot, $args, $forcedownload, $options);

    } else {
        $dir = core_component::get_component_directory($module);
        if (!file_exists("$dir/lib.php")) {
            send_file_not_found();
        }
        include_once("$dir/lib.php");

        $filefunction = $module . '_question_pluginfile';
        if (function_exists($filefunction)) {
            $filefunction($course, $context, $component, $filearea, $qubaid, $slot,
                $args, $forcedownload, $options);
        }

        // Okay, we're here so lets check for function without 'mod_'.
        if (strpos($module, 'mod_') === 0) {
            $filefunctionold  = substr($module, 4) . '_question_pluginfile';
            if (function_exists($filefunctionold)) {
                $filefunctionold($course, $context, $component, $filearea, $qubaid, $slot,
                    $args, $forcedownload, $options);
            }
        }

        send_file_not_found();
    }
}

/**
 * Serve questiontext files in the question text when they are displayed in this report.
 *
 * @param context $previewcontext the context in which the preview is happening.
 * @param int $questionid the question id.
 * @param context $filecontext the file (question) context.
 * @param string $filecomponent the component the file belongs to.
 * @param string $filearea the file area.
 * @param array $args remaining file args.
 * @param bool $forcedownload
 * @param array $options additional options affecting the file serving.
 */
function core_question_question_preview_pluginfile($previewcontext, $questionid, $filecontext, $filecomponent,
                                                    $filearea, $args, $forcedownload, $options = []): void {
    global $DB;
    $sql = 'SELECT q.*,
                   qc.contextid
              FROM {question} q
              JOIN {question_versions} qv
                ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe
                ON qbe.id = qv.questionbankentryid
              JOIN {question_categories} qc
                ON qc.id = qbe.questioncategoryid
             WHERE q.id = :id
               AND qc.contextid = :contextid';

    // Verify that contextid matches the question.
    $question = $DB->get_record_sql($sql, ['id' => $questionid, 'contextid' => $filecontext->id], MUST_EXIST);

    // Check the capability.
    list($context, $course, $cm) = get_context_info_array($previewcontext->id);
    require_login($course, false, $cm);

    question_require_capability_on($question, 'use');

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/{$filecontext->id}/{$filecomponent}/{$filearea}/{$relativepath}";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 * @return array
 */
function question_page_type_list($pagetype, $parentcontext, $currentcontext): array {
    global $CFG;
    $types = [
        'question-*' => get_string('page-question-x', 'question'),
        'question-edit' => get_string('page-question-edit', 'question'),
        'question-category' => get_string('page-question-category', 'question'),
        'question-export' => get_string('page-question-export', 'question'),
        'question-import' => get_string('page-question-import', 'question')
    ];
    if ($currentcontext->contextlevel == CONTEXT_COURSE) {
        require_once($CFG->dirroot . '/course/lib.php');
        return array_merge(course_page_type_list($pagetype, $parentcontext, $currentcontext), $types);
    } else {
        return $types;
    }
}

/**
 * Does an activity module use the question bank?
 *
 * @param string $modname The name of the module (without mod_ prefix).
 * @return bool true if the module uses questions.
 */
function question_module_uses_questions($modname): bool {
    if (plugin_supports('mod', $modname, FEATURE_USES_QUESTIONS)) {
        return true;
    }

    $component = 'mod_'.$modname;
    if (component_callback_exists($component, 'question_pluginfile')) {
        debugging("{$component} uses questions but doesn't declare FEATURE_USES_QUESTIONS", DEBUG_DEVELOPER);
        return true;
    }

    return false;
}

/**
 * If $oldidnumber ends in some digits then return the next available idnumber of the same form.
 *
 * So idnum -> null (no digits at the end) idnum0099 -> idnum0100 (if that is unused,
 * else whichever of idnum0101, idnume0102, ... is unused. idnum9 -> idnum10.
 *
 * @param string|null $oldidnumber a question idnumber, or can be null.
 * @param int $categoryid a question category id.
 * @return string|null suggested new idnumber for a question in that category, or null if one cannot be found.
 */
function core_question_find_next_unused_idnumber(?string $oldidnumber, int $categoryid): ?string {
    global $DB;

    // The the old idnumber is not of the right form, bail now.
    if ($oldidnumber === null || !preg_match('~\d+$~', $oldidnumber, $matches)) {
        return null;
    }

    // Find all used idnumbers in one DB query.
    $usedidnumbers = $DB->get_records_select_menu('question_bank_entries', 'questioncategoryid = ? AND idnumber IS NOT NULL',
            [$categoryid], '', 'idnumber, 1');

    // Find the next unused idnumber.
    $numberbit = 'X' . $matches[0]; // Need a string here so PHP does not do '0001' + 1 = 2.
    $stem = substr($oldidnumber, 0, -strlen($matches[0]));
    do {

        // If we have got to something9999, insert an extra digit before incrementing.
        if (preg_match('~^(.*[^0-9])(9+)$~', $numberbit, $matches)) {
            $numberbit = $matches[1] . '0' . $matches[2];
        }
        $numberbit++;
        $newidnumber = $stem . substr($numberbit, 1);
    } while (isset($usedidnumbers[$newidnumber]));

    return (string) $newidnumber;
}

/**
 * Get the question_bank_entry object given a question id.
 *
 * @param int $questionid Question id.
 * @return false|mixed
 * @throws dml_exception
 */
function get_question_bank_entry(int $questionid): object {
    global $DB;

    $sql = "SELECT qbe.*
              FROM {question} q
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
             WHERE q.id = :id";

    $qbankentry = $DB->get_record_sql($sql, ['id' => $questionid]);

    return $qbankentry;
}

/**
 * Get the question versions given a question id in a descending sort .
 *
 * @param int $questionid Question id.
 * @return array
 * @throws dml_exception
 */
function get_question_version($questionid): array {
    global $DB;

    $version = $DB->get_records('question_versions', ['questionid' => $questionid]);
    krsort($version);

    return $version;
}

/**
 * Get the next version number to create base on a Question bank entry id.
 *
 * @param int $questionbankentryid Question bank entry id.
 * @return int next version number.
 * @throws dml_exception
 */
function get_next_version(int $questionbankentryid): int {
    global $DB;

    $sql = "SELECT MAX(qv.version)
              FROM {question_versions} qv
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
             WHERE qbe.id = :id";

    $nextversion = $DB->get_field_sql($sql, ['id' => $questionbankentryid]);

    if ($nextversion) {
        return (int)$nextversion + 1;
    }

    return 1;
}

/**
 * Checks if question is the latest version.
 *
 * @param string $version Question version to check.
 * @param string $questionbankentryid Entry to check against.
 * @return bool
 */
function is_latest(string $version, string $questionbankentryid): bool {
    global $DB;

    $sql = 'SELECT MAX(version) AS max
                  FROM {question_versions}
                 WHERE questionbankentryid = ?';
    $latestversion = $DB->get_record_sql($sql, [$questionbankentryid]);

    if (isset($latestversion->max)) {
        return ($version === $latestversion->max) ? true : false;
    }
    return false;
}

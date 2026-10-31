# Changes

## 1.9.0 (2026102602) — parts B and C of the round trip: the full backup before a discard, and the safe update

- **Safe update of a course in place** (`local_miwlab_preview_update` and `local_miwlab_apply_update`, feature `safeupdate`, capability
  `local/miwlab:updatecourse`, in the course, manager archetype). The MiwLab lists operations (activities that changed, new ones,
  removed ones, sections, order, and what is course-wide); the plugin sorts each one against the course AS IT IS and the progress of
  each activity (a column per activity, counts only): **updated** (name, text, completion, access restriction, visibility, dates,
  maximum grade, in the activities nobody has progress in or where the change cannot hurt), **added or hidden** (a new activity or
  section comes in a package of its own, restored by Moodle only adding; what the MiwLab removed is hidden, never deleted) or **left
  out**, with the reason (`progress`, `unknowntype`, `newfile`, `persondata`, `deleting`, `sectionzero`...). The preview changes nothing,
  lives 30 minutes, belongs to the account that made it and the course it was made for, and is applied once. The application does
  EXACTLY what the preview showed: it makes the backup without people first, checks again, per activity, the progress of what it is going
  to change (somebody who did the activity between the preview and the application turns that item into "left out", reason
  `progresschanged`) and, if anything fails in the middle, puts back every row it touched and deletes what it created (the signature of
  the course is the same as before). Nothing the people did is touched: grades, submissions, attempts, posts and completion stay
  as they are (the tests compare the people's data before and after).
- **Course-wide items are a choice of whoever sends**, each with "keep" (the default, touches nothing) or "replace": the **blocks**
  of the course page, one by one (add, replace the configuration, remove; a block that holds data of a person, like comments, cannot
  be replaced or removed), the **completion criteria of the course** (one item: with nobody who concluded it replacing loses nothing;
  with someone who concluded it the preview says how many lose the course completion, and replacing needs `forcediscard`, which makes
  the full backup of 1.9.0 first, with the same `keepdays`; the call is refused without it) and the **gradebook of the course** (one
  item: the grades of the activities stay, the final grade is recalculated and the preview says for how many people, and for how many
  a total typed by hand stays).
- `local_miwlab_get_restore_status` returns `updateresult` (what was done, item by item, no person) for the mode `update`.
- New table `local_miwlab_updates` (the previews: operations and what each would do, counts only; deleted with the daily task after a day).
  The privacy statement and `marketplace.md` say it.
- The label name (a label has no name of its own) is derived again from the new text when the text of a label is updated.
- Every Moodle global function, constant and class the new code uses is loaded by the code itself (`simbolos.sh`, issue 202).

- **Full backup before a discard.** `local_miwlab_replace_course` with `forcediscard` now makes, before touching the course, a FULL
  backup of it, with its people (enrolments, roles, grades and history, submissions, attempts, posts, completion, badges, groups;
  not the logs), named `miwlab-before-replace-course-<id>-<date>-full.mbz` and kept only in the course backup area (Course >
  Restore). Moodle's own backup makes it, so Moodle's privacy tools reach it like any course backup. If the site does not allow
  those settings on (for example "include users" locked off), or the backup fails, the task ends in failure BEFORE the course is
  touched, with `error:fullbackupnotallowed` or `error:fullbackupfailed`: nothing is replaced and nothing is discarded. Without
  `forcediscard` nothing changes: the backup made before is still the one without people (`-nu`).
- **New optional parameter `keepdays`** (30 to 365, default 180; outside the range the call is refused with
  `error:invalidkeepdays`): the days the plugin keeps the full backup. A new table (`local_miwlab_fullcopies`: course, file name,
  content hash, dates; no person) records each one, and the daily task deletes the ones whose days are over: only the file the
  plugin registered (a file of the same name with another content stays), and when somebody already deleted it only the record goes.
- `local_miwlab_get_restore_status` also returns `backuphaspeople` and `backupexpires`; `local_miwlab_get_info` lists the feature
  `fullbackup`. The MiwLab offers the conscious discard only when it sees this feature.
- **New permission in the "MiwLab" role: `moodle/backup:userinfo`**, only for that full backup (a divergence from the least privilege of
  1.8.0, decided by the product owner so that the discard has a way back). `local_miwlab_replace_course` with `forcediscard` is
  refused when the account lacks it in the course. The upgrade step gives it to the existing role; the same key reaches the function
  with the new parameter.
- **Every other backup the plugin makes is now provably without people.** Moodle locks all the settings of a backup "by permission"
  when the account lacks `moodle/backup:configure` (the role lacks it), keeping the site default, so until 1.8.0 the absence of
  `moodle/backup:userinfo` was what kept the people out. Now the backup made before a replacement WITHOUT discard and the content
  backup (`local_miwlab_backup_course`) set "include users" and the settings that depend on it off explicitly (lifting only that lock
  by permission, never a lock of the site configuration), and check them AFTER setting, user data of each activity and block
  included: if any is still on (the site locked it on), the backup is refused with `error:backupwithpeople` and nothing is replaced.
- Texts, privacy statement and `marketplace.md` updated (English and Portuguese).

## 1.8.0 (2026102503)

- New web service function `local_miwlab_get_courses_overview`: the courses of some categories (and, by default, of the categories
  below them), in batch and paginated (up to 500 courses per call, `limitfrom`/`limitnum`, `next` is -1 on the last page). For each course
  it returns only numbers: the students (active enrolment, with a role of the student archetype in the course or above it, as
  `local_miwlab_get_course_completion` counts them), whether the course measures completion (completion on in the site and in the course
  and at least one tracked activity, or completion criteria), and how many students completed (by the completion of the course when it has
  criteria, otherwise by every tracked activity complete). Teachers, suspended or expired enrolments and deleted accounts never count, and
  the numbers are the same, course by course, as the ones of the read made one course at a time. It needs `local/miwlab:viewreports` in
  the context of each category asked; no new capability. The MiwLab uses it once a day, outside the busy hours, and on demand, to show
  the enrolled people and the completion of the whole Moodle in its management screens. No person leaves Moodle: not even an id.
- The "MiwLab (local_miwlab)" service gets the function on install and on upgrade. A service made by hand needs it added to its functions.
- The subcategories added by the expansion are checked one by one for `local/miwlab:viewreports`: where it is prohibited they stay out and `categoriesleftout` says how many.
- Every function loads the Moodle libraries it uses itself (`completionlib`, `filelib`, `ddllib`, the backup and restore includes): a
  web service request does not load them, and the unit tests, which have everything loaded, do not show it. Checked by calling every
  function of the service through REST in a disposable Moodle.
- The texts of `local/miwlab:replacecourse` and of `local_miwlab_replace_course` say what they do since 1.7.3 (a backup without user
  data first; with progress, only when the call asks to discard it), and the text of `local/miwlab:viewreports` covers the counts of
  the courses of whole categories too. In English and in Portuguese (pt_br).
- Nothing changes in the tables nor in the "MiwLab" role: the upgrade steps 2026102501, 2026102502 and 2026102503 only record the
  version (the third build makes Moodle read the texts again).
- `local_miwlab_get_info` lists the feature `coursesoverview`.

## 1.7.3 (2026102302)

- Optional parameter `forcediscard` in `local_miwlab_replace_course`: allows replacing the content of an existing course even when
  students have progress, discarding existing progress/grades while keeping enrolments, when requested consciously. The automated
  safety backup of the course is still performed before replacing to guarantee safe rollback.

## 1.7.2 (2026102301)

- Replacing the content of a course (`local_miwlab_replace_course`) and checking its replacement status (`local_miwlab_get_replace_check`)
  now allow replacement when there are zero people enrolled (`enrolled === 0`), even if test records or administrative accesses generated progress entries (`withprogress > 0`),
  as there are no enrolled students or learner grades to protect. Courses with 1 or more enrolments continue to block replacement when any progress exists
  to prevent accidental data loss.

## 1.7.1 (2026102201)

- Replacing the content of a course (`local_miwlab_replace_course`) and checking its replacement status (`local_miwlab_get_replace_check`)
  no longer refuses courses with activities of unmapped modules (`unknownmodules`) when there are zero people enrolled (`enrolled === 0`),
  as there is no student work or progress to protect. Courses with 1 or more enrolments continue to require every module to be known to
  prevent accidental data loss.
- Added `board` (`mod_board`) and `coursecertificate` (`mod_coursecertificate`) to `KNOWN_MODULES` in `replacer`.

## 1.7.0 (2026102110)

- New web service function `local_miwlab_get_certificate_templates`: the certificate templates of the site (`tool_certificate`),
  with where each belongs (the whole site or a course category) and whether it is shared with the categories below, and whether
  the Course certificate activity (`mod_coursecertificate`) is installed and enabled. The MiwLab uses it to let the organisation
  choose the template of the Course certificate it sends in a course (a default, one per category, and one per course). It needs
  `local/miwlab:viewmoduletypes` at site level, the capability that already lists the module types; the "MiwLab" role has it.
  Only the id, the name and where the template belongs: no person, no issued certificate.
- New web service function `local_miwlab_get_upload_limits`: the limits that can refuse the package when the MiwLab sends it
  (`upload_max_filesize`, `post_max_size` and the maximum upload size of the site), the smallest one and which setting it comes
  from, and the user quota of the site. The MiwLab uses it to say, before and after a refused upload, which limit barred the
  package and how much it is worth. Same capability.
- The "MiwLab (local_miwlab)" service gets the two functions on install and on upgrade. A service made by hand needs them added
  to its functions.
- `local_miwlab_get_info` lists the features `certificatetemplates` and `uploadlimits`.
- New web service function `local_miwlab_get_forum_topics` and new capability `local/miwlab:readforumtopics`: the opening topic
  (title, text, files) of each discussion that the STAFF of a course started in its forums (a teacher with or without
  editing rights, a manager, a site administrator), so that the MiwLab can send it again with the package. Moodle restores
  a course without the people and, without them, brings back no discussion of a forum (only the one of a single simple
  discussion forum). A discussion started by a student is never read, nor are replies, grades, ratings or names (only the
  role that made the author staff is said). Left out: single simple discussion forums, discussions of a group or with a
  display period, deleted posts, private replies; limits of 50 topics per forum, 500 per course, 10 files of up to 1 MiB
  per topic, 3 MiB of files and 6 MiB of answer in all. The "MiwLab (local_miwlab)" service gets the function.
- Restoring a package (as a new course, or replacing the content of a course) recreates the opening topics the package
  carries (`miwlab/foruns.json` and the files under `miwlab/foruns/`, ignored by Moodle's restore and by older versions of
  the plugin) as the integration account: no notification is sent (the post is born as mailed), nothing is duplicated (a
  topic whose title is already in the forum is left alone), a single simple discussion forum is left to Moodle, the files
  are attached only when their SHA-1 is the one the package names, and a discussion stays pinned when the account may pin.
  A restore never fails because of a topic: `local_miwlab_get_restore_status` returns `topicscreated`, `topicsskipped` and
  `topicsnote` (new columns of `local_miwlab_restores`).
- The "MiwLab" role gets `local/miwlab:readforumtopics` and, to recreate the topics in the restored course,
  `mod/forum:startdiscussion`, `mod/forum:addnews` (the announcements forum), `mod/forum:createattachment` and
  `mod/forum:pindiscussions`, on install and on upgrade.
- What the account of the MiwLab did in a course (the topics it recreated, for instance) is no longer counted as progress of a
  person when the plugin decides whether a course can be replaced: the account that is running never counts, also when the key
  was made by hand and the plugin does not know the account. A topic started by a teacher still counts as progress (a post).
- `local_miwlab_get_info` lists the feature `forumtopics`.

## 1.6.0 (2026102108)

- New web service function `local_miwlab_get_forum_types`: the type of each forum of a course (`general`, `qanda`, `single`,
  `eachuser`, `blog`), which Moodle's `core_course_get_contents` does not return. The MiwLab uses it to show the right type
  of a forum read by the key and to offer only the completion conditions a person can fulfil in it (nobody but who leads
  opens a discussion in a Questions and answers forum or a single discussion). Needs `local/miwlab:viewreports` in the
  course; only the activity and its type, no person and no post. The "MiwLab (local_miwlab)" service gets the function.
- New web service functions `local_miwlab_backup_course`, `local_miwlab_get_backup_status`,
  `local_miwlab_get_backup_chunk` and `local_miwlab_delete_backup`: the backup of the CONTENT of a course, without any
  data of a person (users, enrolments, role assignments, completion, grade history, logs, comments, groups and badges
  awarded all off; refused with `error:backupwithpeople` when the site locks any of them on), in the background, for the
  MiwLab to download in parts and read the course with all its content. Only the request that queued a backup gets it:
  `local_miwlab_backup_course` returns a secret once (the plugin keeps its SHA-256), and the status, the parts and the
  deletion need it, besides both capabilities in the course. The service does not download files and the plugin serves
  nothing through `pluginfile.php` (security review: with "Can download files" the key would reach any file the role
  could see). The file is kept in the course context (component `local_miwlab`, area `contentbackup`), and deleted when
  the MiwLab asks or after a day (the cleanup task of the restores). The copy Moodle's backup leaves in the course
  backup area is deleted at once, also when the backup fails after being made. New table `local_miwlab_backups`, without the account that asked.
- New capability `local/miwlab:backupcourse` (with `moodle/backup:backupcourse`, in the course). The "MiwLab" role gets
  both, on install and on upgrade; the "MiwLab (local_miwlab)" service gets the four functions.
- The "MiwLab" role also gets `moodle/question:managecategory` and `moodle/question:add`: a package with the content of a
  course imported from Moodle brings its question bank, and Moodle's restore (as a new course, or replacing a course)
  refuses it before restoring when the account cannot create the question categories and questions in the course.
- Replacing the content of a course with a package of that same course (its content imported into the MiwLab and sent
  back) keeps the question bank: the content is now deleted before the precheck the restore uses. Before, Moodle's precheck
  matched the question categories and questions of the package to the ones of the course with the same stamp, and the
  deletion of the content that follows left the course question bank (and the quiz questions from it) out. The same order
  is used when a failed replacement puts the backup back.
- `local_miwlab_get_info` lists the feature `contentbackup`.

- New web service function `local_miwlab_get_blocks`: the blocks installed on the site, with their names and whether each
  is enabled. It needs `local/miwlab:viewmoduletypes` at site level, the capability that already lists the module types
  and the course formats; the "MiwLab" role has it. The MiwLab uses it to add the third-party progress bar block
  (`block_completion_progress`) to the courses it sends only when the site has it.
- Restoring a package (as a new course, or replacing the content of a course) activates the course badges the restore
  brought in: Moodle always restores course badges inactive, and the MiwLab package carries one badge per competency,
  awarded when the activity that assesses it is completed with the passing grade. Only badges with criteria that the
  restore brought in are activated (the ones the course already had are left as they are); a badge without an issuer
  address gets the address of the site. Nothing happens when badges or course badges are disabled on the site.
- Replacing the content of a course deletes its course badges for good before the restore (Moodle's own deletion of the
  content would keep each one as an archived site badge, one more at every replacement), and refuses when one of them was
  awarded (an awarded course badge already counts as progress). When the replacement fails and the backup is restored,
  what the failed restore brought is deleted the same way, and the badges the backup brings back get the status they had.
  Up to Moodle 4.4 a backup without user data carries no badges, so there the rollback brings the course back without them.
- Capabilities (security review): replacing a course also needs `moodle/badges:deletebadge` and
  `moodle/badges:configurecriteria` in it (refused naming them, `error:missingcoursecapabilities`); a restore as a new
  course activates the badges only with `moodle/badges:configurecriteria` in the new course (without it they stay inactive
  and the restore finishes). The "MiwLab" role gets both, on install and on upgrade.
- The "MiwLab (local_miwlab)" service gets the new function on install and on upgrade. A service made by hand needs it
  added to its functions.
- `local_miwlab_get_info` lists the features `blocks` and `badges`.

## 1.5.0 (2026102101)

- New web service function `local_miwlab_replace_course`: replaces the CONTENT of an existing course with a package (the
  sections, activities and resources, blocks, gradebook and question bank of the course come from the package), in the
  background, as Moodle's "Restore into this course, delete the contents first" without overwriting the course
  configuration. The course stays the same course: id and address, full and short name, category, summary and image,
  format, dates, visibility, tags, enrolments, roles and groups. Safeguards, all on the server:
  - refused when somebody has progress in the course (an activity or the course completed, any grade of any grade item,
    a submission (a group submission counts for each member of the group, and counts even when the group has no member),
    a grade in an assignment not released yet, an answer kept without the person (anonymous), a quiz or lesson attempt, an answer, a post, a glossary entry, a wiki page, a workshop submission or
    assessment, a certificate or a course badge issued, a course competency rated or with evidence, a meeting joined, an
    attendance taken, a note), checked when asked, again when the task starts and again after the backup; and refused when
    the course has an activity of a module whose progress the plugin does not read (fail closed, `error:unknownmodules`);
  - a backup of the course, without user data, is made first; when it fails, nothing is replaced. It is kept in the course
    backup area (Course > Restore), with the backups that were already there (Moodle empties that area when it deletes
    the content; the plugin keeps them aside and puts them back);
  - when the restore fails after the content was deleted, the backup is restored into the course again;
  - optional `expectedsignature` / `unchangedsince`: refused when the course changed in Moodle since the caller last knew it;
  - the MiwLab marker goes to the ID number only when the course has none or a MiwLab one (an ID number of another system is
    kept).
- New function `local_miwlab_get_replace_check`: the course, how many people are enrolled and how many have progress (counts
  only), the capabilities the account lacks, and the state of the content.
- New function `local_miwlab_get_course_state`: a signature of the structure of the content and its latest edit, to know
  whether the course changed in Moodle (what people do in the course is not part of it).
- New function `local_miwlab_hide_course`: hides a course, for the old course once a new one takes its place.
- New capabilities `local/miwlab:replacecourse` (course level, RISK_DATALOSS) and `local/miwlab:hidecourse`. The "MiwLab"
  role gets them and `moodle/backup:backupcourse`, `moodle/restore:configure`, `moodle/restore:rolldates` and
  `moodle/course:visibility`, on install and on upgrade; still no `moodle/course:update`.
- `local_miwlab_get_restore_status` also returns `mode`, `backupfilename`, `rolledback` and, when finished, the state of the
  course (`coursesignature`, `contentmodified`); a replacement is followed by whoever can replace that course.
- `local_miwlab_get_info` lists the features `replace`, `hidecourse` and `coursestate`.
- Fix: the site course was not recognised as such by the reports (`SITEID` is a string); it is refused as not a course.

## 1.4.0 (2026102000)

- New web service function `local_miwlab_get_course_formats`: the course formats enabled on the site (add-ons included),
  in the order and with the names of the course settings form, and which one is the default of new courses. A disabled
  format is left out. It needs `local/miwlab:viewmoduletypes` at site level, the capability that already lists the module
  types; the "MiwLab" role has it. The MiwLab uses it to offer every format of the site, not only the ones courses
  already use.
- The "MiwLab (local_miwlab)" service gets the new function on install and on upgrade. A service made by hand needs it
  added to its functions.
- `local_miwlab_get_info` lists the feature `courseformats`.

## 1.3.0 (2026101920)

- The plugin service is named "MiwLab (local_miwlab)": upgrading no longer stops on a site that has a service named
  "MiwLab" made by hand, whose key keeps working; the service of 1.2.0 keeps its key. "Generate the MiwLab key" creates
  the service again when an earlier upgrade stopped half way.
- Moodle 5.3: generating the key, and upgrading when the account exists, turns the web service authentication on (Moodle
  5.3 refuses the token of an account whose authentication method is disabled); the account is created with
  `\core\user::create_user()` where it exists.

## 1.2.0 (2026101910, 2026101911)

- New function `local_miwlab_get_module_types` (the activity and resource types installed on the site) and capability
  `local/miwlab:viewmoduletypes`.
- On install, the plugin creates the external service with every function the MiwLab calls and the "MiwLab" system role
  with the least capabilities they need; the plugin page generates the account and the key in one click.

## 1.1.1 (2026101902)

- Moodle 4.1 to 5.3; README ready for the Moodle Plugins directory.

## 1.1.0 (2026101901)

- The feedback analysis says how many answered an open question even without the text.

## 1.0.0 (2026101900)

- Restore of a MiwLab package as a new course, its progress, finding courses by the MiwLab marker, consolidated
  completion and anonymous feedback results.

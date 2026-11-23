# Changes

## 1.15.0 (2026112202) — the questions written in the MiwLab are added to a quiz that is in Moodle

- **"Update only what is safe" adds the questions of the MiwLab to a quiz that is already in Moodle** (issue 349), with the new key `questions` of the list of operations. **Each question goes once**, recognised by the ID number `miwlab:q:<key>` and by a record of what went; one that came in a package without a stamp is recognised by its content. A question edited or deleted in the MiwLab afterwards is neither rewritten nor deleted in Moodle.
- **Images named by their digest** (`updatehashedfiles`, issue 373): the content of a Page, the description of any activity, the summary of a section and the questions of a quiz bring their images as files in their original format (PNG, JPEG, GIF and WebP, served with their real type also in Moodle 4.1 and 4.5, which do not know WebP), checked by digest and by real type, never over another file, and taken back on a failure. A question with an embedded image (`data:`) is refused, never cleaned in silence.
- **A JPEG arrives whole** (issues 355 and 373): Moodle rewrites a JPEG it receives by `webservice/upload.php` (4.5: every one; 5.x: the ones with EXIF), so its digest no longer matched and the whole list was refused. With `updatehashedfiles` the application sends each image (of a Page, of a text, of a question and the image of the course) in a neutral container, `<name>.miwlab` with the header `MIWLABFILE1` before the exact bytes, which Moodle keeps as it is; size, digest and type are checked on the bytes without the header, and a container without the header is refused. The file is then written by Moodle, so the removal of EXIF the site has on still applies, and it keeps in its source the digest it was written from: the same image sent again is the same file (no conflict, the image of the course is not changed again). A bare file is still accepted as before.
- **WebP keeps its type after a restore**: Moodle 4.1 and 4.5 do not know the extension and the restore does not keep the type of the package, so a `miwlab-<digest>.webp` came back as an unknown file. After creating, replacing or adding from a package (and after taking a replacement back), the WebP images named by their digest in the course and its modules get `image/webp`, only when their bytes are a WebP.
- **A quiz with attempts is never touched**, also when the attempt starts between the preview and the application, and questions **only go to a quiz that students cannot start now** (hidden, not open yet or closed): otherwise it is left out with the reason. **Nothing is deleted from the question bank.**
- Without a draw, the questions are added to the ones in the quiz. **With a draw, they and the questions made in Moodle that are in the quiz are one set** in the bank of that quiz, and the quiz draws the quantity configured. A question of another bank or of another use is copied into the set with the original intact, and a Description stays fixed. The preview says exactly what changes and the maximum grade stays. When the quiz has a draw made over another set, or an item that cannot join, it is left out with the reason.
- **The name of a question, the pair of a matching question and the text of a short answer open no tag** (security review of the 1.63.0): a `<` followed by a letter, `/`, `!` or `?` gets a space, so the plain text Moodle keeps never becomes markup where a report or a theme shows it as it is; `x < y` stays.
- The questions are written last, in one transaction up to the end of the update: a failure takes them back, and the undo journal takes back the rest.
- The new function **`local_miwlab_get_question_state`** tells what is pending in each quiz, changing nothing. `local_miwlab_get_info` answers the new feature `updatequizquestions`. A client must not send `questions` to an earlier version.
- The role gets `mod/quiz:manage`, `moodle/question:useall` and `moodle/question:moveall` (no capability to delete questions); the same key works. New table `local_miwlab_questions`, with no data of a person.

## 1.14.0 (2026112201) — the image of the course reaches the course in "Update only what is safe"

- **The image of the course is carried by the safe update** (issue 355). `coursesettings.image` of the list of operations (`draftitemid`, `filename` as `imagem-do-curso.png`, `.jpg` or `.gif`, `sha256` and `size`) replaces the image of the course (the "overviewfiles" area, the one the list of courses shows) **in place** and **only when the bytes differ** from the ones the course has, compared by SHA-256 (an image the course already has, under any name, is not sent again). The preview shows it as the item `cs:image`.
- The image is checked like the images of a Page: it must be in the draft area of the account, with the size and the SHA-256 the application said, a PNG, JPEG or GIF by its content (never by the label), whose extension is its type, up to 2 MiB and 16 million pixels; anything else makes the whole list invalid and nothing is looked at or changed. The bytes are staged privately for the preview and checked again when it is applied.
- **A description that cites the image** (`@@PLUGINFILE@@/imagem-do-curso.png`) is no longer kept out as `newfile` when the image goes in the same update: the file is also put in the summary area of the course (only when it is missing or is another one) and the description goes with it.
- The old image and the files it replaced are kept, with their bytes, in the undo journal: a failure puts them back and removes the ones the update created.
- `local_miwlab_get_info` answers the new feature `updatecourseimage`. A client must not send `image` to an earlier version (it refuses the whole list).
- No new function, table or permission; the same key works. Privacy: nothing new is stored (the staged image goes with its preview).

## 1.13.0 (2026112102) — topics of the forums and the description of the course reach the course in "Update only what is safe"

- **The opening topics of the forums are created by the safe update** (issue 347). The new function **`local_miwlab_create_forum_topics`** takes a small package (`miwlab/foruns.json` plus the files of the topics, each forum told by its course module, `cmid`) from a draft area and creates, as the account of the integration and without sending any notification, the topics that are not in their forum yet (same forum, same title), including the forums the update itself created. **A topic that is already there is left exactly as it is, even if the MiwLab changed its text** (never rewritten, never duplicated); a forum that is not in the course, or is not a forum, counts as "not created" with the reason; the single simple discussion forum is left to Moodle. It answers `created`, `existing`, `skipped` and the first `note`. Needs `local/miwlab:updatecourse` in the course and, for each forum, the permission to start a discussion there. A failure here never undoes an update that was already applied: the MiwLab shows it as a warning.
- **The description of the course is carried by the safe update** (issue 348). `coursesettings.summary` of the list of operations (HTML, as a section summary comes) changes the summary of the course **in place** and only when it differs from the one the course has; the preview shows it as the item `cs:summary`. As for a section, an image it cites that the course does not have in its summary area keeps the item out (`newfile`). The old description is kept in the undo journal: a failure puts it back.
- `local_miwlab_get_info` answers the new features `updateforumtopics` and `updatecoursesummary`. A client must not send `summary` to an earlier version (it refuses the whole list).
- The new function comes to the `MiwLab (local_miwlab)` service on upgrade, so the same key reaches it. No table changes, no new permission. Privacy: nothing new is stored.

## 1.12.0 (2026112101) — badges and the progress bar on a course that exists, and the site switches they depend on

- **`local_miwlab_apply_gamification`** (issue 333) adds course badges and the progress bar block to a course that already exists (until now badges only came inside a restored package, so a course kept up to date by the safe update never got them). One badge per call entry: name, description, the activities whose completion awards it (all of them), and an optional picture from a draft area (PNG, JPEG or GIF up to 2 MiB; anything else is not taken and the badge goes without a picture). **A badge the course already has by name (case and surrounding spaces ignored), awarded or not, is never changed, deleted or created again.** The completion tracking of the course is turned on when the site has it and the course does not. Each badge says `created`, `existing` or `skipped`, with the reason: `badgesoff`, `coursebadgesoff`, `completionoff` or `noactivities`; the block says `added`, `existing` or `skipped` (`notinstalled`, `disabled`). Needs `local/miwlab:managegamification` in the course and, for badges, `moodle/badges:createbadge` and `moodle/badges:configurecriteria`.
- **`local_miwlab_enable_features`** turns ON, after the person confirmed it in the MiwLab, a fixed list of site switches: `competencies`, `badges`, `coursebadges`, `completion` and `progressblock` (enables the block when it is installed). **It only ever turns on, never off; any other name answers `unknown`; a block of another developer that is not installed is never installed** (`notinstalled`). Needs `local/miwlab:manageconfig` at site level, declared with the configuration risk. No `moodle/site:config` is given to the role: the plugin capability is the gate.
- `local_miwlab_get_info` answers `siteconfig` (badges, course badges, completion, whether the progress bar block is installed and enabled) and `systemcapabilities.manageconfig`, so the MiwLab can tell what is off before sending and whether it may turn it on. New feature names `gamification` and `siteswitches`.
- The two functions come to the `MiwLab (local_miwlab)` service on upgrade and the role gets `local/miwlab:managegamification`, `local/miwlab:manageconfig` and `moodle/badges:createbadge` (`ensure_role`); the same key reaches them. No table changes. Privacy: nothing new is stored (badge pictures are copied from the caller's draft area to the badge, as any badge picture).

## 1.11.0 (2026112002) — Marketplace build checks

- Align PHP formatting and documentation with the Moodle coding standard, and assert `updatepagefiles` in the feature detection test. No behaviour, permission or database schema changes.

## 1.11.0 (2026112001) — images in safe updates of existing Pages

- Safe updates can add the images referenced by the new content of an existing Page without replacing the activity or changing its progress, grades or completion. The Page keeps its identity and all its previous files.
- `local_miwlab_get_info` advertises `updatepagefiles`. Clients send authenticated draft references, SHA-256 digests and byte counts with the Page text. The plugin verifies ownership, target course and file area, canonical names, image signatures and limits; it stages accepted bytes privately for the preview and verifies them again before applying it.
- Images are limited to four per Page, 24 per preview, 2 MiB per image and 48 MiB in total. Only PNG, JPEG and GIF are accepted. An existing file with the same name and different bytes is refused; identical bytes are reused. New files are added only when the corresponding text can be applied, and removed by the journal if the update fails.
- No table changes, new function or permission. The existing service key continues to work. Private staged files expire with their preview and are removed after application. No new personal data is collected.

## 1.10.2 (2026110302) — a competency achieved with any of its children, and the competencies of the MiwLab moved to the parent it asks

- **`local_miwlab_complete_competency_framework` takes `rule` per competency** (`all`, the default, or `any`) **and `moveparents` for the call** (issue 252, option B).
  `any` gives a competency with children the rule "achieved when ANY child is" (a points rule: each child is worth one point and one point is enough; the list of
  children is brought up to date every time, since Moodle takes the rule of a mother away when a child is added). It is only put on a competency made by the MiwLab
  (ID number starting with `miwlab:`); on any other it is told as `kept`. The rule of a competency of the organisation (any rule that is not the plain "all
  children"/"any child" with the outcome "achieved" on a `miwlab:` competency) is never touched. A rule the plugin itself set can be replaced by the other one
  (told as `updated`).
- **`moveparents`**: a competency made by the MiwLab (`miwlab:`) that sits under another competency made by the MiwLab is moved (through
  `core_competency\api::set_parent_competency`, so its id, links and what people achieved stay) under the parent the list says; told as `parentmoved`. Anything the
  organisation made, or placed under its own competency, stays where it is (`parentkept`). Without `moveparents` nothing is moved, as before. Why: the MiwLab now
  puts a level "this course" between the competency of a reference and the skills of each course, and courses sent before had the skills right under the reference.
- New feature name `competencyrules` in `local_miwlab_get_info`, so a client knows the plugin understands both.
- No table changes, no new function, no new permission: the upgrade only moves the version, and the same key reaches it. Privacy: nothing new is stored.

## 1.10.1 (2026110301) — the text of the scale, exactly as Moodle stores it

- **`local_miwlab_get_scales` also returns `scale`**: the text of each scale untouched (`scale.scale`), with the spaces typed after the commas. `items`
  keeps the trimmed items, so a client that reads only `items` is not affected; `scale` is optional in the returned structure (1.10.0 does not have it).
  Why: when a course is restored Moodle reuses a scale of the site only if the text is identical, and a scale written "Bad, Good, Great" is not
  "Bad,Good,Great"; a client that rebuilt the text from `items` made Moodle create a copy of the scale in the course (issue 250).
- No table changes, no new function, no new permission: the upgrade only moves the version, and the same key reaches it.
- Privacy: nothing new is stored; the text of a scale is data of Moodle itself.

## 1.10.0 (2026110201) — stamp of the activities, competencies, scales and course completion in the safe update

- **Stamp of the activities, to recognise the copies of a course** (`local_miwlab_stamp_activities`, `local_miwlab_find_courses_by_stamp`).
  When a course is copied in Moodle it loses the marker in the course ID number, but the ID number of its activities is kept. The MiwLab
  writes `miwlab:<course>:a:<activity>` into the ID number of the activities of a course that was already in Moodle (capability
  `local/miwlab:updatecourse`, in the course), **never over a value that is there**: it answers which stamps it wrote and which it skipped, with
  the reason (`existing`, `same`, `duplicated`, `idnumbertaken`, `invalidstamp`, `notincourse`, `deleting`). It finds the courses of the site
  whose activities carry the stamps of a course of the MiwLab, how many and which. `local_miwlab_find_courses` now says when two or more courses
  carry the very same marker (`duplicated`), which one is exactly the marker asked (`exact`) and when the list was cut (`truncated`).
- **Competencies** (`local_miwlab_get_competency_frameworks`, `local_miwlab_get_competencies`, `local_miwlab_complete_competency_framework`,
  `local_miwlab_get_course_competencies`, `local_miwlab_set_course_competencies`; capabilities `local/miwlab:viewcompetencies` and
  `local/miwlab:managecompetencies`, plus the four `moodle/competency:*` ones the API of Moodle asks, given to the role). They list what
  exists; complete a framework (create it or the competencies it lacks, update the name and description of the ones that exist, and give a
  competency with daughters the rule "achieved when all the children are" when it has no rule); and link competencies to a course and to its
  activities so that **completing the activity marks the competency as achieved**. Everything goes through `core_competency\api`. It only
  adds: no framework, competency or link is ever deleted, moved, re-scaled or hidden, and a link that exists is left as it is. A framework
  made by the plugin has its highest scale item as the default and the proficient one (Moodle gives the default grade when an activity
  completes a competency, so a default that is not proficient would rate without achieving); `completeachieves` tells when a framework of
  the organisation is set up the other way. Learning plans are not touched.
- **Scales** (`local_miwlab_get_scales`, capability `local/miwlab:viewmoduletypes`): the grading scales of the site, and of a course when it is
  asked (with the right to read the course), with their items.
- **Detection** (`local_miwlab_get_info`): new features `stampactivities`, `findcoursesbystamp`, `scales`, `competencies` and
  `completioncriteria`; `competenciesenabled`; and `addons`, whether `availability_coursecompleted` ("Restriction by course completion") is
  installed in the database, enabled, and in which version.
- **Course completion in the safe update.** The update takes the completion criteria of the course **by activity** (type 4: an activity of the
  course) and **by grade** (type 6: an absolute minimum grade of the course), as well as by another course, now with the type Moodle
  really uses, **8**. Fixed: `criteria_differ` compared the criterion of a course with 7 (the manual conclusion by role) while Moodle
  stores it as 8, so the plugin always saw a difference; the wire type of that criterion is 8 now, and 7 is refused. The same risk
  classification as the rest: replacing criteria still needs the conscious confirmation and the full backup when somebody concluded the course,
  and a criterion by activity on an activity without completion tracking leaves the item out with the reason `nocompletion` (the criteria the
  course has stay). The application writes the aggregation rows as the completion page of the course does (before, a course with criteria
  written by the update had none).
- No table changes. The upgrade gives the role the six new permissions and Moodle adds the eight new functions to the "MiwLab
  (local_miwlab)" service: the same key reaches them. A service made by hand needs them added.
- Privacy: nothing new is stored. The stamp is an identifier of the course and of the activity in the MiwLab; frameworks, competencies and
  their links are data of Moodle itself. Strings are in English; the Brazilian Portuguese pack is the issue of the translation.

## 1.9.1 (2026103100)

- No change in the code. New build for the Moodle Marketplace automated tests.

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

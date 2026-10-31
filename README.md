# MiwLab companion plugin for Moodle (local_miwlab)

Lets a MiwLab installation connect to Moodle with one click of the site administrator, list the activity types, the
course formats, the blocks, the certificate templates and the upload limits of the site, send a finished course to Moodle
as a new course or in place of the course it came from (with the opening topics of its forums), read, as counts only, the
activity completion and the anonymous feedback results of that course, and back up the content of a course without any
data of a person for the MiwLab to bring it in whole, through web service functions.

## Description

The MiwLab is a course design tool, run by each organisation on its own server, separate from Moodle. It builds the course
(competencies, units, activities, rubrics, the feedback survey) and generates a Moodle course backup (.mbz) of it. **This
plugin is useful only together with the MiwLab**: its only page is the one where the site administrator generates the
MiwLab key, and it does nothing else until a MiwLab installation calls it with that web service token. Its functions are plain Moodle web services,
though, and can be tried without the MiwLab (see [Testing without MiwLab](#testing-without-miwlab)).

On install, the plugin creates the **"MiwLab (local_miwlab)" external service** (enabled, restricted to authorised users, with file
upload) with every function the MiwLab calls, the core ones included, and the **"MiwLab" system role** with only the
capabilities those functions need. No account is created on install. On the plugin page (Site administration > Plugins >
Local plugins > MiwLab key), a click of the site administrator on **Generate the MiwLab key** turns web services and REST on,
creates the account `local_miwlab` (web service authentication: nobody signs in with it; fictitious e-mail
`local_miwlab@example.invalid`, with e-mail stopped), turns the web service authentication on (from Moodle 5.3 on, the
token of an account whose authentication method is disabled is refused; upgrading to 1.3.0 turns it on too when the
account exists), gives it the role at site level, authorises it in the service and
shows the token and the site address, to paste in the MiwLab. The same page generates a new key (the old one stops
working), revokes the key or deletes the account. Uninstalling the plugin removes the service and its tokens, the role and
the account it created.

With that token, the MiwLab uses the plugin to do ten things Moodle core web services do not offer:

1. **List the activity and resource types installed on the site** (enabled or not, standard or add-on), without depending
   on a course where the account can add activities, and **the course formats enabled on the site** (the ones the course
   settings form offers, add-ons included), without depending on the courses that already use them.
2. **Restore a course package** (.mbz) as a new course in a chosen category, in the background (ad hoc task), and follow
   its progress until the address of the new course is known. The course ID number receives a MiwLab marker, so the
   MiwLab finds the course later.
3. **Read consolidated activity completion** of a course: per section and per activity, how many students completed it
   out of how many are enrolled. Counts only, never a person.
4. **Read the consolidated results of an anonymous feedback** (mod_feedback), as Moodle consolidates them. The text of
   open answers leaves Moodle only when the call asks for it. A feedback that is not anonymous is refused.
5. **Replace the content of the course a MiwLab course came from** (1.5.0), keeping the course itself (id, address, names,
   category, summary, image, format, dates, visibility, enrolments, roles and groups): refused when somebody has progress
   there and people are enrolled (from 1.7.2 a course with nobody enrolled is replaced whatever traces it has), or the course
   changed in Moodle since the MiwLab knew it; a backup of the course is made first, kept in its course backup area, and
   nothing is replaced when it fails. From 1.7.3 the call may ask, with `forcediscard`, to replace it even with progress: the
   progress and the grades are discarded and the enrolments kept, and the backup made first, having no user data, does not
   bring them back; the MiwLab asks for it only after the person who sends the course confirms that the progress will be lost. And **hide the old course** once a new one takes its place.
6. **List the blocks installed on the site** (1.6.0), so that a course sent by the MiwLab carries a third-party block (the
   progress bar of its light gamification) only when the site has it; and **activate the course badges a package brings**
   once the plugin restores it (Moodle always restores course badges inactive). Only the badges the restore brought in are
   activated; the ones the course already had are left as they are.
7. **Back up the content of a course without any data of a person** (1.6.0), so that a course the MiwLab reads by the key
   comes with all its content (page texts, files and images, questions, the statements of assignments, the sections'
   summaries) and goes back intact in the package. The backup is a regular course backup with users, enrolments, role
   assignments, completion, grade history, logs, comments, groups and badges awarded all off (refused when the site forces
   any of them on), made in the background as the account that asked, kept in the course context only until the MiwLab
   downloads it in parts (only the request that queued it can, with a secret it received once; the service does not
   download files, and the plugin serves nothing through `pluginfile.php`) and deletes it, and
   deleted after a day anyway.
8. **List the certificate templates of the site** (1.7.0), so that a course the MiwLab sends carries the Course certificate
   (`mod_coursecertificate`, of `tool_certificate`) already pointing at the template the organisation chose; and
   **list the limits that can refuse the package** when it is sent (PHP and the site maximum upload size), so that the MiwLab
   says which limit barred it and how much it is worth.
9. **Read and recreate the opening topics of the forums** (1.7.0). Moodle restores a package without the people, and without them
   it brings back no discussion of a forum (only the one of a "single simple discussion" forum is recreated from its
   introduction). The MiwLab keeps the opening topic of each forum (title, text and files, no replies), and the plugin
   recreates it after the restore: `local_miwlab_get_forum_topics` reads, from the course of origin, the first post of each
   discussion started by the **staff** of the course (a teacher with or without editing rights, a manager, or a site
   administrator; a discussion started by a student or anyone else is never read, nor are replies, grades, ratings,
   subscriptions or names), and the restore and the replacement create the topics the package carries
   (`miwlab/foruns.json` and the files under `miwlab/foruns/`, which Moodle's restore ignores) as the integration
   account, without sending any notification and without duplicating a topic whose title is already in the forum. A
   restore that cannot create a topic (a missing capability, say) finishes all the same and reports how many topics were
   created and not, with the first reason. What the account of the MiwLab does in a course (the topics it recreates, for
   instance) is not counted as the progress of a person when the plugin decides whether a course can be replaced.
10. **Read, in batch, the numbers of the courses of whole categories** (1.8.0), so that the management screens of the MiwLab show the
   whole Moodle and not only the courses it made: `local_miwlab_get_courses_overview` returns, for up to 500 courses per call, the
   students enrolled, whether each course measures completion and how many completed. Counts only, made in the database; teachers,
   suspended or expired enrolments and deleted accounts are never counted; the right is checked in each category asked.
11. **Update a course in place, only with what is safe for the people who study in it** (1.9.0): `local_miwlab_preview_update`
   sorts what the MiwLab wants to change against the course as it is and the progress of each activity (updated, added or hidden,
   or left out with the reason), changing nothing; `local_miwlab_apply_update` applies exactly that preview, in the background,
   after a backup without people. Grades, submissions, attempts, posts and the completion of the people stay as they are. What is
   course-wide (blocks, completion criteria of the course, gradebook of the course) is a choice of whoever sends, "keep" by default.

The plugin makes no network request: it only answers the web service calls it receives. It stores no personal data
(privacy API: null provider); it keeps the progress of each restore, without the person who asked for it, for 30 days
after the end, the status of each backup of the content of a course (without the person who asked), deleted with the
backup once the MiwLab downloads it or after a day, and the ids of the role and of the account it created. The integration account needs fewer rights with the plugin than without it: no
`mod/feedback:*` capability, and no enrolment in the courses it reads.

## Requirements

- Moodle 4.1 to 5.3 (`$plugin->supported = [401, 503]`; tested on 4.1, 4.5, 5.0, 5.1, 5.2 and 5.3).
- PostgreSQL, MariaDB or MySQL (tested on PostgreSQL 17 and MariaDB 11.4 in every version above, and on MySQL 8.4 in
  4.5 and 5.3). The plugin uses only the Moodle database API (DML), with no SQL specific to a database.
- Web services with the REST protocol enabled, and cron running (the restore is an ad hoc task).
- Optional: the Course certificate activity (`mod_coursecertificate`) and the certificate tool (`tool_certificate`), only for
  the certificate templates function; without them that function answers that they are not installed, and nothing else changes.
- The external system is the MiwLab installation of the organisation, which calls the plugin; the plugin itself calls
  nothing outside Moodle.

## Installation

Site administration > Plugins > Install plugins, with the zip; or unzip it into `local/miwlab` (`public/local/miwlab` from
Moodle 5.1 on) and visit Site administration > Notifications (or run `admin/cli/upgrade.php`).

### Updating

Install the new zip over the old one the same way (Site administration > Plugins > Install plugins, or replace the folder and
visit Site administration > Notifications). The upgrade adds the new columns of the restore table, adds the new functions to
the "MiwLab (local_miwlab)" service and resets the "MiwLab" role to the capabilities of the current release. The key does not
change and nothing has to be generated again. A service made by hand (see below) is not touched: add to it the functions that
are new in the release (1.7.0: `local_miwlab_get_certificate_templates`, `local_miwlab_get_upload_limits` and
`local_miwlab_get_forum_topics`; 1.8.0: `local_miwlab_get_courses_overview`; 1.9.0: `local_miwlab_preview_update` and `local_miwlab_apply_update`) and give its role the capabilities that go with them. The list of changes by release is in
`CHANGES.md`.

## Configuration after installing

Site administration > Plugins > Local plugins > **MiwLab key** (or search "MiwLab" in Site administration), then
**Generate the MiwLab key**, and paste the key and the site address in the MiwLab, in Organização > Plataforma de ensino
(Organisation > Teaching platform). Nothing else is needed.

The role "MiwLab" holds, at site level, only what the functions of the service need. The writes are the restore of a
package as a new course and, from 1.5.0, the replacement of the content of a course where nobody has progress (after a
backup of it; from 1.7.3, also discarding the progress when the call asks for it with `forcediscard`) and hiding a course, each through a plugin function that checks its own capability in the course. Each
capability and the function that needs it:

| Capability | Why |
|---|---|
| `webservice/rest:use` | the REST protocol of the key |
| `moodle/course:view` | `core_course_get_contents` and `core_course_get_courses_by_field` read a course the account is not enrolled in |
| `moodle/course:viewhiddencourses` | the same, and `core_course_search_courses`, for hidden courses (a restored course arrives hidden) |
| `moodle/course:viewhiddensections` | `core_course_get_contents` returns the hidden sections |
| `moodle/course:viewhiddenactivities` | `core_course_get_contents` returns the hidden activities and the access restriction (`availability`) of each one |
| `moodle/course:ignoreavailabilityrestrictions` | `core_course_get_contents` returns the activities an access restriction hides from the account |
| `moodle/category:viewhiddencategories` | `core_course_get_categories` lists hidden categories too |
| `moodle/restore:restorecourse`, `moodle/course:create`, `local/miwlab:restorecourse` | `local_miwlab_get_restore_categories`, `local_miwlab_restore_course` (restore as a new course) |
| `local/miwlab:viewreports` | `local_miwlab_get_courses_overview` (1.8.0: counts of the courses of whole categories, checked in each category), `local_miwlab_get_course_completion`, `_get_course_feedbacks`, `_get_feedback_analysis`, `_find_courses` (counts only), `_get_course_state`, `_get_forum_types` (1.6.0, type of each forum) |
| `local/miwlab:viewmoduletypes` | `local_miwlab_get_module_types`, `local_miwlab_get_course_formats`, `local_miwlab_get_blocks` (names only), `local_miwlab_get_certificate_templates` and `local_miwlab_get_upload_limits` (1.7.0: names and ids of templates; settings of the site) |
| `local/miwlab:replacecourse`, `moodle/backup:backupcourse`, `moodle/restore:restorecourse`, `moodle/restore:configure`, `moodle/restore:rolldates` | `local_miwlab_get_replace_check`, `local_miwlab_replace_course` (1.5.0): the backup made before, the restore into the course, its settings (keep enrolments, roles and groups) and its dates (follow the start of the course) |
| `local/miwlab:hidecourse`, `moodle/course:visibility` | `local_miwlab_hide_course` (1.5.0) |
| `local/miwlab:updatecourse` (1.9.0), `moodle/backup:backupcourse`, `moodle/restore:restorecourse`, `moodle/restore:configure`, `moodle/restore:rolldates` | `local_miwlab_preview_update`, `local_miwlab_apply_update`: all of them, in the course, and `moodle/backup:userinfo` too when the call confirms a discard (the completion criteria of a course somebody concluded) |
| `moodle/question:managecategory`, `moodle/question:add` | `local_miwlab_restore_course`, `local_miwlab_replace_course` (1.6.0): a package with the content of a course imported from Moodle brings its question bank, and Moodle's restore creates question categories and questions only for an account that can manage and add them in the course (without them it refuses the package before restoring it) |
| `moodle/backup:userinfo` (1.9.0) | the full backup, with the people, that `local_miwlab_replace_course` makes before a replacement with `forcediscard` (kept in the course backup area, deleted after `keepdays` days, 30 to 365). Only that path: the other backups set users off and check it |
| `local/miwlab:backupcourse`, `moodle/backup:backupcourse` | `local_miwlab_backup_course`, `local_miwlab_get_backup_status`, `local_miwlab_get_backup_chunk`, `local_miwlab_delete_backup` (1.6.0): the backup of the content of a course, without any data of a person, in the course |
| `moodle/badges:deletebadge`, `moodle/badges:configurecriteria` | `local_miwlab_replace_course` (1.6.0): the course badges of the old content are deleted before the restore, and the ones the package brings are activated; `local_miwlab_restore_course` (1.6.0): the badges the package brings are activated only with `moodle/badges:configurecriteria` in the new course (without it they stay inactive) |
| `local/miwlab:readforumtopics` | `local_miwlab_get_forum_topics` (1.7.0): the opening topics that the staff of a course started in its forums, in the course (it is personal data of the staff, so it is a capability of its own) |
| `mod/forum:startdiscussion`, `mod/forum:addnews`, `mod/forum:createattachment`, `mod/forum:pindiscussions` | `local_miwlab_restore_course`, `local_miwlab_replace_course` (1.7.0): the topics recreated after the restore need them in the restored course (`addnews` for the announcements forum, `createattachment` for the files, `pindiscussions` to keep a discussion pinned); without one the course is restored all the same, and the topics that need it are counted as not created |
| every `mod/<module>:view` of the site | `core_course_get_contents` skips an activity whose view capability the account lacks: without them a quiz, an assignment, a feedback or an H5P activity is missing from the structure (checked on 4.1 and 5.1). Read only; the role gets the new ones on every upgrade and every key generated |

`moodle/course:update` is deliberately not there: it would let the key change the settings of every course, and checked on
Moodle 4.1 and 5.1 it reads less than the three `viewhidden…`/`ignoreavailabilityrestrictions` capabilities above (only
the restrictions of visible activities). Neither are `mod/feedback:*`: the anonymous feedback is read by the plugin's own
functions, so the service does not carry `mod_feedback_*`. The replacement (1.5.0) does not need `moodle/course:update`
either: it does not overwrite the course configuration. The plugin resets the role to exactly this list on every upgrade.

### Configuring by hand (an account of your own)

A key made by hand works too:

1. Site administration > Server > Web services > Overview: enable web services and the REST protocol.
2. Create an account for the integration (or use the one the MiwLab key already uses) and a role for it. From Moodle 5.3
   on, the authentication method of the account must be enabled (Site administration > Plugins > Authentication >
   Manage authentication): an account with the web service authentication needs it on.
3. Site administration > Server > Web services > External services: in the service used by the MiwLab key (or a new
   one, with any name but "MiwLab (local_miwlab)", the name of the plugin service), add the twenty-four `local_miwlab_*` functions below and enable **Can upload files** (the package is uploaded with
   `webservice/upload.php` before the restore). **Can download files** stays off: the backup of the content of a course
   comes in parts through `local_miwlab_get_backup_chunk`.
4. Give the role of the integration account:
   - `webservice/rest:use`, at site level;
   - to restore: `local/miwlab:restorecourse`, `moodle/restore:restorecourse` and `moodle/course:create`, in each
     category where the MiwLab may create courses (or at site level);
   - to read the consolidated reports: `local/miwlab:viewreports`, at site level (or in the categories or courses);
   - to list the module types, the course formats, the blocks, the certificate templates and the upload limits of the site:
     `local/miwlab:viewmoduletypes`, at site level;
   - to replace the course a MiwLab course came from (1.5.0): `local/miwlab:replacecourse`, `moodle/backup:backupcourse`,
     `moodle/restore:restorecourse`, `moodle/restore:configure` and `moodle/restore:rolldates`, in the course (or its
     category, or at site level), and from 1.6.0 also `moodle/badges:deletebadge` and `moodle/badges:configurecriteria`;
     to hide the old course: `local/miwlab:hidecourse` and `moodle/course:visibility`;
   - to restore or replace with a package that brings a question bank (the content of a course imported from Moodle, 1.6.0):
     `moodle/question:managecategory` and `moodle/question:add`, in the categories (or at site level);
   - to back up the content of a course for the MiwLab (1.6.0): `local/miwlab:backupcourse` and `moodle/backup:backupcourse`,
     in the course (or its category, or at site level);
   - to read the opening topics the staff of a course started in its forums (1.7.0): `local/miwlab:readforumtopics`, in the
     course (or its category, or at site level); to recreate them after a restore: `mod/forum:startdiscussion`,
     `mod/forum:addnews`, `mod/forum:createattachment` and `mod/forum:pindiscussions`, in the categories (or at site level);
   - to have the badges a package brings activated when it is restored as a new course (1.6.0):
     `moodle/badges:configurecriteria`, in the categories (or at site level); without it they arrive inactive;
   - to read the whole structure of a course: `moodle/course:view`, `moodle/course:viewhiddencourses`,
     `moodle/course:viewhiddensections`, `moodle/course:viewhiddenactivities`, `moodle/course:ignoreavailabilityrestrictions`
     and each `mod/<module>:view`, at site level (the table above says why).
5. Create a token for the account and service (Site administration > Server > Web services > Manage tokens) and give it
   to the MiwLab, in Organização > Plataforma de ensino (Organisation > Teaching platform). The MiwLab tests the key and
   shows whether the plugin is installed, its version, and in how many categories the account can restore.

With the plugin, the account needs no `mod/feedback:*` capability (the plugin reads the anonymous feedback),
nor to be enrolled in the courses it reads: the reads validate only the category, and check `local/miwlab:viewreports`.

When Moodle restores a new course it makes whoever restores it a teacher of it ("Restorers' role in courses"). The plugin
takes that role and enrolment back from the integration account once the restore ends: it does not need them.

## Testing without MiwLab

Everything the MiwLab does with the plugin can be done with `curl` (and `jq`, to read the answers). Set up the account as
above (the site administrator also works, with a token for the service), then:

```sh
MOODLE=https://moodle.example        # address of the Moodle site
TOKEN=0123456789abcdef               # web service token of the account
ws() { f=$1; shift; curl -sS "$MOODLE/webservice/rest/server.php" -d moodlewsrestformat=json \
  --data-urlencode "wstoken=$TOKEN" --data-urlencode "wsfunction=$f" "$@"; echo; }

# 1. The plugin answers, with its version and what the account can do at site level.
ws local_miwlab_get_info

# 2. Categories where the account can restore (it holds the three capabilities there).
ws local_miwlab_get_restore_categories

# 3. Restore any course backup (.mbz) of your own: upload it to the draft area, queue the restore, run cron, follow it.
ITEM=$(curl -sS -F "file_1=@course.mbz" "$MOODLE/webservice/upload.php?token=$TOKEN" | jq -r '.[0].itemid')
ws local_miwlab_restore_course -d categoryid=<id from step 2> -d draftitemid=$ITEM --data-urlencode marker=miwlab:test-course:v1
#    -> {"restoreid": "...", "status": "queued", ...}
php admin/cli/adhoc_task.php --execute        # on the Moodle server, or wait for cron
ws local_miwlab_get_restore_status -d restoreid=<restoreid>
#    -> "status": "finished", "courseid", "courseurl", and "marker": "miwlab:test-course:v1" (the course ID number)

# 4. Find the course by the marker (or its beginning).
ws local_miwlab_find_courses --data-urlencode marker=miwlab:test-course

# 5. Consolidated completion of a course with completion tracking (counts of enrolled students only).
ws local_miwlab_get_course_completion -d courseid=<courseid>

# 6. Feedback activities of the course, then the consolidated results of an anonymous one (add one if the course has
#    none: Feedback activity, "Record user names" = Anonymous, a few questions, answered by some students).
ws local_miwlab_get_course_feedbacks -d courseid=<courseid>
ws local_miwlab_get_feedback_analysis -d feedbackid=<id>                  # open answers withheld
ws local_miwlab_get_feedback_analysis -d feedbackid=<id> -d includetext=1 # open answers included

# 7. Back up the content of a course, without any data of a person (1.6.0): queue it, run cron, download, delete.
ws local_miwlab_backup_course -d courseid=<courseid>                # -> {"backupid": n, "secret": "<s>", "status": "queued", ...}
php admin/cli/adhoc_task.php --execute        # on the Moodle server, or wait for cron
ws local_miwlab_get_backup_status -d backupid=<n> -d secret=<s>    # -> "finished", "filesize", "contenthash"
ws local_miwlab_get_backup_chunk -d backupid=<n> -d secret=<s> -d offset=0 | jq -r .data | base64 -d > content.mbz
#    (repeat with offset = the bytes already read until "eof" is true; each part has up to 4 MB)
sha1sum content.mbz                           # the contenthash above
ws local_miwlab_delete_backup -d backupid=<n> -d secret=<s>

# 8. The numbers of the courses of whole categories, in batch (1.8.0): students, whether each measures completion, how many
#    completed. Counts only; the right is checked in each category asked, and subcategories without it are left out.
ws local_miwlab_get_courses_overview -d 'categoryids[0]=<category id>' -d includesubcategories=1 -d limitfrom=0 -d limitnum=500
#    -> {"total": n, "next": -1, "courses": [{"id", "categoryid", "students", "measures", "completedby", "completed", ...}],
#        "categoriesleftout": 0, ...}   (repeat with limitfrom = next while next is not -1)
```

What to expect besides the success cases: an account without the capabilities gets `error:missingcapabilities` (the
message names each capability missing) or `nopermissions`; a feedback that is not anonymous gets `error:notanonymous`; a
file that is not a course backup ends with `"status": "failed"` and `error:notabackup`, and no course is left behind;
a marker that does not start with `miwlab:` gets `error:invalidmarker`; a backup of the content when the site locks "Include users" on ends with `error:backupwithpeople`.

## Web service functions

Errors come as Moodle web service exceptions (`{"exception": ..., "errorcode": ..., "message": ...}`), with the
`errorcode` stable and the `message` in the language of the account.

### local_miwlab_get_info

No parameters. Detects the plugin.

```
{ "component": "local_miwlab", "version": 2026102000, "release": "1.4.0",
  "moodleversion": "2026100500.02", "moodlerelease": "5.3+ (Build: 20261013)",
  "features": ["restore", "restorestatus", "findcourses", "completion", "feedback", "moduletypes", "courseformats"],
  "systemcapabilities": { "restorecourse": true, "viewreports": true, "viewmoduletypes": true } }
```

`features` only grows: a new feature adds a name, and a name never changes meaning.

### local_miwlab_get_module_types

No parameters. Every mod plugin installed on the site, with the name in the language of the account. Needs
`local/miwlab:viewmoduletypes` at site level; no course is involved.

```
{ "modules": [ { "name": "assign", "title": "Assignment", "enabled": true, "standard": true, "version": 2022112800 },
               { "name": "hvp", "title": "Interactive Content", "enabled": true, "standard": false, "version": 2024022100 } ] }
```

### local_miwlab_get_course_formats

No parameters. The course formats enabled on the site, in the order and with the names of the "Course format" field of
the course settings form (Site administration > Plugins > Course formats > Manage course formats): a disabled format is
left out. `default` marks the default format of new courses. Needs `local/miwlab:viewmoduletypes` at site level; no
course is involved. Since 1.4.0.

```
{ "formats": [ { "name": "topics", "title": "Custom sections", "standard": true, "default": true },
               { "name": "weeks", "title": "Weekly sections", "standard": true, "default": false },
               { "name": "tiles", "title": "Tiles format", "standard": false, "default": false } ] }
```

### local_miwlab_get_blocks

No parameters. The blocks installed on the site (Site administration > Plugins > Blocks > Manage blocks), in the order of
the names, with the name in the language of the account and whether each is enabled (`visible`). Needs
`local/miwlab:viewmoduletypes` at site level; no course is involved. Since 1.6.0.

```
{ "blocks": [ { "name": "completion_progress", "title": "Completion Progress", "visible": true, "standard": false },
              { "name": "html", "title": "Text", "visible": true, "standard": true } ] }
```

### local_miwlab_get_certificate_templates

No parameters. The certificate templates of the site (Site administration > Certificates > Manage certificate templates, the
`tool_certificate` plugin), for the Course certificate activity (`mod_coursecertificate`): the id and the name of each, whether it
belongs to the whole site (`categoryid` 0) or to a course category, and whether it is shared with the categories below. A
template of the site is good for every course; one of a category, for the courses of that category (and of the ones below it when
shared). `installed` is false when the activity or the certificate tool is missing (the list is then empty); `enabled`, whether the
activity is enabled in Manage activities. A template belongs to the site and is not part of a course backup: its id is only good in
the same site. Needs `local/miwlab:viewmoduletypes` at site level; no course is involved. Since 1.7.0.

```
{ "installed": true, "enabled": true,
  "templates": [ { "id": 3, "name": "Certificado padrão", "shared": false, "categoryid": 0, "categoryname": "" },
                 { "id": 5, "name": "Certificado da Formação", "shared": true, "categoryid": 12, "categoryname": "Formação" } ] }
```

### local_miwlab_get_upload_limits

No parameters. The limits that can refuse a file sent by the web service upload (`webservice/upload.php`), in bytes: the PHP
settings `upload_max_filesize` and `post_max_size` and the maximum upload size of the site (`maxbytes`). `effective` is the smallest
of them (0 when nothing limits) and `limitedby` says which one it is (`site`, `phpupload`, `phppost` or `none`). `userquota` is the
user quota of the site, reported because it is often blamed: Moodle applies it to private files, not to the draft area the upload goes
to. A web server in front of Moodle (nginx, Apache) can have a limit of its own that this function cannot see. Needs
`local/miwlab:viewmoduletypes` at site level. Since 1.7.0.

```
{ "effective": 33554432, "limitedby": "phpupload", "sitemaxbytes": 104857600, "phpuploadmaxfilesize": 33554432,
  "phppostmaxsize": 67108864, "userquota": 0 }
```

### local_miwlab_get_forum_types

Parameter: `courseid`. The type of each forum of the course (`general`, `qanda` for Questions and answers, `single` for a single
simple discussion, `eachuser` for each person posts one discussion, `blog`), by the id of the activity in the course. Needs
`local/miwlab:viewreports` in the course. No person and no post. Since 1.6.0.

```
{ "courseid": 12, "forums": [ { "cmid": 87, "type": "qanda" }, { "cmid": 90, "type": "general" } ] }
```

### local_miwlab_get_forum_topics (1.7.0)

Parameter: `courseid`. Needs `local/miwlab:readforumtopics` in the course. For each forum that is not a single simple discussion, the
opening topics started by the staff of the course (`editingteacher`, `teacher`, `manager` in the course or above it, or a site
administrator): `subject`, `message` (HTML; the files of the text stay as `@@PLUGINFILE@@`), `pinned`, `authorrole` (the only thing said
about the person) and the files (`filename`, `mimetype`, `filesize`, `embedded`, `content` in base64). Left out: a discussion of a group or with
a display period, a deleted post, a private reply; at most 50 topics per forum and 500 per course, texts up to 200 000 characters, 10 files per
topic of up to 1 MiB each, 3 MiB of files and 6 MiB of answer in all (what does not fit is counted in `omittedtopics` and `omittedfiles`).

```
{ "courseid": 12, "omittedtopics": 0,
  "forums": [ { "cmid": 87, "type": "general", "name": "Introductions", "omittedtopics": 0,
                "topics": [ { "discussionid": 5, "subject": "Introduce yourself", "message": "<p>...</p>", "pinned": true,
                              "created": 1792397085, "authorrole": "editingteacher", "omittedfiles": 0,
                              "files": [ { "filename": "guide.pdf", "mimetype": "application/pdf", "filesize": 1200,
                                           "embedded": false, "content": "JVBERi0x..." } ] } ] } ] }
```

### Topics in a package (1.7.0)

The MiwLab writes `miwlab/foruns.json` in the package, next to the backup files (Moodle's restore reads only what
`moodle_backup.xml` lists, so it ignores them, and a plugin older than 1.7.0 does too):

```
{ "formato": 1,
  "foruns": [ { "secao": 3, "posicao": 0, "nome": "Questions", "topicos": [
                { "titulo": "Ask here", "texto": "<p>...</p>", "fixado": false,
                  "anexos": [ { "nome": "guide.pdf", "sha1": "<40 hex>", "embutido": false } ] } ] } ] }
```

The forum is found in the restored course by the number of its section, its position among the forums of that section (in the order
of the section) and its name. Each file is `miwlab/foruns/<sha1>`, and is attached only if its SHA-1 is the one named. The restore
and the replacement move `miwlab/` aside before Moodle's restore runs (Moodle deletes the extracted package when it ends) and
create the topics right after it. `local_miwlab_get_restore_status` then returns `topicscreated`, `topicsskipped` and `topicsnote`
(the first reason a topic was not created).

### local_miwlab_get_restore_categories

No parameters. The categories where the account holds the three capabilities of the restore.

```
{ "categories": [ { "id": 2, "name": "Courses", "path": "Top / Courses", "parent": 1, "idnumber": "", "visible": true } ] }
```

### local_miwlab_restore_course

| Parameter | Type | |
|---|---|---|
| categoryid | int | category of the new course |
| draftitemid | int | the `itemid` returned by `webservice/upload.php` |
| filename | string, optional | name of the package in that draft area; empty: the only file there |
| marker | string, optional | MiwLab marker for the course ID number; empty: the one the package carries in `course/course.xml`, if any |

Checks, before queuing: the category exists; the account holds `local/miwlab:restorecourse`,
`moodle/restore:restorecourse` and `moodle/course:create` there (error `error:missingcapabilities`, whose message names
every capability missing); the marker format (`error:invalidmarker`); the package is in the draft area
(`error:draftfilenotfound`, `error:draftfiletoomany`, `error:draftfileempty`). Returns the same structure as the
status, with `"status": "queued"`.

The restore runs in an ad hoc task, as the account, on the next cron run. The package leaves the draft area when the
task ends. If the restore fails, the half-made course is deleted.

**Marker.** A string `miwlab:<id>` or `miwlab:<id>:<suffix>` (letters, digits, `-`, `_`; at most 60 + 20 characters),
written in the course ID number. Moodle drops the ID number of a course restored from another site, so the plugin writes
it once the restore ends. The ID number is unique: if another course already has the marker, the new one gets
`<marker>:<course id>`.

### local_miwlab_get_restore_status

| Parameter | Type |
|---|---|
| restoreid | string, as returned by local_miwlab_restore_course |

Needs `local/miwlab:restorecourse` in the category of the restore (a replacement: `local/miwlab:replacecourse` in the
course). From 1.5.0 it also returns `mode` (`new` or `replace`), `backupfilename`, `rolledback` and, when finished,
`coursesignature` and `contentmodified` (the state of the course right after). From 1.7.0, when finished: `topicscreated`, `topicsskipped` and `topicsnote`.

```
{ "restoreid": "c4bd43968ecbf382849b80491a7c458e",
  "status": "queued" | "running" | "finished" | "failed",
  "progress": 0.42,                // 0 to 1 while running, 1 when finished
  "categoryid": 2,
  "courseid": 3,                   // when finished; 0 otherwise
  "courseurl": "https://moodle.example/course/view.php?id=3",
  "coursefullname": "...", "courseshortname": "...",
  "marker": "miwlab:...:v2",       // the course ID number, when finished
  "errorcode": "", "errormessage": "",   // when failed (errorcode "error:..." or a core code)
  "timecreated": 1792397085, "timestarted": 1792397099, "timefinished": 1792397100 }
```

Failure codes of the plugin: `error:notabackup`, `error:precheck` (Moodle refused the package; the message carries
Moodle's reasons), `error:categorynotfound`, `error:missingcapabilities` (the capabilities changed while it waited),
`error:draftfilenotfound`, `error:stalled` (did not end within 48 hours: check that cron runs), `error:coursedeleted`
(finished, but the course was deleted since). Other codes are Moodle's own, with Moodle's message.

### local_miwlab_get_replace_check (1.5.0)

| Parameter | Type |
|---|---|
| courseid | int |

Needs `local/miwlab:replacecourse` in the course. The course, the counts of people (no person named) and what is missing:

```
{ "courseid": 7, "fullname": "...", "shortname": "...", "categoryid": 2, "categoryname": "...", "visible": true,
  "format": "topics", "courseurl": "https://moodle.example/course/view.php?id=7",
  "enrolled": 12,                  // people enrolled, active or not (the MiwLab account aside)
  "withprogress": 0,               // people with an activity or the course completed, a grade, a submission, an attempt,
                                   // an answer, a post or a note: with one or more and people enrolled, the course is
                                   // not replaced (1.7.2), unless local_miwlab_replace_course is called with forcediscard (1.7.3)
  "missingcapabilities": [],       // capabilities the account lacks in the course to replace it
  "inprogress": false, "canreplace": true, "canhide": true, "idnumber": "",
  "unknownmodules": [],            // modules of activities whose progress the plugin does not read: they refuse replacement when enrolled > 0 (1.7.1)
  "signature": "3f2c…", "contentmodified": 1792397100 }   // as local_miwlab_get_course_state
```

### local_miwlab_replace_course (1.5.0)

| Parameter | Type |
|---|---|
| courseid | int, the course whose content is replaced |
| draftitemid | int, the itemid returned by `webservice/upload.php` |
| filename | string, optional: the name of the package in the draft area |
| marker | string, optional: the MiwLab marker, written in the ID number only when the course has none or a MiwLab one |
| expectedsignature | string, optional: refused (`error:coursechanged`) if the state of the course is another |
| unchangedsince | int, optional: without the signature, refused if the content was edited after this time |
| forcediscard | bool, optional, default false (1.7.3): replace even when somebody has progress, discarding the progress and the grades and keeping the enrolments |

Needs the seven capabilities of the table above in the course (the five of 1.5.0 and, from 1.6.0, the two of the badges). Progress is any trace of a person the deletion would destroy
(completion, any grade of any grade item, submission — a group submission counts for each member of the group, and even
when the group has no member —, assignment grade not released yet, record kept without the person (anonymous answer), attempt, answer, post, glossary entry, wiki page, workshop submission
or assessment, certificate or course badge issued, course competency rated or with evidence, meeting joined, attendance,
note); a course with an activity of a module the plugin does not read is refused too when people are enrolled (`error:unknownmodules`, and
`unknownmodules` in `local_miwlab_get_replace_check`; from 1.7.1, allowed when enrolled is 0). From 1.7.2 progress does not
refuse a course with nobody enrolled, and from 1.7.3 `forcediscard` skips the progress check (at once and in the task): what the
deletion destroys is not in the backup made first, which has no user data. Refused at once with `error:hasprogress`, `error:unknownmodules`,
`error:missingcoursecapabilities`, `error:coursechanged`, `error:replaceinprogress` or `error:coursenotfound` (the site
course is never replaced). Queued like a restore and followed with `local_miwlab_get_restore_status` (`mode` = `replace`).
The task checks capabilities, progress and state again, makes the backup (without user data), checks progress again,
then replaces. The course badges of the old content are deleted for good before the restore (not left as archived site
badges); the ones the package brings arrive active; a failed replacement gives the badges the backup brings back the
status they had (up to Moodle 4.4 a backup without user data has no badges). Failure codes: `error:backupfailed` (nothing replaced), `error:hasprogress`, `error:coursechanged`,
`error:cannotkeepenrolments` (nothing replaced), `error:replacefailed` (the content was deleted and the backup was put back:
the course is as it was; `rolledback` true), `error:rollbackfailed` (the backup could not be put back: restore
`backupfilename` from Course > Restore > Course backup area). When finished, `backupfilename` names the backup in the course
backup area.

### local_miwlab_get_course_state (1.5.0)

| Parameter | Type |
|---|---|
| courseid | int |

Needs `local/miwlab:viewreports` in the course. `{ "courseid": 7, "signature": "<sha1>", "contentmodified": 1792397100,
"activities": 9, "sections": 6 }`. The signature covers each activity (type, instance, section, visibility, access
restriction, completion settings, ID number, group mode, the time it was last edited) and each section (number, name,
summary, order, visibility, restriction); `contentmodified` is the latest edit of a section or an activity, or activity
added. What people do in the course is not part of it. Deleting an activity changes the signature but not the time.

### local_miwlab_hide_course (1.5.0)

| Parameter | Type |
|---|---|
| courseid | int |

Needs `local/miwlab:hidecourse` and `moodle/course:visibility` in the course. Hides it as "Hide" in the course list does;
showing it again is done in Moodle. `{ "courseid": 7, "visible": false, "changed": true }`.

### local_miwlab_backup_course (1.6.0)

| Parameter | Type |
|---|---|
| courseid | int |

Needs `local/miwlab:backupcourse` and `moodle/backup:backupcourse` in the course (refused naming the missing ones,
`error:missingcontentcapabilities`); the site course is refused (`error:coursenotfound`). Queues the backup of the content
of the course (an ad hoc task, run as the account that asked) and returns its status, as `local_miwlab_get_backup_status`.
The backup has no data of a person: users, anonymize, role assignments, comments, user completion, logs, grade history,
groups and badges are set off, and the task fails with `error:backupwithpeople` when the site locks any of them on.
The answer carries `secret`, returned only here: status, parts and deletion of this backup need it (the plugin keeps only
its SHA-256), so only the request that queued the backup gets the file.

### local_miwlab_get_backup_status (1.6.0)

| Parameter | Type |
|---|---|
| backupid | int |
| secret | alphanumeric |

Needs the secret and the same two capabilities in the course of the backup (a wrong secret is answered as
`error:backupnotfound`). `status` is `queued`, `running`, `finished` or `failed`; finished, it carries `filename`,
`filesize` and `contenthash` (SHA-1); failed, `errorcode` and `errormessage`.

### local_miwlab_get_backup_chunk (1.6.0)

| Parameter | Type |
|---|---|
| backupid | int |
| secret | alphanumeric |
| offset | int (default 0) |
| length | int (default and at most 4194304) |

Needs the secret and the same two capabilities. A part of the finished backup file: `{ "offset", "length", "data"
(base64), "eof" }`. The MiwLab reads the parts in order and checks the size and the SHA-1 the status gave.

### local_miwlab_delete_backup (1.6.0)

| Parameter | Type |
|---|---|
| backupid | int |
| secret | alphanumeric |

Needs the secret and the same two capabilities. Deletes the backup file and its record: `{ "deleted": true }`. A scheduled task deletes
the ones kept longer than a day.

### local_miwlab_find_courses

| Parameter | Type |
|---|---|
| marker | string: the marker, or its beginning (`miwlab:<id>` also finds `miwlab:<id>:v2` and `miwlab:<id>:v2:57`, never `miwlab:<id>0`) |

Only courses where the account holds `local/miwlab:viewreports`; at most 100.

```
{ "courses": [ { "id": 3, "categoryid": 2, "fullname": "...", "shortname": "...", "idnumber": "miwlab:...:v2",
                 "visible": false, "url": "https://moodle.example/course/view.php?id=3", "timecreated": 1792397100 } ] }
```

### local_miwlab_get_course_completion

| Parameter | Type |
|---|---|
| courseid | int |

Needs `local/miwlab:viewreports` in the course. A student is an active enrolled user (active enrolment, not suspended,
within its dates) with a role of the student archetype in the course or above it; teachers and suspended students do
not count. The counts are made in the database: not even the ids of the people are read by the plugin.

```
{ "courseid": 3, "completionenabled": true,
  "students": 12,                                   // the total of every count below
  "coursecompletion": { "enabled": false, "completed": 0 },   // course completion criteria, if any
  "sections": [ { "id": 21, "number": 1, "name": "...", "visible": true,
                  "activities": 3,                  // activities of the section with completion tracking
                  "completed": 3 } ],               // students who completed all of them (complete or complete with pass)
  "activities": [ { "cmid": 39, "sectionid": 21, "sectionnumber": 1, "modname": "page", "name": "...", "idnumber": "",
                    "visible": true, "completiontype": 2,   // 1 manual, 2 automatic
                    "completed": 4,                         // complete, or complete with a pass grade
                    "completedfail": 0 } ],                 // complete without the pass grade
  "timegenerated": 1792397300 }
```

Only activities with completion tracking are listed. The plugin returns raw counts; any minimum or rounding is for the
caller to apply.

### local_miwlab_get_courses_overview (1.8.0)

| Parameter | Type |
|---|---|
| categoryids | int[] (1 to 200 categories) |
| includesubcategories | bool, default true |
| limitfrom | int, default 0 (courses to skip, by course id) |
| limitnum | int, default and maximum 500 |

Needs `local/miwlab:viewreports` in the context of **each** category asked (a right given in a category reaches the ones below it);
one category without it refuses the whole call. A subcategory the expansion adds is checked on its own: where the right is prohibited it stays out, and `categoriesleftout` says how many stayed. Counts only, made in the database, in a few queries for the whole page: not even the
ids of the people are read. The front page is never listed.

```
{ "total": 640, "next": 500,                        // courses in the categories; limitfrom of the next page, -1 on the last
  "courses": [ { "id": 7, "categoryid": 3, "fullname": "...", "shortname": "...", "visible": true,
                 "students": 12,                    // active enrolled users with the student archetype in the course or above it
                 "completionenabled": true, "trackedactivities": 2,
                 "measures": true,                  // completion on in the site and in the course, and a tracked activity or criteria
                 "completedby": "activities",       // course (completion criteria), activities (every tracked activity) or none
                 "completed": 9 } ],                // students who completed; 0 when the course does not measure
  "timegenerated": 1792397300 }
```

For the same course the numbers are the ones of `local_miwlab_get_course_completion` (`students` equal; `completed` equal to its
`coursecompletion.completed` when `completedby` is `course`).

### local_miwlab_preview_update (1.9.0)

| Parameter | Type |
|---|---|
| courseid | int |
| operations | string: the list, as a JSON object (up to 4 MB) |
| expectedsignature | string, optional: the state of the course the MiwLab knows (`local_miwlab_get_course_state`); refused with `error:coursechanged` if it changed |
| draftitemid, filename | optional: the draft area with the package of ONLY the new activities and sections (when the list has any) |

Needs the capabilities of `local/miwlab:updatecourse` in the course (all of the table above, the missing ones named in the refusal)
and changes nothing. The list is read and validated whole before anything is looked at: a field it does not know, a value of the wrong
kind, an id that is not an activity, a section or a block **of this course**, a package that is not exactly the activities and sections
the list announces (or that carries an activity of the course) refuses the call. Shape:

```
{ "activities": [ { "cmid": 52, "name": "...", "text": { "field": "content", "html": "..." }, "completion": { ... },
                    "availability": "<json>"|null, "visible": true, "dates": { "duedate": 1790000000 }, "grademax": 100 } ],
  "added": [ { "key": "n1", "module": "page", "name": "...", "section": 12 } ],   // section: the id of the section, or the key of a new one
  "retired": [ { "cmid": 51 } ], "sections": [ { "id": 12, "name": "...", "summary": "..." } ],
  "newsections": [ { "key": "s1", "number": 5, "name": "..." } ], "retiredsections": [ { "id": 14 } ],
  "order": [ { "section": 12, "items": [52, "n1", 53] } ], "sectionorder": [12, "s1", 13],
  "coursesettings": { "completion": { "choice": "keep|replace", "aggregation": 1, "criteria": [ { "type": 7, "courseinstance": 3 } ] },
                      "gradebook": { "choice": "keep|replace", "category": { "aggregation": 13 } },
                      "blocks": [ { "proposal": "add|replace|remove", "choice": "keep|apply", "name": "html", "instanceid": 5, "config": { } } ] } }
```

It returns `previewid`, `signature`, `expires` (30 minutes), `enrolled` and `withprogress` (counts), `unknownmodules` (the modules whose
progress the plugin cannot read: what depends on them is left out), `counts` and `items`: for each operation, its `group`
(`update`, `add`, `skip`, `choice`), what it is (`kind`, `module`, `name`, `section`, `field`), what would be done (`action`), why it is
left out (`reason`), how many people have progress in it (`people`; counts only), the people whose value stays (`kept`), the warnings
that do not block (`warnings`), whether it could be deleted by hand (`suggestdelete`) and, for the course-wide items, `choice`,
`canchoose` and `needsdiscard`. The progress is read **per activity**, with the same queries the replacement uses (the MiwLab account
never counts).

### local_miwlab_apply_update (1.9.0)

| Parameter | Type |
|---|---|
| previewid | string |
| forcediscard | bool, optional, default false: the conscious confirmation that somebody who concluded the course loses it (the preview says it with `needsdiscard`); a full backup is made first |
| keepdays | int, optional, 30 to 365, default 180: the days the full backup is kept, when one is made |

Queues the application of a preview, in the background (the answer is the one of `local_miwlab_get_restore_status`, mode `update`, whose
`updateresult` carries, when it ends, what was done item by item as JSON, with no person). The preview must be the one of this
account for this course, not yet applied and not expired; the course must be as it was when it was previewed
(`error:coursechanged`) and no other operation can be running in it. A preview that replaces the completion criteria of a course somebody
concluded is refused without `forcediscard` (`error:discardnotconfirmed`); with it the full backup is made BEFORE touching the course
(and the call fails before touching it when the site does not allow it, `error:fullbackupnotallowed`). The application checks again,
per activity, the progress of what it is going to change in grades or completion: if somebody did it since the preview, that item is
left out (`progresschanged`). If anything fails, the rows it touched go back, what it created is deleted and the course has the same
signature as before.

### local_miwlab_get_course_feedbacks

| Parameter | Type |
|---|---|
| courseid | int |

Needs `local/miwlab:viewreports` in the course. Lists every feedback with its questions, never answers.

```
{ "courseid": 3, "feedbacks": [ { "id": 3, "cmid": 52, "sectionnumber": 3, "name": "...", "anonymous": true, "visible": true,
    "items": [ { "id": 10, "typ": "multichoicerated", "name": "...", "label": "...", "presentation": "...",
                 "position": 2, "hasvalue": true, "required": true } ] } ] }
```

### local_miwlab_get_feedback_analysis

| Parameter | Type |
|---|---|
| feedbackid | int (the instance id, `id` above) |
| includetext | bool, default false: whether the open answers leave Moodle |

Needs `local/miwlab:viewreports` in the course. Refuses a feedback that is not anonymous (`error:notanonymous`).
Over every participant (no group).

```
{ "feedbackid": 3, "cmid": 52, "courseid": 3, "name": "...", "anonymous": true,
  "completedcount": 12, "itemscount": 6, "textincluded": false,
  "itemsdata": [ { "item": { ...as in local_miwlab_get_course_feedbacks... },
                   "data": [ "{\"answertext\":\"Strongly disagree\",\"answercount\":3,\"avg\":0.25,\"value\":\"1\",\"quotient\":0.25}" ],
                   "withheld": false, "answercount": 0 } ] }
```

`data` follows `mod_feedback_get_analysis`: for `multichoice` and `multichoicerated`, one JSON per option, as Moodle
consolidates it; for `textarea` and `textfield`, the text of each answer, in alphabetical order (not in the order they
arrived), only with `includetext`, otherwise empty with `"withheld": true`; for `numeric`, a single JSON
`{"answercount": n, "average": x}` instead of each value; other types (info, captcha...) are withheld. `answercount` says,
for open and numeric questions, how many non-empty answers there are, also when the text is withheld (0 for the other types).

## Privacy

The plugin implements the privacy API as a null provider. It returns counts and the questions of a feedback, never the
identity of a person; open answers of an anonymous feedback leave Moodle only when the call sets `includetext`. The table
`local_miwlab_restores` keeps the progress of each restore (category, course, marker, status, times), without the account
that asked for it, and a scheduled task deletes each record 30 days after the restore ends. The table `local_miwlab_backups` (1.6.0) keeps the
status of each backup of the content of a course (course, status, file name, size and hash, times), without the account
that asked for it; the backup itself has no data of a person (no users, enrolments, grades, logs or comments) and the file
of the backup is stored with no author, and both are deleted once the MiwLab downloads it, or after a day. The account of the MiwLab key,
when the site administrator generates it, is an ordinary account of the site, with a fictitious e-mail address; the plugin
keeps only its id (and the id of its role), to reuse it and to delete it on uninstall.

What the plugin reads and what it does not read. It reads the structure and the content of a course (the texts, files and
questions a teacher wrote), counts of completion per activity and the consolidated results of an anonymous feedback. It does
**not** read the students: no list of enrolled people, no name, e-mail, grade, submission, quiz attempt, forum reply, log or
completion of a person; the backup of the content of a course is made with all data of people switched off. The one place where a
person's text leaves Moodle is `local_miwlab_get_forum_topics` (1.7.0): the opening topic (title, text, files) of discussions
that the **staff** of a course started, with only the role that made the author staff, never the name; a discussion started by a
student is never read, nor are replies. It is guarded by a capability of its own (`local/miwlab:readforumtopics`) and the plugin
stores none of it. The topics the plugin recreates are ordinary forum posts, kept by Moodle in the forum and authored by the
integration account (so they fall under the privacy handling of the forum, not of this plugin). In the restore table (1.7.0) three
columns count the topics created and skipped and the first reason, with no person in them.

## Development

Source code and bug tracker: https://github.com/V3RTECH-DF/moodle-local_miwlab (issues welcome). The plugin is
developed together with the MiwLab and published here at each release. Each release is checked in disposable Moodles
4.1, 4.5, 5.0, 5.1, 5.2 and 5.3, on PostgreSQL and on MariaDB: installation without debugging messages, the Moodle code
checker and moodle-plugin-ci, PHPUnit, and real web service calls (restore, completion with enrolled, suspended and
teacher accounts, anonymous and identified feedback). The repository runs moodle-plugin-ci on every push
(`.github/workflows/moodle-plugin-ci.yml`).

Translations are welcome through AMOS, the Moodle translation tool (https://lang.moodle.org).

## Documentation

This README is the reference for the plugin: what it creates in Moodle, every web service function with a `curl` example,
every capability with the reason it is needed, the privacy statement and the list of changes by release (`CHANGES.md`). The
MiwLab manual, which each MiwLab installation serves at its own address, has a page about this plugin for the person who
administers the Moodle (installing and updating it, generating, replacing and revoking the key, what each permission is for,
common problems): `/manual/complemento-do-moodle` on the address of the installation (the manual is in Portuguese).

## Copyright and license

2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>. GNU GPL v3 or later (see LICENSE).

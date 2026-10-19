# MiwLab companion plugin for Moodle (local_miwlab)

Lets a MiwLab installation send a finished course to Moodle as a new course and read, as counts only, the activity
completion and the anonymous feedback results of that course, through web service functions.

## Description

The MiwLab is a course design tool, run by each organisation on its own server, separate from Moodle. It builds the course
(competencies, units, activities, rubrics, the feedback survey) and generates a Moodle course backup (.mbz) from a template
course of the organisation's own Moodle. **This plugin is useful only together with the MiwLab**: it has no page of its own
and does nothing until a MiwLab installation calls it with a web service token. Its functions are plain Moodle web services,
though, and can be tried without the MiwLab (see [Testing without MiwLab](#testing-without-miwlab)).

With a token of an integration account, the MiwLab uses the plugin to do three things Moodle core web services do not offer:

1. **Restore a course package** (.mbz) as a new course in a chosen category, in the background (ad hoc task), and follow
   its progress until the address of the new course is known. The course ID number receives a MiwLab marker, so the
   MiwLab finds the course later.
2. **Read consolidated activity completion** of a course: per section and per activity, how many students completed it
   out of how many are enrolled. Counts only, never a person.
3. **Read the consolidated results of an anonymous feedback** (mod_feedback), as Moodle consolidates them. The text of
   open answers leaves Moodle only when the call asks for it. A feedback that is not anonymous is refused.

The plugin makes no network request: it only answers the web service calls it receives. It stores no personal data
(privacy API: null provider); the only thing it keeps is the progress of each restore, without the person who asked
for it, for 30 days after the end. The integration account needs fewer rights with the plugin than without it: neither
`mod/feedback:viewreports` nor `moodle/course:ignoreavailabilityrestrictions`, and no enrolment in the courses it reads.

## Requirements

- Moodle 4.1 to 5.3 (`$plugin->supported = [401, 503]`; tested on 4.1, 4.5, 5.0, 5.1, 5.2 and 5.3).
- PostgreSQL, MariaDB or MySQL (tested on PostgreSQL 17 and MariaDB 11.4 in every version above, and on MySQL 8.4 in
  4.5 and 5.3). The plugin uses only the Moodle database API (DML), with no SQL specific to a database.
- Web services with the REST protocol enabled, and cron running (the restore is an ad hoc task).
- The external system is the MiwLab installation of the organisation, which calls the plugin; the plugin itself calls
  nothing outside Moodle.

## Installation

Site administration > Plugins > Install plugins, with the zip; or unzip it into `local/miwlab` (`public/local/miwlab` from
Moodle 5.1 on) and visit Site administration > Notifications (or run `admin/cli/upgrade.php`). The plugin has no settings
page.

## Configuration after installing (the integration account)

1. Site administration > Server > Web services > Overview: enable web services and the REST protocol.
2. Create an account for the integration (or use the one the MiwLab key already uses) and a role for it.
3. Site administration > Server > Web services > External services: in the service used by the MiwLab key (or a new
   one), add the eight `local_miwlab_*` functions below and enable **Can upload files** (the package is uploaded with
   `webservice/upload.php` before the restore).
4. Give the role of the integration account:
   - `webservice/rest:use`, at site level;
   - to restore: `local/miwlab:restorecourse`, `moodle/restore:restorecourse` and `moodle/course:create`, in each
     category where the MiwLab may create courses (or at site level);
   - to read the consolidated reports: `local/miwlab:viewreports`, at site level (or in the categories or courses).
5. Create a token for the account and service (Site administration > Server > Web services > Manage tokens) and give it
   to the MiwLab, in Organização > Plataforma de ensino (Organisation > Teaching platform). The MiwLab tests the key and
   shows whether the plugin is installed, its version, and in how many categories the account can restore.

With the plugin, the account needs neither `mod/feedback:viewreports` nor `moodle/course:ignoreavailabilityrestrictions`,
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
```

What to expect besides the success cases: an account without the capabilities gets `error:missingcapabilities` (the
message names each capability missing) or `nopermissions`; a feedback that is not anonymous gets `error:notanonymous`; a
file that is not a course backup ends with `"status": "failed"` and `error:notabackup`, and no course is left behind;
a marker that does not start with `miwlab:` gets `error:invalidmarker`.

## Web service functions

Errors come as Moodle web service exceptions (`{"exception": ..., "errorcode": ..., "message": ...}`), with the
`errorcode` stable and the `message` in the language of the account.

### local_miwlab_get_info

No parameters. Detects the plugin.

```
{ "component": "local_miwlab", "version": 2026101902, "release": "1.1.1",
  "moodleversion": "2026100500.02", "moodlerelease": "5.3+ (Build: 20261013)",
  "features": ["restore", "restorestatus", "findcourses", "completion", "feedback"],
  "systemcapabilities": { "restorecourse": false, "viewreports": true } }
```

`features` only grows: a new feature adds a name, and a name never changes meaning.

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

Needs `local/miwlab:restorecourse` in the category of the restore.

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
that asked for it, and a scheduled task deletes each record 30 days after the restore ends.

## Development

Source code and bug tracker: https://github.com/V3RTECH-DF/moodle-local_miwlab (issues welcome). The plugin is
developed together with the MiwLab and published here at each release. Each release is checked in disposable Moodles
4.1, 4.5, 5.0, 5.1, 5.2 and 5.3, on PostgreSQL and on MariaDB: installation without debugging messages, the Moodle code
checker and moodle-plugin-ci, PHPUnit, and real web service calls (restore, completion with enrolled, suspended and
teacher accounts, anonymous and identified feedback). The repository runs moodle-plugin-ci on every push
(`.github/workflows/moodle-plugin-ci.yml`).

Translations are welcome through AMOS, the Moodle translation tool (https://lang.moodle.org).

## Copyright and license

2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>. GNU GPL v3 or later (see LICENSE).


# An announcements system for Moodle

An announcements system (developed as a local plugin) that allows for advanced and complex audience targeting. Used as the primary means of daily communication and tailored to the requirements of Canberra Grammar School. A companion block also exists for displaying latest announcements within courses (See moodle-block_latest_local_announcements).

Key functionality:
 - Announcement creation with attachments
 - Complex and extensible audience types
 - The ability to combine and intersect audiences
 - Advanced configuration of privileges
 - Daily digest emails with branding, processed via scheduled jobs
 - Notifications (web, email, mobile) with individual preferences, processed by scheduled jobs
 - Force send announcements for emergencies
 - Announcements integrated with Moodle search
 - Moderation workflow - approve, reject, defer
 - Moderation from email (tokenised approve button)
 - Moderator assistants
 - Announcement administration, auditing and impersonation
 - Additional recipients

Author
--------
Michael Vangelovski<br/>
<https://github.com/michaelvangelovski><br/>
<http://michaelvangelovski.com>

## Examples

### Posting an announcement
![](/screenshots/local_announcements_post.gif?raw=true)

## Technical overview & configuration

### Global Settings
 - perpage → Number of announcements per page
 - maxbytes → Maximum upload bytes
 - globaldisable → Enable/Disable Digest
 - maxeditorfiles → Default number of editor files allowed per announcement
 - maxattachments → Default number of attachments allowed per announcement
 - shortpost →   Number of characters to truncate message for short message
 - enablenotify → Enable/Disable Notifications.
 - enabledigest → Enable/Disable Digest.
 - digestheaderimage → URL for digest header image
 - digestfooterimage → URL for digest footer image
 - digestfooterimageurl → URL for digest footer image link
 - forcesendheaderimage → Header image URL for immediate (forcesend) announcements
 - showposterallinctx → True/False whether a poster can view all within contexts they can post, even when not specifically targeted.
 - digestfootercredits → Additional HTML placed at the bottom of the digest.

### Audience Providers
Audience providers handle the logic for targeting audiences. Audience providers extend the audience_provider base class and implement a set of required functions that are required by the broader system to interact with audience.

The current audience providers are:

 - audience_mdlcourse.php - handles targeting of announcements to Moodle courses.
 - audience_mdlgroup.php - handles targeting of announcements to groups in Moodle.
 - audience_mdlprofile.php - handles targeting of announcements based on custom user profile fields.
 - audience_mdluser.php - handles targeting of announcements directly to Moodle users.
 - audience_combination.php - handles targeting of announcements to arbitrary combinations of audiences from other audience providers.

### Audience Types
The `ann_audience_types` table is used to define the ways user's can be targeted by announcements via the front end. Each row in the table directly corresponds to a tab in the audience selection interface. 

- type → the key used for the audience type
- namesingluar → the name of the audience type
- nameplural → the plural of the audience type name
- provider → which audience provider handles the logic for this audience type
- active → whether the audience type is enabled
- filterable → whether to show a search field that is used to filter audience items. If true, the audience items are hidden by default and revealed by typing.
- grouped → whether to group the audience items. The value of audience items must be in the following format <grouping><delimiter><item>, e.g. Senior School:Staff. The `groupdelimiter` column is used to define the delimiter, e.g. `:`.
- uisort → tabs are sorted and displayed horizontally according to this value.
- roletypes → a comma separated list of the role types that can be targeted for this audience type. Audience providers handle different roles in different ways, but most cater Students, Mentors, and Staff. The roles that an audience provider caters to is specified in the constant <provider>::ROLES. Aliases can be defined with square brackets, e.g. Mentors[Parents]. Students generally means that you will target users enrolled as students in the selected audiences. Mentors generally means you will target users that are mentors of users that are enrolled as students in the selected audiences. Staff generally means you will target users enrolled as teachers (editing and non-editing), managers, and course creators. 
- scope → Used to narrow the audience items to a specific scope. This limits what audience items are displayed in the audience selector for a given audience type. Each audience provider uses this field in it's own way. Used to limit audience items to certain course categories for a mdlcourse based audience type. Used to specify the profile field for a mdlprofile based audience type.
- description → a field to describe the audience type.
- groupdelimiter → the delimiter used when splitting a value for grouped audiencetypes. See "grouped" above.
- itemsoverride → Used by some providers to override the values presented in the UI to be a fixed set rather than a system generated set. Required for the combination provider as the combination provider displays a fixed set of audience items. Can be used by the mdlprofile provider to specify the possible options for the given profile field, otherwise the system will attempt to generate the possible options by looking at distinct values for all users of the system.
- visiblechecktype → not currently used. To be used to determine whether a tab is displayed to an end user or not. Currently all tabs are displayed whether the tab has any audience items or not for the user (audience items are not loaded until the tab is clicked).
- visiblecheckvalue → not currently used.
- excludecodes → comma separated list of codes (of audience items, e.g. course idnumber) to exclude from the set.

### Privileges
Privileges determine whether a user can post to an audience, and whether their post requires moderation or not. The `ann_privileges` table contains the configuration for privileges. Each row defines a specific check for a given audience type etc.

#### Determining whether a user can post to the selected audience:
When an announcement is posted, the system retrieves the privilege checks that must be performed based on the audiences that were targeted. It then executes the checks one at a time, ordered by `checkorder`, until a check evaluates to true. For example, if an announcement is targeting a course, the system will check whether the user as the "local/announcements:post" capability within that course. If the check returns true, the system will save the announcement and set up moderation based on the moderation columns. If the check returns false, the system will move to the next check. If all checks are false, the announcement is not stored. Note, this should never happen as audiences are not displayed to the user on the front end unless they have privileges to post to that audience (the checks are performed as the audiences are retrieved for the UI).

#### Determining whether moderation is required:
The same privilege checks are used to determine moderation. The checks are executed in order of "checkorder" until a row that matches the audience, roles selected, condition of the announcement is found. Only the first matching check is used to determine whether moderation is required for the given audience.

If an announcement has multiple audiences, and each matches to a privilege check where "modrequired" is true, "modprioirty" is used to determine who should moderate the post.

If the post is an intersection, moderation is not required if moderation is not required for at least one of the audiences within the intersection.

If union the post contains a union, moderation is required if moderation is required for any of the specified audiences.  

#### Privileges configuration table fields: 
 - audiencetype → the audience type this privilege applies to. E.g. course
 - code → the audience code this privilege applies to. E.g. Science-0703-2020
 - role → the audience roles this privilege applies to. E.g. Students. Default is "*" meaning apply to all.
 - condition → whether this privilege applies to standard or intersected audiences. Default is "*" meaning apply to all.
 - forcesend → whether this privilege applies to immediate or digest announcements. Default is "*" meaning apply to all.
 - description → human readable description of the privilege.
 - checktype → the type of check to perform to determine whether user has this privilege. Options: usercapability|coursecapability|username|profilefield|exclude
 - checkvalue → the value of the check, e.g. a capability, username, profilefield, etc.
- checkorder → the order in which to execute the check if there are multiple competing checks.
- modrequired → whether moderation is required for this privilege.
- modthreshold → the number of items matching this privilige before moderation is required.
- modusername → the moderator
- modpriority → the priority of this moderation. Used to determine moderator if announcement has audiences with competing moderation requirements.
- active → whether the privilege check is active.

### Moderator Assistants
Assistants to moderators can action items on behalf of the moderator. They also bypass moderation when sending an announcement that would ordinarily be moderated by the user they assist.

### Impersonators
The `ann_impersonators` table is used to control impersonation capabilities. It contains an index of users that can post announcements on behalf of other users. A wildcard "\" character in the `impersonateuser` field enables the author to search and impersonate any "staff" member. Announcement admins automatically have this ability. This relies on a `CampusRole` custom profile field. Moderation is bypassed if either the author OR the user that is being impersonated does not require moderation. If both users require moderation based on the selected audiences, the author's moderation requirements is used. Users can edit and delete the announcements that they have been impersonated in, however they cannot change the impersonated user to another user. 

### CC Groups
Some users need to be CC'd into audiences they are not directly enrolled or involved in. "CC groups" allows you to include a group of users to the list of ordinary recipients based on the audiences and conditions selected.

#### CC groups configuration table fields: 
- audiencetype → the audience type this cc group applies to.
 - code → the audience code this cc group applies to. Default is "*" meaning apply to all.
 - role → the audience roles this cc group applies to. E.g. Students. Default is "*" meaning apply to all.
 - forcesend → whether this privilege applies to immediate or digest announcements. Default is "*" meaning apply to all.
 - description → human readable description of the privilege.
 - ccgroupid → the id of the group to include in the announcement recipients. Comma-separated for multiple.

### Capabilities
- local/announcements:post → Given to editing teachers, managers, and coursecreators by default in their courses.
- local/announcements:administer → Checked in various functions across the system to allow admin users to do basically anything.
- local/announcements:auditor → Allows users to view all announcements in the system
- local/announcements:emergencyannouncer → Allows users to send immediate (forcesend) announcements without moderation.
- local/announcements:unmoderatedannouncer → Allows users to post announcements without moderation.

### Settings pages
Besides the global settings, administrators have access to a number of custom settings pages.
- /local/announcements/settings/audiencetypes.php
- /local/announcements/settings/ccgroups.php
- /local/announcements/settings/impersonators.php
- /local/announcements/settings/moderatorassistants.php
- /local/announcements/settings/privileges.php
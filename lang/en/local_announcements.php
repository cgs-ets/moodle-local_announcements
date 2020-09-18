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
 * Strings for local_announcements
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
$string['title'] = '';
$string['pluginname'] = 'Announcements';
$string['privacy:metadata'] = 'The announcements block does not store any personal data.';
$string['crontask_digests'] = 'Announcement digests task';
$string['crontask_notifications'] = 'Announcement notifications task';
$string['audiencesettings:heading'] = 'Audience Settings';
$string['audiencesettings:savesuccess'] = 'Audience settings have been saved.';
$string['moderation:heading'] = 'Moderation';

$string['settings_impersonators:heading'] = 'Impersonator Settings';
$string['settings_impersonators:savesuccess'] = 'Impersonator settings have been saved.';
$string['settings_privileges:heading'] = 'Privileges Settings';
$string['settings_privileges:savesuccess'] = 'Privilege settings have been saved.';
$string['settings_ccgroups:heading'] = 'CC Group Settings';
$string['settings_ccgroups:savesuccess'] = 'CC group settings have been saved.';
$string['settings_moderatorassistants:heading'] = 'Moderator Assistant Settings';
$string['settings_moderatorassistants:savesuccess'] = 'Moderator Assistant settings have been saved.';

$string['list:addanewannouncement'] = 'Add a new announcement';
$string['list:moderation'] = 'Moderation';
$string['list:preferences'] = 'Notification preferences';
$string['list:viewallannouncements'] = 'View all of your announcements';
$string['list:noannouncements'] = 'You do not have any announcements';
$string['list:announcementnotfound'] = 'Announcement not found. It may have been deleted.';
$string['list:announcementunavailable'] = 'This post is unavailable because it is outside of the selected display dates. Selected audiences will not see this announcement.';
$string['list:announcementmodpending'] = 'This post is not visible to users because moderation is pending with {$a}.';
$string['list:announcementmodrejected'] = 'This post is unavailable it was rejected by {$a}.';
$string['list:viewmore'] = 'View full message';
$string['list:turnauditoff'] = 'Turn auditing off';
$string['list:turnauditon'] = 'Turn auditing on';
$string['list:viewas'] = 'View as another user';
$string['list:exitviewas'] = 'Exit view as';
$string['list:viewastitle'] = '<i class="fa fa-eye fa-fw" aria-hidden="true"></i> Viewing as {$a}';
$string['list:auditingonicon'] = '<i class="fa fa-eye fa-fw" aria-hidden="true"></i>';
$string['list:auditingofficon'] = '<i class="fa fa-eye-slash fa-fw" aria-hidden="true"></i>';
$string['list:auditingontitle'] = 'You are currently in audit mode which means you see all announcements. Click to view announcements targeted specifically to you.';
$string['list:auditingofftitle'] = 'You are currently viewing announcements targeted specifically to you. Click to turn auditing mode on.';
$string['list:deliveryforcepending'] = 'Scheduled for immediate delivery';
$string['list:deliveryforcesending'] = 'Sending in-progress';
$string['list:deliveryforcemailed'] = 'Delivered via immediate delivery';
$string['list:deliverydigestpending'] = 'Scheduled for delivery via the Daily Digest';
$string['list:deliverydigestsending'] = 'Sending in-progress via the Daily Digest';
$string['list:deliverydigestmailed'] = 'Delivered via the Daily Digest';

$string['messageprovider:notifications'] = 'New announcement posted notifications';
$string['messageprovider:forced'] = 'Emergency announcement notifications';
$string['messageprovider:digests'] = 'Daily Digest';
$string['messageprovider:moderationmail'] = 'Announcement moderation notifications';

$string['config:perpage'] = 'Announcements per page';
$string['config:perpagedesc'] = 'Number of announcements shown per page';
$string['config:maxattachmentsize'] = 'Maximum attachment size';
$string['config:maxattachmentsizedesc'] = 'Maximum size for all announcement attachments on the site';
$string['config:maxattachments'] = 'Maximum number of attachments';
$string['config:maxattachmentsdesc'] = 'Maximum number of attachments allowed per announcement.';
$string['config:maxeditorfiles'] = 'Maximum number of editor files';
$string['config:maxeditorfilesdesc'] = 'Maximum number of editor files allowed per announcement.';
$string['config:globaldisable'] = 'Disable posting';
$string['config:globaldisabledesc'] = 'Enable this setting if you want to completely disable announcement posting for some reason.';
$string['config:enabledigest'] = 'Enable Daily Digest';
$string['config:enablenotify'] = 'Enable Post Notifications';
$string['config:digestheaderimage'] = 'Digest header image';
$string['config:digestheaderimagedesc'] = 'A url to an image file to be added to the digest header';
$string['config:digestfooterimage'] = 'Digest footer image';
$string['config:digestfooterimagedesc'] = 'A url to an image file to be added to the digest footer';
$string['config:digestfooterimageurl'] = 'Digest footer URL';
$string['config:digestfooterimageurldesc'] = 'If provided, a URL to a website that will open when the digest footer is clicked.';
$string['config:digestfootercredits'] = 'Digest footer credits';
$string['config:digestfootercreditsdesc'] = 'Additional HTML placed at the bottom of the digest.';
$string['config:forcesendheaderimage'] = 'Force send header image';
$string['config:forcesendheaderimagedesc'] = 'A url to an image file to be added to the email header for force send announcements.';
$string['config:shortpost'] = 'Short post length';
$string['config:shortpostdesc'] = 'Number of characters to truncate message when not in full view.';
$string['config:showposterallinctx'] = 'Show poster all in a course context';
$string['config:showposterallinctxdesc'] =  'If this is enabled, user\'s with "local/announcements:post" capability in a course context, for example teachers and managers, will see all latest announcements in that course, regardless of whether they are included in the audience list.';
$string['config:cronsendnum'] = 'Cron send number';
$string['config:cronsendnumdesc'] = 'Number of notifications to process in a single cron task.';


$string['announcements:post'] = 'Post announcements';
$string['announcements:administer'] = 'Administer announcements';
$string['announcements:auditor'] = 'Audit announcements';
$string['announcements:emergencyannouncer'] = 'Send immediate announcements without moderation.';
$string['announcements:unmoderatedannouncer'] = 'Send announcements without moderation.';

$string['postform:yournewpost'] = 'Your new announcement';
$string['postform:subject'] = 'Subject';
$string['postform:message'] = 'Message';
$string['postform:forcesend'] = 'Force send';
$string['postform:forcesendnote'] = 'Override individual preferences and email a copy of this announcement immediately. This should only be used for emergencies.';
$string['postform:remail'] = 'Resend in digest';
$string['postform:remailnote'] = 'You are editing an announcement that has already been mailed. Select this option if you want include this announcement in the next daily digest.';
$string['postform:attachment'] = 'Attachment';
$string['postform:attachment_help'] = 'You can optionally attach one or more files to an announcement post. If you attach an image, it will be displayed after the message.';
$string['postform:displaysettings'] = 'Display settings';
$string['postform:displaystart'] = 'Display start';
$string['postform:displayend'] = 'Display end';
$string['postform:areaannouncement'] = 'Announcement';
$string['postform:areaattachment'] = 'Attachment';
$string['postform:post'] = 'Post';
$string['postform:timestartenderror'] = 'Display end date cannot be earlier than the start date';
$string['postform:erroremptysubject'] = 'Post subject cannot be empty';
$string['postform:erroremptymessage'] = 'Post message cannot be empty';
$string['postform:errornoaudienceselected'] = 'You must select an audience.';
$string['postform:postaddedsuccess'] = 'Your announcement was successfully added.';
$string['postform:postupdatesuccess'] = 'Your post was successfully updated.';
$string['postform:impersonate'] = 'Send as';
$string['postform:impersonatenoselection'] = 'No selection - this post will be sent from your own account.';
$string['postform:impersonateplaceholder'] = 'Search by name';
$string['postform:impersonateplaceholderdd'] = 'Select a user';

$string['audienceselector:heading'] = 'Audience';
$string['audienceselector:info'] = 'Target your announcement to a specific audience. Use the buttons below to browse audience types and select from a list of available options.';
$string['audienceselector:addaudience'] = 'Add audience';
$string['audienceselector:createintersection'] = 'Create an intersection';
$string['audienceselector:continueintersection'] = 'Continue intersection';
$string['audienceselector:finishintersection'] = 'Finish intersection';
$string['audienceselector:cancelintersection'] = 'Cancel intersection';
$string['audienceselector:noaudienceitems'] = 'No associations found.';
$string['audienceselector:audienceitemsinfo'] = 'Select {$a} you want to target';
$string['audienceselector:roleitemsinfo'] = 'Select the roles you want to target in the selected audiences';
$string['audienceselector:filterplaceholder'] = 'Search for a {$a} to see results...';

$string['digest:mailsubject'] = 'My Daily Announcements from CGS Connect';
$string['digest:textmailheader'] = 'This is your daily digest of new announcements. To change your default email preferences, go to {$a}.';
$string['digest:textpostedit'] = 'To edit this post, go to {$a}.';
$string['digest:textdivider'] = "\n=====================================================================\n";
$string['digest:smallmessage'] = 'Announcements digest containing {$a} messages';

$string['notification:mailsubject'] = 'Announcement from CGS Connect';
$string['notification:subject'] = '{$a->subject}';
$string['notification:smallmessage'] = '{$a->subject}';

$string['moderation:mailsubject'] = 'Moderation required for: {$a->subject}';
$string['moderation:rejectedmailsubject'] = 'Announcement rejected: {$a}';
$string['moderation:approvedmailsubject'] = 'Announcement approved: {$a}';

$string['search:post'] = 'Announcements';

$string['error:postingdisabled'] = 'Posting announcements is disabled.';

$string['tokenmod:approvesuccess'] = '<div style="padding: 15px 20px 18px; color: #155724; background-color: #d4edda; border-color: #c3e6cb; text-align: center; max-width: 800px; margin: 25px auto; font-size: 19px; font-family: sans-serif;">The announcement has been successfully approved. This window should close automatically.</div>';
$string['tokenmod:approvefail'] = '<div style="padding: 15px 20px 18px; color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; text-align: center; max-width: 800px; margin: 25px auto; font-size: 19px; font-family: sans-serif;">This task has already been actioned, or you do not have permission to access it. This window should close automatically.</div>';
$string['tokenmod:errorurl'] = '<div style="padding: 15px 20px 18px; color: #383d41; background-color: #e2e3e5; border-color:#d6d8db; text-align: center; max-width: 800px; margin: 25px auto; font-size: 19px; font-family: sans-serif;">Error with moderation request. This window should close automatically.</div>';
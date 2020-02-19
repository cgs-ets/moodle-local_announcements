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
 * Provides the local_announcements/moderation module
 *
 * @package   local_announcements
 * @category  output
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @module local_announcements/moderation
 */
define(['jquery', 'core/log', 'core/config', 'core/ajax','core/templates', 'core/modal_factory', 'core/modal_events' ], 
        function($, Log, Config, Ajax, Templates, ModalFactory, ModalEvents) {    
    'use strict';

    /**
     * Initializes the moderation component.
     */
    function init() {
        Log.debug('local_announcements/moderation: initializing');

        var rootel = $('.lann-moderation').first();

        if (!rootel.length) {
            Log.error('local_announcements/moderation: .local_announcements element not found!');
            return;
        }

        var moderation = new Moderation(rootel);
        moderation.main();
    }


    /**
     * The constructor
     *
     * @constructor
     * @param {jQuery} rootel
     */
    function Moderation(rootel) {
        var self = this;
        self.rootel = rootel;

        self.modals = {
            APPROVE: null,
            REJECT: null,
            DEFER: null,
            VIEWUSERS: null,
        };
        self.templates = {
            REJECT: 'local_announcements/moderation_modal_reject',
            DEFER: 'local_announcements/moderation_modal_defer',
            VIEWUSERS: 'local_announcements/announcement_users_list',
        };

        self.ispagesingle = $('body').hasClass('moderation-single');

        //versioned name to force refetch templates after updates and prevent them being pulled from browser cache. 
        self.ver = 'local_announcements_2019072200';
    }

    /**
     * Run the Audience Selector.
     *
     */
   Moderation.prototype.main = function () {
        var self = this;

        // Handle approve button click.
        self.rootel.on('click', '.announcement .action-approve', function(e) {
            e.preventDefault();
            var button = $(this);
            self.approveAnnouncement(button);
        });

        // Handle reject announcement button click.
        self.rootel.on('click', '.announcement .action-reject', function(e) {
            e.preventDefault();
            var button = $(this);
            self.rejectAnnouncement(button);
        });

        // Handle defer announcement button click.
        self.rootel.on('click', '.announcement .action-defer', function(e) {
            e.preventDefault();
            var button = $(this);
            self.deferAnnouncement(button);
        });

        // Handle view users button click.
        self.rootel.on('click', '.announcement .action-viewusers', function(e) {
            e.preventDefault();
            var button = $(this);
            self.viewAnnouncementUsers(button);
        });

        // Preload the modals and templates.
        var preloads = [];
        preloads.push(self.loadModal('APPROVE', 'Approve Announcement', 'Approve', ModalFactory.types.SAVE_CANCEL));
        preloads.push(self.loadModal('REJECT', 'Reject Announcement', 'Reject', ModalFactory.types.SAVE_CANCEL));
        preloads.push(self.loadModal('DEFER', 'Reassign Moderation', 'Reassign', ModalFactory.types.SAVE_CANCEL));
        preloads.push(self.loadModal('ERROR', 'Error', '', ModalFactory.types.DEFAULT));
        preloads.push(self.loadModal('VIEWUSERS', 'Announcement Recipients'));
        preloads.push(self.loadTemplate('REJECT'));
        preloads.push(self.loadTemplate('VIEWUSERS'));
        // Do not show actions until all modals and templates are preloaded.
        $.when.apply($, preloads).then(function() {
            self.rootel.addClass('preloads-completed');
        })

    };

    /**
     * Approve an announcement.
     *
     * @method approveAnnouncement
     */
    Moderation.prototype.approveAnnouncement = function (button) {
        var self = this;

        var announcement = button.closest('.announcement');
        var subject = announcement.find('.subject').first().html();
        var id = announcement.data('id');

        if (self.modals.APPROVE) {
            self.modals.APPROVE.setBody('<p>Please confirm that you want to approve:<br><span style="font-style:italic;">' + subject + '</span></p>');
            self.modals.APPROVE.getRoot().on(ModalEvents.save, function(e) {
                Ajax.call([{
                    methodname: 'local_announcements_mod_approve',
                    args: { id: id },
                    done: function(response) {
                        announcement.addClass('removing');
                        announcement.fadeOut(1000, function() {
                            announcement.remove();
                            if (self.ispagesingle) {
                                window.location.href = Config.wwwroot + "/local/announcements/moderation.php";
                            }
                        });
                    },
                    fail: function(reason) {
                        Log.error('local_announcements/moderation: failed to approve the announcement');
                        Log.debug(reason);
                    }
                }]);
            });
            self.modals.APPROVE.show();
        }
    };

    /**
     * Reject an announcement.
     *
     * @method rejectAnnouncement
     */
    Moderation.prototype.rejectAnnouncement = function (button) {
        var self = this;

        var announcement = button.closest('.announcement');
        var data = {
            subject: announcement.find('.subject').first().html(),
            author: announcement.find('.author').first().html(),
        };
        var id = announcement.data('id');

        if (self.modals.REJECT) {
            Templates.render(self.templates.REJECT, data, self.ver).done(function(html){ 
                self.modals.REJECT.setBody(html);
                var rejectcomment = $('[name="reject-comment"]');
                self.modals.REJECT.getRoot().on(ModalEvents.save, function(e) {
                    if (rejectcomment.val() == "") {
                        e.preventDefault();
                        rejectcomment.addClass('is-invalid');
                        return;
                    }
                    Ajax.call([{
                        methodname: 'local_announcements_mod_reject',
                        args: { 
                            id: id,
                            comment: rejectcomment.val(),
                        },
                        done: function(response) {
                            announcement.addClass('removing');
                            announcement.fadeOut(1000, function() {
                                announcement.remove();
                                if (self.ispagesingle) {
                                    window.location.href = Config.wwwroot + "/local/announcements/moderation.php";
                                }
                            });
                        },
                        fail: function(reason) {
                            Log.error('local_announcements/moderation: failed to reject the announcement');
                            Log.debug(reason);
                        }
                    }]);
                });
            });
            self.modals.REJECT.show();
        }
    };

    /**
     * Reassign an announcement.
     *
     * @method rejectAnnouncement
     */
    Moderation.prototype.deferAnnouncement = function (button) {
        var self = this;
        var announcement = button.closest('.announcement');
        var id = announcement.data('id');

        if (self.modals.DEFER) {
            // Get a list of alternate moderators for this post.
            var altmods = Ajax.call([{
                methodname: 'local_announcements_get_alternate_moderators',
                args: { postid: id },
                done: function(altmodlist) {
                    if (altmodlist['moderators'].length == 0) {
                        self.showError('<p>There are no moderators with equivalent or higher privileges than you.</p>');
                        return;
                    }
                    var data = {
                        subject: announcement.find('.subject').first().html(),
                        author: announcement.find('.author').first().html(),
                        moderators: altmodlist,
                    };
                    // Render the template with the list of moderators.
                    Templates.render(self.templates.DEFER, data, self.ver).done(function(html) { 
                        self.modals.DEFER.setBody(html);
                        var defermoderator = $('[name="defer-moderator"]');
                        var defermoderatorval = defermoderator.children("option:selected").val();
                        var defercommentval = $('[name="defer-comment"]').val();
                        self.modals.DEFER.getRoot().on(ModalEvents.save, function(e) {
                            if (defermoderatorval == "") {
                                e.preventDefault();
                                defermoderator.addClass('is-invalid');
                                return;
                            }
                            // Submit.
                            Ajax.call([{
                                methodname: 'local_announcements_mod_defer',
                                args: { 
                                    id: id,
                                    moderator: defermoderatorval,
                                    comment: defercommentval,
                                },
                                done: function(response) {
                                    announcement.addClass('removing');
                                    announcement.fadeOut(1000, function() {
                                        announcement.remove();
                                        if (self.ispagesingle) {
                                            window.location.href = Config.wwwroot + "/local/announcements/moderation.php";
                                        }
                                    });
                                },
                                fail: function(reason) {
                                    Log.error('local_announcements/moderation: failed to reassign the announcement');
                                    Log.debug(reason);
                                }
                            }]);
                        });
                    });
                    self.modals.DEFER.show();
                },
                fail: function(reason) {
                    Log.error('local_announcements/moderation: failed to get the list of alternate moderators');
                    Log.debug(reason);
                    self.showError('<p>Failed to retrieve a list of alternate moderators.</p>');
                }
            }]);
        }
    };

    /**
     * View a list of users for an announcement
     *
     * @method
     */
    Moderation.prototype.viewAnnouncementUsers = function (button) {
        var self = this;

        var announcement = button.closest('.announcement');
        var id = announcement.data('id');

        if (self.modals.VIEWUSERS) {
            self.modals.VIEWUSERS.setBody('<div style="font-style:italic;">... Fetching user list ...<div class="loader" style="display:block;"><div class="circle spin"></div></div></div>');
            self.modals.VIEWUSERS.show();
            Ajax.call([{
                methodname: 'local_announcements_get_announcement_users',
                args: { id: id },
                done: function(response) {
                    Templates.render(self.templates.VIEWUSERS, response, self.ver)
                        .done(function(html) {
                            self.modals.VIEWUSERS.setBody(html);
                        })
                        .fail(function(reason) {
                            Log.debug(reason);
                            return "Failed to render announcement user list."
                        });
                },
                fail: function(reason) {
                    Log.error('local_announcements/list: unable to get announcemnt users.');
                    Log.debug(reason);
                }
            }]);
        }
    };

    /**
     * Helper used to preload a modal
     *
     * @method loadModal
     * @param {string} modalkey The property of the global modals variable
     * @param {string} title The title of the modal
     * @param {string} title The button text of the modal
     * @return {object} jQuery promise
     */
    Moderation.prototype.loadModal = function (modalkey, title, buttontext, type) {
        var self = this;
        return ModalFactory.create({type: type}).then(function(modal) {
            modal.setTitle(title);
            if (buttontext) {
                modal.setSaveButtonText(buttontext);
            }
            self.modals[modalkey] = modal;
            // Preload backgrop.
            modal.getBackdrop();
        });
    }

    /**
     * Helper used to preload a template
     *
     * @method loadTemplate
     * @param {string} templatekey The property of the global templates variable
     * @return {object} jQuery promise
     */
    Moderation.prototype.loadTemplate = function (templatekey) {
        var self = this;
        return Templates.render(self.templates[templatekey], {}, self.ver);
    }


    /**
     * Helper used to display error modal
     *
     * @method showError
     * @param {string} message
     */
    Moderation.prototype.showError = function (message) {
        var self = this;
        self.modals.ERROR.setBody(message);
        self.modals.ERROR.show();
    }
    

    return {
        init: init
    };
});
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
 * Provides the local_announcements/list module
 *
 * @package   local_announcements
 * @category  output
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @module local_announcements/list
 */
define(['jquery', 'core/log', 'core/config', 'core/ajax','core/templates', 
        'core/str', 'core/modal_factory', 'core/modal_events' ], 
        function($, Log, Config, Ajax, Templates, Str, ModalFactory, ModalEvents) {    
    'use strict';

    /**
     * Initializes the list component.
     */
    function init() {
        Log.debug('local_announcements/list: initializing');

        var rootel = $('.local_announcements').first();

        if (!rootel.length) {
            Log.error('local_announcements/list: .local_announcements element not found!');
            return;
        }

        var list = new List(rootel);
        list.main();
    }


    /**
     * The constructor
     *
     * @constructor
     * @param {jQuery} rootel
     */
    function List(rootel) {
        var self = this;
        self.rootel = rootel;

        self.modals = {
            DELETE: null,
            VIEWUSERS: null,
            VIEWAS: null,
        };
        self.templates = {
            VIEWUSERS: 'local_announcements/announcement_users_list',
        };
    }


    /**
     * Run the Audience Selector.
     *
     */
   List.prototype.main = function () {
        var self = this;

        // Handle delete announcement button click.
        self.rootel.on('click', '.announcement .action-delete', function(e) {
            e.preventDefault();
            var button = $(this);
            self.deleteAnnouncement(button);
        });

        // Handle view users button click.
        self.rootel.on('click', '.announcement .action-viewusers', function(e) {
            e.preventDefault();
            var button = $(this);
            self.viewAnnouncementUsers(button);
        });

        // Handle view as button click.
        self.rootel.on('click', '.option-viewas', function(e) {
            e.preventDefault();
            self.viewAs();
        });

        // Preload the modals and templates.
        var preloads = [];
        preloads.push(self.loadModal('DELETE', 'Delete Announcement', 'Delete', ModalFactory.types.SAVE_CANCEL));
        preloads.push(self.loadModal('VIEWUSERS', 'Announcement Recipients', '', ModalFactory.types.DEFAULT));
        preloads.push(self.loadModal('VIEWAS', 'View as another user', 'Go', ModalFactory.types.SAVE_CANCEL));
        preloads.push(self.loadTemplate('VIEWUSERS'));
        // Do not show actions until all modals and templates are preloaded.
        $.when.apply($, preloads).then(function() {
            self.rootel.addClass('preloads-completed');
        })

        // set up infinite scroll
        if(typeof InfiniteScroll != 'undefined') {
          var infScroll = new InfiniteScroll( '.lann-list', {
            // options
            path: '.next',
            append: '.announcement',
            history: false,
            status: '.page-load-status',
          });
        }

    };

    /**
     * View a list of users for an announcement
     *
     * @method
     */
    List.prototype.viewAs = function () {
        var self = this;

        if (self.modals.VIEWAS) {
            self.modals.VIEWAS.setBody('<p>Enter the ID of a user:</p><p><input class="form-control" id="viewas-user" size="48"/></p>');
            self.modals.VIEWAS.getRoot().on(ModalEvents.save, function(e) {
              var viewas = $('input#viewas-user').val();
              window.location.href = Config.wwwroot + "/local/announcements/index.php?viewas=" + viewas;
            });
            self.modals.VIEWAS.show();
        }
    };

    /**
     * View a list of users for an announcement
     *
     * @method
     */
    List.prototype.viewAnnouncementUsers = function (button) {
        var self = this;

        var announcement = button.closest('.announcement');
        var id = announcement.data('id');

        if (self.modals.VIEWUSERS) {
            self.modals.VIEWUSERS.getModal().addClass('modal-xl');
            self.modals.VIEWUSERS.setBody('<div style="font-style:italic;">... Fetching user list ...<div class="loader" style="display:block;"><div class="circle spin"></div></div></div>');
            self.modals.VIEWUSERS.show();
            Ajax.call([{
                methodname: 'local_announcements_get_announcement_users',
                args: { id: id },
                done: function(response) {
                    var count = Object.keys(response['userslist']).length;
                    self.modals.VIEWUSERS.setTitle('Audience Recipients (' + count + ')');
                    Templates.render(self.templates.VIEWUSERS, response)
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
     * Delete an announcement.
     *
     * @method
     */
    List.prototype.deleteAnnouncement = function (button) {
        var self = this;

        var announcement = button.closest('.announcement');
        var subject = announcement.find('.subject').first().html();
        var id = announcement.data('id');

        if (self.modals.DELETE) {
            self.modals.DELETE.setBody('<p>Please confirm that you want to delete:<br><span style="font-style:italic;">' + subject + '</span></p>');
            self.modals.DELETE.getRoot().on(ModalEvents.save, function(e) {
                Ajax.call([{
                    methodname: 'local_announcements_delete_announcement',
                    args: { id: id },
                    done: function(response) {
                        announcement.addClass('removing');
                        announcement.fadeOut(1000, function() {
                            $(this).remove();
                        });
                    },
                    fail: function(reason) {
                        Log.error('local_announcements/list: unable to delete the announcement');
                        Log.debug(reason);
                    }
                }]);
            });
            self.modals.DELETE.show();
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
    List.prototype.loadModal = function (modalkey, title, buttontext, type) {
        var self = this;
        return ModalFactory.create({type: type}).then(function(modal) {
            modal.setTitle(title);
            if (buttontext) {
                modal.setSaveButtonText(buttontext);
            }
            self.modals[modalkey] = modal;
            // Preload backgrop.
            modal.getBackdrop();
            modal.getRoot().addClass('modal-' + modalkey);
        });
    }

    /**
     * Helper used to preload a template
     *
     * @method loadModal
     * @param {string} templatekey The property of the global templates variable
     * @return {object} jQuery promise
     */
    List.prototype.loadTemplate = function (templatekey) {
        var self = this;
        return Templates.render(self.templates[templatekey], {});
    }

    return {
        init: init
    };
});
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
 * Provides the local_announcements/post module
 *
 * @package   local_announcements
 * @category  output
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @module local_announcements/post
 */
define(['jquery', 'local_announcements/audienceselector', 'core/log', 'core/modal_factory', 'core/modal_events', 'core/templates'], 
    function($, AudienceSelector, Log, ModalFactory, ModalEvents, Templates) {    
    'use strict';

    /**
     * Initializes the post component.
     */
    function init() {
        Log.debug('local_announcements/post: initializing');

        var rootel = $('#page-local-announcements-post');

        if (!rootel.length) {
            Log.error('local_announcements/post: #page-local-announcements-post not found!');
            return;
        }

        var post = new Post(rootel);
        post.main();
    }


    /**
     * The constructor
     *
     * @constructor
     * @param {jQuery} rootel
     */
    function Post(rootel) {
        var self = this;
        self.rootel = rootel;

        //versioned name to force refetch templates after updates and prevent them being pulled from browser cache. 
        self.ver = 'local_announcements_2019072200';
    }

    /**
     * Run the Audience Selector.
     *
     */
   Post.prototype.main = function () {
        var self = this;
        var cancel = false;

        // Initialise audience selector.
        AudienceSelector.init();

        // Handle submit. Adding a click event to the post button causes it to not work
        // so event is on form submit instead. 
        self.rootel.on('submit', 'form[data-form=lann-post]', function(e) {
            if (!cancel) {
                var nativeform = this;
                e.preventDefault();
                self.handleSubmit(nativeform);
            }
        });

        // Handle cancel.
        self.rootel.on('click', 'form[data-form=lann-post] input[name="cancel"]', function(e) {
            cancel = true;
        });

    };

    /**
     * Check if user is building an audience before submitting.
     *
     * @method handleSubmit
     */
    Post.prototype.handleSubmit = function (form) {
        var self = this;
        var audienceselector = self.rootel.find('.audience-selector').first();

        // Reset any warnings.
        var haswarnings = false;
        $('.roles>ul').removeClass('warn');

        self.rootel.find('.contents').each(function() {
            // Get selected items.
            var selecteditems = $(this).find('.item:checked');

            // Get selected roles.
            var selectedroles = $(this).find('.role:checked');

            // If user has selected items but no roles, add a red border.
            var hasroles = $(this).data('audiencehasroles');
            if (hasroles && selecteditems.length > 0 && selectedroles.length == 0) {
                haswarnings = true;
                audienceselector.find('.roles>ul').addClass('warn');
            }

            // Check if mid intersection.
            var intersections = audienceselector.find('.intersection-workspace.has-tags');
            if (intersections.length) {
                haswarnings = true;
                intersections.addClass('warn');
            }

        });

       
        if (!haswarnings) {
            $('.lann-post-overlay').addClass('active');
            form.submit();
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
    /*Post.prototype.loadModal = function (modalkey, title, buttontext) {
        var self = this;
        return ModalFactory.create({type: ModalFactory.types.SAVE_CANCEL}).then(function(modal) {
            modal.setTitle(title);
            modal.setSaveButtonText(buttontext);
            self.modals[modalkey] = modal;
            // Preload backgrop.
            modal.getBackdrop();
        });
    }*/

    /**
     * Helper used to preload a template
     *
     * @method loadTemplate
     * @param {string} templatekey The property of the global templates variable
     * @return {object} jQuery promise
     */
    /*Post.prototype.loadTemplate = function (templatekey) {
        var self = this;
        return Templates.render(self.templates[templatekey], {}, self.ver);
    }*/


    return {
        init: init
    };
});
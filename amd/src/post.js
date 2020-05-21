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
define(['jquery', 'local_announcements/audienceselector', 'local_announcements/impersonateselector', 'core/log', 'core/modal_factory', 'core/modal_events', 'core/templates', 'core/form-autocomplete'], 
    function($, AudienceSelector, ImpersonateSelector, Log, ModalFactory, ModalEvents, Templates, AutoComplete) {    
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

        // Initialise the impersonate selector.
        ImpersonateSelector.init();

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

        // Fix for bug: when editing an announcement that has an impersonate value, the autocomplete
        // field does not initialise correctly. The click event to remove the value does not work 
        // and the initial value is not set in the underlying select. To make the remove work, the input 
        // must be triggered in some way causing the selected list to be rerendered. This fix 
        // triggers a click event on the drop down causing a rerender of the autocompete field. A blur event
        // on the input then hides the suggestions but also causes the initial selections to be deselected.
        // The selection is reselected. The select to The timeout is added because the fix won't work if 
        // the autocomplete field hasn't been initialised yet. A hacky downside to this is that it shifts the 
        // field focus and the user could be in the process of typing in another field.
        /*setTimeout(function(){ 
            var imroot = self.rootel.find('select[name="impersonate"]').parent();
            // Only perform this check when editing an announcement and there is a selected value.
            if ( self.rootel.find('input[name="edit"]').val() > 0 && imroot.find('[role="listitem"]').length > 0) {
                imroot.find('.form-autocomplete-downarrow').click();
                imroot.find('input').blur();
                var iminitialval = self.rootel.find('input[name="initialimpersonate"]').val();
                self.rootel.find('select[name="impersonate"]').val(iminitialval);
            }
            
        }, 3000);*/

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

    return {
        init: init
    };
});
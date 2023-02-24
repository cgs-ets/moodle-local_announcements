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
 * Provides the local_announcements/audienceselector module
 *
 * @package   local_announcements
 * @category  output
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @module local_announcements/audienceselector
 */
define(['jquery', 'core/log', 'core/ajax','core/templates', 'core/str', 'core/modal_factory', 'core/modal_events'], function($, Log, Ajax, Templates, Str, ModalFactory, ModalEvents) {
    'use strict';

    /**
     * Initializes the audienceselector component.
     */
    function init() {
        Log.debug('local_announcements/audienceselector: initializing the audience-selector component');

        var rootel = $('.audience-selector').first();

        if (!rootel.length) {
            Log.error('local_announcements/audienceselector: audience-selector root element not found!');
            return;
        }

        var audienceselector = new AudienceSelector(rootel);
        audienceselector.main();
    }

    /**
     * The audience selector constructor
     *
     * @constructor
     * @param {jQuery} rootel
     */
    function AudienceSelector(rootel) {
        var self = this;
        self.rootel = rootel;
        self.component = 'local_announcements';

        // Global vars.
        self.selectedtabcode = '';
        self.intersectionjson = '';

        self.modals = {
            VIEWUSERS: null,
        };
        self.templates = {
            VIEWUSERS: 'local_announcements/announcement_users_list',
        };

        // Get some strings for future use.
        self.strings = {}
        Str.get_strings([
            {key: 'audienceselector:continueintersection', component: self.component},
            {key: 'audienceselector:createintersection', component: self.component},
        ]).then(function(s) {
            self.strings.continueintersection = s[0];
            self.strings.createintersection = s[1];
        });
    }

    /**
     * Run the Audience Selector.
     *
     */
   AudienceSelector.prototype.main = function () {
        var self = this;

        // Render existing tags (if editing announcement).
        self.renderTags();

        // Handle audience type tab click.
        var tabs = self.rootel.find('.audience-tab');
        tabs.on('click', function(e) {
            e.preventDefault();
            var tab = $(this);
            self.openTab(tab);
        });

        // Handle add audience button click.
        self.rootel.on('click', '.audience-add-btn', function(e) {
            e.preventDefault();
            var button = $(this);
            self.addTag(button);
        });

        // Handle remove tags button click.
        self.rootel.on('click', '.remove-tag', function(e) {
            e.preventDefault();
            var button = $(this);
            self.removeTag(button);
        });

        // Handle add to intersection button click.
        self.rootel.on('click', '.audience-intersect-btn', function(e) {
            e.preventDefault();
            var button = $(this);
            self.addIntersection(button);
        });

        // Handle finish intersection button click.
        self.rootel.on('click', '.intersection-finish-btn', function(e) {
            e.preventDefault();
            self.finishIntersection();
        });

        // Handle cancel intersection button click.
        self.rootel.on('click', '.intersection-cancel-btn', function(e) {
            e.preventDefault();
            self.clearIntersectionWorkspace();
        });

        // Handle moderation rule toggle.
        self.rootel.on('click', '.rule-toggle', function(e) {
            e.preventDefault();
            var button = $(this);
            self.showModerationRule(button);
        });

        // Handle key on filter
        var keytimer;
        self.rootel.on('keyup', '.audience-filter', function(e) {
            clearTimeout(keytimer);
            var filterEl = $(this);
            keytimer = setTimeout(function () {
                self.handleFitlerChange(filterEl);
            }, 500);
        });

        // Handle audience item selection
        self.rootel.on('change', 'input:checkbox', function(e) {
            var item = $(this);
            self.handleItemChange(item);
        });

        // Handle view user list click
        self.rootel.on('click', '#action-viewusers', function(e) {
            e.preventDefault();
            self.getUserList();
        });


        self.checkForOriginCourse();

        // Preload the modals and templates.
        var preloads = [];
        preloads.push(self.loadModal('VIEWUSERS', 'Preview recipients', '', ModalFactory.types.DEFAULT));
        preloads.push(self.loadTemplate('VIEWUSERS'));
        // Do not show actions until all modals and templates are preloaded.
        $.when.apply($, preloads).then(function() {
            self.rootel.addClass('preloads-completed');
        })

    };


    /**
     * Open and fill audience provider tab.
     *
     * @method
     */
    AudienceSelector.prototype.openTab = function (tab, preselect) {
        var self = this;

        // Get handles to needed elements
        var type = tab.data('audiencetype');
        var contents = self.rootel.find('.contents[data-audiencetype="' + type +'"]').first();

        var alreadyLoaded = contents.hasClass('loaded');
        var currentlyLoadingTab = ( contents.parent().hasClass('loading') && self.selectedtabcode == type );

        // Load the audience type data if not already loaded or currently loading.
        if ( !alreadyLoaded && !currentlyLoadingTab ) {
            self.selectedtabcode = type;
            // Load the audience type contents
            self.getAudienceItems(tab, contents, preselect);
            // Add the loading animation
            contents.parent().addClass('loading');
        }

        //Hide all contents and show the relevant one
        self.rootel.find('.contents').removeClass('selected');
        contents.addClass('selected');

    };


    /**
     * Get the list of audience associations for the selected audience.
     *
     * @method
     */
    AudienceSelector.prototype.getAudienceItems = function (tab, contents, preselect) {
        var self = this;
        var type = tab.data('audiencetype');
        var namesingular = contents.data('audiencenamesingular');
        var nameplural = contents.data('audiencenameplural');
        var items = contents.find('.items').first();

        Ajax.call([{
            methodname: 'local_announcements_get_audience_items',
            args: { type: type },
            done: function(response) {
                // Render the audience items.
                var template = 'local_announcements/audience_list';
                if (response.grouped) {
                    var template = 'local_announcements/audience_list_tree';
                }
                Templates.render(template, response) 
                    .then(function(html) {
                        if (response.audiencelist.length || response.audiencelistgrouped.length) {
                            items.html(html);
                            if (preselect) {
                                Log.debug("Preselecting: " + preselect);
                                var item = contents.find('.item[value="' + preselect + '"]').first();
                                item.prop('checked', true);
                                self.handleItemChange(item);
                            }
                            if (response.grouped) {
                                self.initialiseTreeView(contents);
                            }
                            if (response.audiencelist.length > 10) {
                                contents.find('.list').addClass('list-gt10');
                            }
                            contents.parent().removeClass('loading');
                            contents.addClass('loaded');
                        } else {
                            var message = Str.get_string('audienceselector:noaudienceitems', 'local_announcements', response.typenameplural);
                            $.when(message).done(function(localizedString) {
                                self.handleNoItems(contents, response, 'local_announcements/audienceselector: User is not a member of any ' + nameplural + '.', localizedString);
                            });
                        }
                    }).fail(function(reason) {
                        self.handleNoItems(contents, reason, 'local_announcements/audienceselector: Failed to render audience items.', 'Failed to get ' + namesingular + ' associations.');
                    });
            },
            fail: function(reason) {
                self.handleNoItems(contents, reason, 'local_announcements/audienceselector: Failed to get audience items', 'Failed to get ' + namesingular + ' associations.');
            }
        }]);
    };


    /**
     * Get the list of audience associations for the selected audience.
     *
     * @method
     */
    AudienceSelector.prototype.getUserList = function () {
        var self = this;
        var tagsjson = $('input[name="audiencesjson"]').val();
        if (self.modals.VIEWUSERS) {
            self.modals.VIEWUSERS.getModal().addClass('modal-xl');
            self.modals.VIEWUSERS.setBody('<div style="font-style:italic;">... Fetching user list ...<div class="loader" style="display:block;"><div class="circle spin"></div></div></div>');
            self.modals.VIEWUSERS.show();
            Ajax.call([{
                methodname: 'local_announcements_get_audienceselector_users',
                args: { audiencesjson: tagsjson },
                done: function(response) {
                    var count = Object.keys(response['userslist']).length;
                    //self.modals.VIEWUSERS.setTitle('Audience Users');
                    Templates.render(self.templates.VIEWUSERS, response)
                        .done(function(html) {
                            html = '<p><strong>The following ' + count + ' user(s) will receive this announcement</strong></p>' + html;
                            self.modals.VIEWUSERS.setBody(html);
                        })
                        .fail(function(reason) {
                            Log.debug(reason);
                            return "Failed to render announcement user list."
                        });
                },
                fail: function(reason) {
                    Log.error('local_announcements/audienceselector: unable to get announcemnt users.');
                    Log.debug(reason);
                }
            }]);
        }
    };


    /**
     * Initialise tree view.
     *
     * @method
     */
    AudienceSelector.prototype.initialiseTreeView = function (contents) {
        var groups = contents.find(".group-name");
        groups.on('click', function(e) {
            var groupname = $(this);
            groupname.parent().find(".group-items").toggleClass("active");
            groupname.toggleClass("caret-down");
        });
    };


    /**
     * Get the list of audience associations for the selected audience.
     *
     * @method
     */
    AudienceSelector.prototype.handleNoItems = function (contents, obj, debugMessage, userMessage) {
        var items = contents.find('.items').first();
        Log.debug(debugMessage);
        contents.parent().removeClass('loading');
        contents.addClass('no-items');
        items.html(userMessage); 
    };

    /**
     * Display the tags from the hidden input.
     *
     * @method
     */
    AudienceSelector.prototype.renderTags = function () {
        var self = this;
        var tagsjson = $('input[name="audiencesjson"]').val();
        var tags = new Array();
        if(tagsjson) {
            tags = JSON.parse(tagsjson);
            var i;
            for (i = 0; i < tags.length; ++i) {
                self.renderTag(tags[i]);
            }
        }
        // Check moderation status.
        self.handleTagChanges();
    };


    /**
     * Get the list of audience associations for the selected audience.
     *
     * @method
     */
    AudienceSelector.prototype.addTag = function (button) {
        var self = this;
        var contents = button.closest('.contents');
        
        // Extract the selected audiences.
        var selectedaudience = self.getSelected(contents);
        if (!selectedaudience) {
            return;
        }

        // Create the tag.
        var tags = new Array();
        var audiencesJSON = $('input[name="audiencesjson"]');
        if(audiencesJSON.val()) {
            tags = JSON.parse(audiencesJSON.val());
        }

        var tag = {
            type: "union",
            uid : Date.now(),
            audiences: [
                selectedaudience,
            ],
        };
        tags.push(tag);

        // Encode to json and add tag to hidden input.
        var tagStr = JSON.stringify(tags);
        audiencesJSON.val(tagStr);

        // Render the tag.
        self.renderTag(tag);

        // Clear and close the audience selector page.
        self.closeAudienceSelector(contents);

        // Check moderation status.
        self.handleTagChanges();

    };

    /**
     * Clear and close the audience selector page
     *
     * @method
     */
    AudienceSelector.prototype.closeAudienceSelector = function (contents) {
        contents.removeClass('selected');
        contents.find(".items input").prop("checked", false);
        contents.find(".roles input").prop("checked", false);
        contents.find(".buttons").removeClass('show');
        if (contents.hasClass('contents-filterable')) {
            contents.find(".audience-filter").val('');
            contents.find('.list li').removeClass('active');
        }
    };

    /**
     * Add the selected audience to the intersection workspace.
     *
     * @method
     */
    AudienceSelector.prototype.addIntersection = function (button) {
        var self = this;
        var contents = button.closest('.contents');
        
        // Extract the selected audiences.
        var selectedaudiences = self.getSelectedSplit(contents);
        if (!selectedaudiences.length) {
            return;
        }

        // Create the tag.
        var intersectiontags = new Array();
        if(self.intersectionjson) {
            intersectiontags = JSON.parse(self.intersectionjson);
        }
        $.each(selectedaudiences, function( i, audience ) {
            intersectiontags.push(audience);
        });

        // Encode to json and add tag to hidden input.
        self.intersectionjson = JSON.stringify(intersectiontags);

        // Call function to render tag.
        $.each(selectedaudiences, function( i, audience ) {
            self.renderIntersection(audience);
        });

        // Show the finish intersection button if there is at least 2 tags. This is done via css.
        if (intersectiontags.length > 1) {
            self.rootel.find('.intersection-workspace').addClass('has-multiple-tags');
        }

        // Now that we are in the process of building an intersection update the add intersection button name.
        self.rootel.find(".audience-intersect-btn").html(self.strings.continueintersection);
        // Also remove the Add users button.
        self.rootel.find(".audience-add-btn").hide();
        // Also hide any tabs that do not allow for intersections.
        self.rootel.find(".audience-tab").hide();
        self.rootel.find(".audience-tab.contents-intersectable").show();

        // Clear and close the audience selector page.
        self.closeAudienceSelector(contents);
    };

    /**
     * Add the selected audience to the intersection workspace.
     *
     * @method
     */
    AudienceSelector.prototype.finishIntersection = function (button) {
        var self = this;
        
        // Extract the intersected audiences.
        var intersectiontags = JSON.parse(self.intersectionjson);
        if (intersectiontags.length < 2) {
            // This should never happen.
            return;
        }

        // Get the current tags.
        var tags = new Array();
        var audiencesJSON = $('input[name="audiencesjson"]');
        if(audiencesJSON.val()) {
            tags = JSON.parse(audiencesJSON.val());
        }

        // Setup the new tag.
        var tag = {
            type: "intersection",
            uid : Date.now(),
            audiences: intersectiontags,
        };

        // Add the new tag to the list.
        tags.push(tag);

        // Encode the tags list to json and add tag to hidden input.
        var tagStr = JSON.stringify(tags);
        audiencesJSON.val(tagStr);

        // Render the new tag.
        self.renderTag(tag);

        // Clear the intersection workspace.
        self.clearIntersectionWorkspace();

        // Check moderation status.
        self.handleTagChanges();
    };

    /**
     * Add the selected audience to the intersection workspace.
     *
     * @method
     */
    AudienceSelector.prototype.clearIntersectionWorkspace = function () {
        var self = this;
        // Clear the intersection workspace.
        self.intersectionjson = '';
        // Show the add button again.
        self.rootel.find(".audience-add-btn").show();
        // Change the name of the add intersection button.
        self.rootel.find('.audience-intersect-btn').html(self.strings.createintersection);
        // Show all audience tabs again.
        self.rootel.find(".audience-tab").show();
        // Clear the intersection workspace.
        self.rootel.find('.intersection-workspace').removeClass('has-tags');
        self.rootel.find('.intersection-workspace').removeClass('has-multiple-tags');
        self.rootel.find('.intersection-tags-list .box').fadeOut(300, function() {
            $(this).remove();
        });
    };

    /**
     * Get the selected items.
     *
     * @method
     */
    AudienceSelector.prototype.getSelected = function (contents) {
        var audienceprovider = contents.data('audienceprovider');
        var audiencetype = contents.data('audiencetype');
        var audiencenamesingular = contents.data('audiencenamesingular');
        var audiencenameplural = contents.data('audiencenameplural');
        var audiencehasroles = contents.data('audiencehasroles');

        // get selected items
        var selecteditems = new Array();
        contents.find('.item:checked').each(function() {
            var input = $(this);
            var item = {
                code: input.val(),
                name: input.data('name'),
            }
            selecteditems.push(item);
        });

        if ( !selecteditems.length ) {
            return;
        }

        if (audiencehasroles) {
            // get selected roles
            var selectedroles = new Array();
            contents.find('.role:checked').each(function() {
                var input = $(this);
                var item = {
                    code: input.val(),
                    name: input.data('name'),
                }
                selectedroles.push(item);
            });

            if ( !selectedroles.length ) {
                return;
            }
        }

        return {
            audienceprovider: audienceprovider,
            audiencetype: audiencetype,
            audiencenamesingular: audiencenamesingular,
            audiencenameplural: audiencenameplural,
            selecteditems: selecteditems,
            selectedroles: selectedroles,
        };

    };


/**
     * Get the selected items. Where multiple are selected, create individual tags.
     *
     * @method
     */
    AudienceSelector.prototype.getSelectedSplit = function (contents) {
        var self = this;
        var singletag = self.getSelected(contents);

        // Create multiple tags from one. One for each selected item.
        var splitaudiences = new Array();
        $.each(singletag.selecteditems, function( i, item ) {
            var items = new Array();
            items.push(item);
            var tag = {
                audienceprovider: singletag.audienceprovider,
                audiencetype: singletag.audiencetype,
                audiencenamesingular: singletag.audiencenamesingular,
                audiencenameplural: singletag.audiencenameplural,
                selecteditems: items,
                selectedroles: singletag.selectedroles,
            };
            splitaudiences.push(tag);
        });

        return splitaudiences;
    };

    /**
     * Get the list of audience associations for the selected audience.
     *
     * @method
     */
    AudienceSelector.prototype.removeTag = function (button) {
        var self = this;
        var removeuid = button.data('taguid');
        var audiencesJSON = $('input[name="audiencesjson"]');
        var tags = JSON.parse(audiencesJSON.val());
        var tagsnew = new Array();
        var i;
        for (i = 0; i < tags.length; i++) {
            var curruid = tags[i]['uid'];
            if (curruid != removeuid) {
                tagsnew.push(tags[i]);
            }
        }
        var tagStr = '';
        if (tagsnew.length) {
            tagStr = JSON.stringify(tagsnew);
        }
        audiencesJSON.val(tagStr);
        button.parent().parent().fadeOut(300, function(){
            $(this).remove();
        });

        // Check moderation status.
        self.handleTagChanges();
    };

    /**
     * Render a audience tag string and append to the selected audiences list
     *
     * @method
     */
    AudienceSelector.prototype.renderTag = function (tag) {
        var self = this;
        var loader = self.rootel.find('.tags .loader');
        loader.addClass('show');
        // Render the tag from a template
        Templates.render('local_announcements/audience_tag', tag)
            .then(function(html) {
                self.rootel.find('.tags-list').append(html);
                loader.removeClass('show');
            }).fail(function(reason) {
                Log.error(reason);
            });
    };

    /**
     * Render a audience tag string and append to the selected audiences list
     *
     * @method
     */
    AudienceSelector.prototype.renderIntersection = function (tag) {
        var self = this;
        var loader = self.rootel.find('.intersection-workspace .loader');
        loader.addClass('show');
        // Render the tag from a template
        Templates.render('local_announcements/intersection_tag', tag)
            .then(function(html) {
                self.rootel.find('.intersection-tags-list').append(html);
                self.rootel.find('.intersection-workspace').addClass('has-tags');
                loader.removeClass('show');
            }).fail(function(reason) {
                Log.error(reason);
            });
    };

    /**
     * Check to see if a filter is present or not, and show list depending.
     *
     * @method
     */
    AudienceSelector.prototype.handleFitlerChange = function (filter) {
        var contents = filter.parent();
        var list = contents.find('.list').first();
        list.find('.more').remove();
        // clear active/found items.
        list.find('li').removeClass('active');
        // get search string.
        var search = filter.val().toLowerCase();
        if (search.length == 0) {
            return false;
        }
        // search items.
        var maxresults = 50;
        var foundresults = 0;
        list.find('li').each(function() {
            name = $(this).find('input').first().data('name').toLowerCase();
            if ( name.indexOf(search) >= 0 ) {
                foundresults++;
                if ( foundresults > maxresults ) {
                    list.append(
                        $('<li/>')
                            .addClass("more-items active")
                            .text("More records are available. be more specific to narrow this down.")
                    );
                    return false;
                }
                $(this).addClass('active');
            }
        });
    };

    /**
     * Check to see if an item is selected or not, and show add audience button depending.
     *
     * @method
     */
    AudienceSelector.prototype.handleItemChange = function (item) {
        var contents = item.closest('.contents');
        var type = contents.data('audiencetype');
        var contentsid = '#contents-' + type;
        var btnclass =  contentsid + ' .buttons';
        var hasroles = contents.data('audiencehasroles');
        var li = item.closest('.' + type +'-item');

        if (item.prop('checked')) {
            li.addClass('selected');
        } else {
            li.removeClass('selected');
        }

        $(btnclass).removeClass('show');
        if ($(contentsid + ' .items input:checkbox:checked').length > 0) {
            if (hasroles) {
                if ($(contentsid + ' .roles input:checkbox:checked').length > 0) {
                    $(btnclass).addClass('show');
                }
            } else {
                $(btnclass).addClass('show');
            }
        }
    };



    /**
     * Check if coming from a course then load audience and preselect course.
     *
     * @method
     */
    AudienceSelector.prototype.checkForOriginCourse = function () {
        var self = this;
        var type = self.getUrlParameter('type');
        var code = self.getUrlParameter('code');
        if (type === undefined || code === undefined) {
            return;
        }
        var tab = $('[data-audiencetype=' + type +']');
        self.openTab(tab, code);
    };

    /**
     * Helper method to get param from query string.
     *
     * @method
     */
    AudienceSelector.prototype.getUrlParameter = function (sParam) {
        var sPageURL = window.location.search.substring(1),
            sURLVariables = sPageURL.split('&'),
            sParameterName,
            i;

        for (i = 0; i < sURLVariables.length; i++) {
            sParameterName = sURLVariables[i].split('=');

            if (sParameterName[0] === sParam) {
                return sParameterName[1] === undefined ? true : decodeURIComponent(sParameterName[1]);
            }
        }
    };



    AudienceSelector.prototype.handleTagChanges = function () {
        var self = this;
        // Check moderation status.
        self.checkModerationStatus();
        self.checkCCGroups();

        var tags = new Array();
        var audiencesJSON = $('input[name="audiencesjson"]');
        if(audiencesJSON.val()) {
            tags = JSON.parse(audiencesJSON.val());
        }
        // If has tags add class to audience selector
        if (tags.length) {
            self.rootel.addClass('has-tags');
        } else {
            self.rootel.removeClass('has-tags');
        }
    }


    /**
     * Refresh the moderation status for the selected audiences.
     *
     * @method
     */
    AudienceSelector.prototype.checkModerationStatus = function () {
        var moderationroot = $('.moderation-status');
        var moderationstatus = $('.moderation-status .status');
        var moderationdesc = $('.moderation-status .description');
        moderationdesc.removeClass('active');
        moderationroot.removeClass('visible hasmod');
        
        var tagsjson = $('input[name="audiencesjson"]').val();
        if (tagsjson.length == 0) {
            return;
        }

        // Moderator is initially NA.
        var moderatorjson = document.querySelector('input[name="moderatorjson"]');

        moderationroot.addClass('visible loading');
        Ajax.call([{
            methodname: 'local_announcements_get_moderation_for_audiences',
            args: { audiencesjson: tagsjson },
            done: function(mod) {
              if (mod.required) {
                moderationstatus.html(mod.status + ' <a class="rule-toggle" href="#">More</a>');
                moderationdesc.html(mod.description);
                moderationroot.removeClass('loading').addClass('hasmod');

                // Do we need to select a moderator?
                if (mod.modusername.indexOf('[') > -1) {
                  // Moderation from a list is needed. Recreate the options.
                  if (moderatorjson.value == 'na') {
                    moderatorjson.value !== ''
                  }
                  // Blank option.
                  var select = document.getElementById('id_moderator')
                  select.innerHTML = "";
                  var opt = document.createElement('option')
                  opt.value = ''
                  opt.innerHTML = '-- select --'
                  select.appendChild(opt)
                  // List of users.
                  var moderators = JSON.parse(mod.modusername)
                  if (moderators.length) {
                    for (var i = 0; i < moderators.length; ++i) {
                      var opt = document.createElement('option')
                      opt.value = moderators[i]['username']
                      opt.innerHTML = moderators[i]['fullname']
                      select.appendChild(opt)
                    }
                    // Select the default/existing moderator if in list.
                    if (moderatorjson.value !== '') {
                      var modoption = document.querySelector('#id_moderator option[value="' + moderatorjson.value + '"]')
                      if (modoption) {
                        document.getElementById('id_moderator').value = moderatorjson.value
                      } else {
                        moderatorjson.value = ''
                      }
                    }
                    var fieldset = document.getElementById('id_moderation')
                    fieldset.classList.add("show");
                  }
                }
              } else {
                  moderatorjson.value = 'na'
                  moderationroot.removeClass('loading').removeClass('visible')
                  var fieldset = document.getElementById('id_moderation')
                  fieldset.classList.remove("show")
              }
            },
            fail: function(reason) {
                moderationroot.removeClass('visible loading');
                Log.debug(reason);
            }
        }]);
    };

    /**
     * Check cc groups for the selected audiences.
     *
     * @method
     */
    AudienceSelector.prototype.checkCCGroups = function () {

        var ccgroupsroot = $('.ccgroups-status');
        var ccgroupsstatus = $('.ccgroups-status .status');
        ccgroupsroot.removeClass('visible hasccgroups');
        
        var tagsjson = $('input[name="audiencesjson"]').val();
        if (tagsjson.length == 0) {
            return;
        }

        // Hide this for from user for now. Leave in html for debugging.
        //ccgroupsroot.addClass('visible loading');
        Ajax.call([{
            methodname: 'local_announcements_get_ccgroups_for_audiences',
            args: { audiencesjson: tagsjson },
            done: function(ccgroups) {
                ccgroupsroot.removeClass('loading')
                if (ccgroups) {
                    var description = ccgroups.join('. ');
                    ccgroupsstatus.html(description + '.');
                    ccgroupsroot.addClass('hasccgroups');
                } else {
                    ccgroupsroot.removeClass('visible')
                }
            },
            fail: function(reason) {
                ccgroupsroot.removeClass('visible loading');
                Log.debug(reason);
            }
        }]);
    };

    /**
     * Toggle moderation rule
     *
     * @method
     */
    AudienceSelector.prototype.showModerationRule = function (button) {
        var self = this;
        var moderationinfo = button.closest('.moderation-status');
        var description = moderationinfo.find('.description').first();
        description.addClass('active');
        button.remove();
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
    AudienceSelector.prototype.loadModal = function (modalkey, title, buttontext, type) {
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
    AudienceSelector.prototype.loadTemplate = function (templatekey) {
        var self = this;
        return Templates.render(self.templates[templatekey], {});
    }


    return {
        init: init
    };
});
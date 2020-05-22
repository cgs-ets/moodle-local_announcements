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
 * Module for impersonate autocomplete field.
 *
 * @package   local_announcements
 * @category  output
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @module local_announcements/impersonateselector
 */
define(['jquery', 'core/log', 'core/ajax', 'core/templates', 'core/str'], function($, Log, Ajax, Templates, Str) {
    'use strict';

    /**
     * Initializes the impersonateselector component.
     */
    function init() {
        Log.debug('local_announcements/impersonateselector: initializing the impersonate-selector component');

        var rootel = $('.impersonate-selector').first();

        if (!rootel.length) {
            Log.error('local_announcements/impersonateselector: impersonate-selector root element not found!');
            return;
        }

        var impersonateselector = new ImpersonateSelector(rootel);
        impersonateselector.main();
    }

    /**
     * The impersonate selector constructor
     *
     * @constructor
     * @param {jQuery} rootel
     */
    function ImpersonateSelector(rootel) {
        var self = this;
        self.rootel = rootel;
        self.component = 'local_announcements';
        // By default, the element has fields and is treated like a drop down.
        self.wildcard = false;
        // Wildcard allows user searching.
        if (self.rootel.data('wildcard')) {
            self.wildcard = true;
        }
        if (self.rootel.data('hasresults')) {
            self.hasresults = true;
        }

        self.strings = {}
        Str.get_strings([
            {key: 'postform:impersonatenoselection', component: self.component},
        ]).then(function(s) {
            self.strings.noselectionstr = s[0];
        });
    }

    /**
     * Run the Impersonate Selector.
     *
     */
   ImpersonateSelector.prototype.main = function () {
        var self = this;

        // Render existing selection (if editing announcement).
        self.render();

        // Handle search.
        var keytimer;
        self.rootel.on('keyup', '.impersonate-autocomplete', function(e) {
            clearTimeout(keytimer);
            var autocomplete = $(this);

            // If not a search, do not allow typing.
            if (!self.wildcard) {
                autocomplete.val('');
                return;
            }

            keytimer = setTimeout(function () {
                self.search(autocomplete);
            }, 500);
        });

        // Handle search result click.
        self.rootel.on('click', '.impersonate-result', function(e) {
            e.preventDefault();
            var tag = $(this);
            self.add(tag);
        });

        // Handle tag click.
        self.rootel.on('click', '.impersonate-tag', function(e) {
            e.preventDefault();
            var tag = $(this);
            self.remove(tag);
        });

        // Handle entering the autocomplete field.
        self.rootel.on('focus', '.impersonate-autocomplete', function(e) {
            self.refocus();
        });

        // Handle leaving the autocomplete field.
        $(document).on('click', function (e) {
            var target = $(e.target);
            if (target.is('.impersonate-autocomplete') || target.is('.impersonate-result')) {
                return;
            }
            self.unfocus();
        });
    };


    /**
     * Add a selection.
     *
     * @method
     */
    ImpersonateSelector.prototype.add = function (tag) {
        var self = this;
        self.unfocus();

        var input = $('input[name="impersonate"]');

        // Encode to json and add tag to hidden input.
        var obj = {
            username: tag.data('username'),
            photourl: tag.find('img').attr('src'),
            fullname: tag.find('span').text()
        };
        input.val(JSON.stringify(obj));

        self.render();
    };

    /**
     * Remove a selection.
     *
     * @method
     */
    ImpersonateSelector.prototype.remove = function (tag) {
        var self = this;

        var input = $('input[name="impersonate"]');
        input.val('');

        self.render();
    };

    /**
     * Render the selection.
     *
     * @method
     */
    ImpersonateSelector.prototype.render = function () {
        var self = this;
        var input = $('input[name="impersonate"]');

        if (input.val() == '') {
            // Remove tag.
            self.rootel.find('.impersonate-selection').html(self.strings.noselectionstr);
            return;
        }

        var json = input.val();
        if(json) {
            var tag = JSON.parse(json);

            console.log(tag);
            // Render the tag from a template.
            Templates.render('local_announcements/impersonate_selector_tag', tag)
                .then(function(html) {
                    self.rootel.find('.impersonate-selection').html(html);
                }).fail(function(reason) {
                    Log.error(reason);
                });
        }
    };

    /**
     * Search.
     *
     * @method
     */
    ImpersonateSelector.prototype.search = function (searchel) {
        var self = this;
        self.hasresults = false;

        if (searchel.val() == '') {
            return;
        }

        Ajax.call([{
            methodname: 'local_announcements_get_impersonate_users',
            args: { query: searchel.val() },
            done: function(response) {
                if (response.length) {
                    self.hasresults = true;
                    // Render the results.
                    Templates.render('local_announcements/impersonate_selector_results', { users : response }) 
                        .then(function(html) {
                            var results = self.rootel.find('.impersonate-results');
                            results.html(html);
                            results.addClass('active');
                        }).fail(function(reason) {
                            Log.error(reason);
                        });
                } else {
                    self.rootel.find('.impersonate-results').removeClass('active');
                }
            },
            fail: function(reason) {
                Log.error('local_announcements/impersonateselector: failed to search.');
                Log.debug(reason);
            }
        }]);
    };

    /**
     * Leave the autocomplete field.
     *
     * @method
     */
    ImpersonateSelector.prototype.unfocus = function () {
        var self = this;
        self.rootel.find('.impersonate-results').removeClass('active');
    };

    /**
     * Leave the autocomplete field.
     *
     * @method
     */
    ImpersonateSelector.prototype.refocus = function () {
        var self = this;
        if (self.hasresults) {
            self.rootel.find('.impersonate-results').addClass('active');
        }
    };

    return {
        init: init
    };
});
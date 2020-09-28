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
 * Provides {@link local_announcements\external\announcement_exporter} class.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/announcements/locallib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
use core\external\persistent_exporter;
use renderer_base;
use \local_announcements\persistents\announcement;
use \local_announcements\providers\moderation;


/**
 * Exporter of a single announcement
 */
class announcement_exporter extends persistent_exporter {

    /**
    * Returns the specific class the persistent should be an instance of.
    *
    * @return string
    */
    protected static function define_class() {
        return announcement::class; 
    }

    /**
     * Returns a list of objects that are related.
     *
     * We need the context to be used when formatting the message field.
     *
     * @return array
     */
    protected static function define_related() {
        return [
            'context' => 'context',
            'audiences' => 'stdClass[]',
        ];
    }

    /**
	 * Return the list of additional properties.
	 * @return array
	 */
	protected static function define_other_properties() {
	    return [
	        'audiences' => [
	            'type' => audience_exporter::read_properties_definition(),
	            'multiple' => true,
	            'optional' => true
	        ],
	        'authorphoto' => [
	        	'type' => PARAM_RAW,
	        ],
	        'authorphototokenised' => [
	        	'type' => PARAM_RAW,
	        ],
	        'authorfullname' => [
	        	'type' => PARAM_RAW,
	        ],
	        'authorjobpositions' => [
	        	'type' => PARAM_RAW,
	        ],
	        'authorurl' => [
	        	'type' => PARAM_RAW,
	        ],
	        'readabletime' => [
	        	'type' => PARAM_RAW,
	        ],
	        'formattedattachments' => [
	        	'type' => PARAM_RAW,
	        ],
	        'iscreator' => [
	        	'type' => PARAM_BOOL,
	        ],
	        'editurl' => [
	        	'type' => PARAM_RAW,
	        ],
            "isavailable" => [
                'type' => PARAM_BOOL,
                'default' => 0,
            ],
	        'messageplain' => [
	        	'type' => PARAM_RAW,
	        ],
	        'messagetokenized' => [
	        	'type' => PARAM_RAW,
	        ],
	        'messagemobile' => [
	        	'type' => PARAM_RAW,
	        ],
	        'shortmessage' => [
	        	'type' => PARAM_RAW,
	        ],
	        'islong' => [
	        	'type' => PARAM_BOOL,
	        ],
	        'attachmentstokenized' => [
	        	'type' => PARAM_RAW,
	        ],
	        'viewurl' => [
	        	'type' => PARAM_RAW,
	        ],
            "ismodapproved" => [
                'type' => PARAM_BOOL,
                'default' => 0,
            ],
            "ismodpending" => [
                'type' => PARAM_BOOL,
                'default' => 0,
            ],
            "ismodrejected" => [
                'type' => PARAM_BOOL,
                'default' => 0,
            ],
	        'modinfo' => [
	        	'type' => PARAM_RAW,
	        ],
	        'impersonatedbyusername' => [
	        	'type' => PARAM_RAW,
	        ],
	        'impersonatedbyphoto' => [
	        	'type' => PARAM_RAW,
	        ],
	        'impersonatedbyfullname' => [
	        	'type' => PARAM_RAW,
	        ],
	        'impersonatedbyurl' => [
	        	'type' => PARAM_RAW,
	        ],
	        'deliverystatus' => [
	        	'type' => PARAM_RAW,
	        ],
	        'deliveryicon' => [
	        	'type' => PARAM_RAW,
	        ],
	        'deliverymessage' => [
	        	'type' => PARAM_RAW,
	        ],
	    ];
	}

	/**
	 * Get the additional values to inject while exporting.
	 *
	 * @param renderer_base $output The renderer.
	 * @return array Keys are the property names, values are their values.
	 */
	protected function get_other_values(renderer_base $output) {
		global $USER, $DB, $OUTPUT, $PAGE;

		// Author and impersonated user are both considered creator.
		$iscreator = 0;
		if ($this->data->authorusername == $USER->username || $this->data->impersonate == $USER->username) {
			$iscreator = 1;
		}

        // Give admins the same power as the creator.
        if (is_user_admin()) {
        	$iscreator = 1;
        }

		// Export the audiences for this announcement.
	    $audiences = array();
	    foreach ($this->related['audiences'] as $audience) {
	    	$audienceexporter = new audience_exporter($audience, ['iscreator' => $iscreator]);
            $audiences[] = $audienceexporter->export($output);
	    }
	    // Restructure audiences into multidimensional array by condition type (union/intersection).
	    $audiencesgrouped = array();
    	foreach ($audiences as $audience) {
    		$postsaudiencesid = $audience->postsaudiencesid;
    		if (!isset($audiencesgrouped[$postsaudiencesid])) {
    			$audiencesgrouped[$postsaudiencesid] = (object) array(
    				'conditions' => array(),
    				'conditiontype' => $audience->conditiontype,
    				'isintersection' => $audience->conditiontype == 'intersection',
    				'isunion' => $audience->conditiontype == 'union',
    				'postsaudiencesid' => $audience->postsaudiencesid,
    			);
    		}
    		$audiencesgrouped[$postsaudiencesid]->conditions[] = (object) array(
    			'name' => $audience->name, 
    			'url' => $audience->url
    		);
	    }
	    $audiencesgrouped = array_values($audiencesgrouped);
        // Set last indexes - used in digests (emailannouncementhtml.mustache).
        $i = count($audiencesgrouped)-1;
        if ($i >= 0) {
        	$audiencesgrouped[$i]->last = true;
	        foreach ($audiencesgrouped as $audience) {
	            $i = count($audience->conditions)-1;
	            $audience->conditions[$i]->last = true;
	        }
        }

    	// Get the author profile details.
    	// If the announcement was sent as someone else replace the author.
    	$impersonatedbyusername = $impersonatedbyphoto = $impersonatedbyfullname = $impersonatedbyurl = '';
    	if (!empty($this->data->impersonate)) {
    		// Make the impersonated user the author.
	        $impersonatedbyusername = $this->data->authorusername;
    		$this->data->authorusername = $this->data->impersonate;
    		$impersonatedby = $DB->get_record('user', array('username'=>$impersonatedbyusername));
    		$impersonatedbyphoto = new \moodle_url('/user/pix.php/'.$impersonatedby->id.'/f2.jpg');
	        $impersonatedbyfullname = fullname($impersonatedby);
	        $impersonatedbyurl = new \moodle_url('/user/profile.php', array('id' => $impersonatedby->id));
    	}
        $author = $DB->get_record('user', array('username'=>$this->data->authorusername));
        profile_load_data($author);
        $authorphoto = new \moodle_url('/user/pix.php/'.$author->id.'/f2.jpg');
        $authorfullname = fullname($author);
        $authorjobpositions = '';
		if (isset($author->profile_field_JobPositions)) {
			$authorjobpositions = $author->profile_field_JobPositions;
		}
        $authorurl = new \moodle_url('/user/profile.php', array('id' => $author->id));
        $authorphototokenised = $OUTPUT->user_picture($author, array('size' => 35, 'includetoken' => true));

        $displaytime = max(array($this->data->timecreated, $this->data->timeedited));
        $displaytime = $this->data->sorttime > $displaytime ? $this->data->sorttime : $displaytime;
        $readabletime = date('j M Y, g:ia', $displaytime);

	    $formattedattachments = $this->export_attachments($output);

	    $editurl = '';
	    if($iscreator) {
	    	$editurl = new \moodle_url('/local/announcements/post.php', array('edit' => $this->data->id));
	    	$editurl = $editurl->out();
	    }

	    $isavailable = false;
	    $now = time();
	    if (($this->data->timestart <= $now and $this->data->timeend >  $now) or
	     	 	($this->data->timestart <= $now and $this->data->timeend == 0) or
	     		($this->data->timestart == 0    and $this->data->timeend >  $now) or
	 			($this->data->timestart == 0    and $this->data->timeend == 0)) {
	    	$isavailable = true;
	    }

	    $messagetokenized = $messagemobile = file_rewrite_pluginfile_urls($this->data->message,'pluginfile.php',$this->related['context']->id,
	        		'local_announcements','announcement',$this->data->id,['includetoken' => true]);
	    $messageplain = trim(html_to_text(format_text_email($messagetokenized, FORMAT_PLAIN)));
	    
	    // Mobile shows the full tokenised message with minimal formating. 
	    // Replace <p> with <br> as it is common for editor html to have p's inside p's and this breaks the template.
	    $messagemobile = preg_replace("/<p[^>]*?>/", "", $messagemobile);
		$messagemobile = str_replace("</p>", "<br />", $messagemobile);
		// Remove inline styles
		$messagemobile = preg_replace('#(<[a-z ]*)(style=("|\')(.*?)("|\'))([a-z ]*>)#', '\\1\\6', $messagemobile);
	    $allowedtags = array("<b>", "<i>", "<a>", "<img>", "<br>", "<div>", "<blockquote>", 
	    	"<h1>", "<h2>", "<h3>", "<h4>", "<h5>", "<h6>", "<span>", "<hr>", "<small>", "<strong>", "<em>",
	    	"<sub>", "<sup>", "<label>", "<ul>", "<ol>", "<li>", "<table>", "<tr>", "<th>", "<td>");
	    $messagemobile = strip_tags($messagemobile, $allowedtags); // The second param is a whitelist of allowed tags. 
	    
	    $attachmentstokenized = $this->export_attachmentstokenized($output);

    	$viewurl = new \moodle_url('/local/announcements/view.php', array('id' => $this->data->id));
    	$viewurl = $viewurl->out();

    	$shortmessage = shorten_text($this->data->message, get_shortpost(), false, '...');
    	$islong = ($shortmessage != $this->data->message);

    	// Replace images, videos and iframes in short message with a word.
		$dom = new \DOMDocument;
		@$dom->loadHTML(mb_convert_encoding($shortmessage, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		$shortmessagebeforetrims = trim($dom->saveHTML());
		foreach( $dom->getElementsByTagName("img") as $img ) {
		    $text = $dom->createElement("p", "(image)");
		    $img->parentNode->replaceChild($text, $img);
		}
		foreach( $dom->getElementsByTagName("video") as $video ) {
		    $text = $dom->createElement("p", "(video)");
		    $video->parentNode->replaceChild($text, $video);
		}
		foreach( $dom->getElementsByTagName("iframe") as $iframe ) {
		    $text = $dom->createElement('p', "(iframe)");
		    $iframe->parentNode->replaceChild($text, $iframe);
		}
		$shortmessage = trim($dom->saveHTML());
    	$islong = ($islong || $shortmessage != $shortmessagebeforetrims);

		// Rewrite pluginfile urls.
	    $shortmessage = file_rewrite_pluginfile_urls($shortmessage,'pluginfile.php',$this->related['context']->id, 'local_announcements','announcement',$this->data->id);

	   	$ismodapproved = false;
	    if ($this->data->modrequired == 0 or $this->data->modstatus == 1) {
	    	$ismodapproved = true;
	    }
	    $ismodpending = false;
	    if ($this->data->modrequired == 1 and $this->data->modstatus == 0) {
	    	$ismodpending = true;

	    }
	    $ismodrejected = false;
	    if ($this->data->modrequired == 1 and $this->data->modstatus == 2) {
	    	$ismodrejected = true;
	    }
	    // Get any moderation details.
	    $modinfo = moderation::get_mod_info($this->data->id);

	    // Append view more link to short message.
		$viewlink = '<p><a class="view-full-link btn btn-secondary" href="' . $viewurl . '">' . get_string('list:viewmore', 'local_announcements') . '</a></p>';
		if ($islong) {
			$shortmessage .= $viewlink;
		}

		// Deliver status
		$deliverystatus = '';
		$deliveryicon = '';
		$deliverymessage = '';
		if ( $this->data->forcesend && (!$this->data->notified) ) {
			$deliverystatus = 'forcepending';
			$deliveryicon = '<i class="fa fa-circle-o" aria-hidden="true"></i>';
			$deliverymessage = get_string('list:deliveryforcepending', 'local_announcements');
		} elseif ( $this->data->forcesend && $this->data->notified ) {
			// Check if cron is still processing.
			if (announcement::is_sending($this->data->id)) {
				$deliverystatus = 'forcesending';
				$deliveryicon = '<i class="fa fa-circle-o" aria-hidden="true"></i>';
				$deliverymessage = get_string('list:deliveryforcesending', 'local_announcements');
			} else {
				$deliverystatus = 'forcemailed';
				$deliveryicon = '<i class="fa fa-check-circle-o" aria-hidden="true"></i>';
				$deliverymessage = get_string('list:deliveryforcemailed', 'local_announcements');
			}
		} elseif ( (!$this->data->forcesend) && (!$this->data->mailed) ) {
			$deliverystatus = 'digestpending';
			$deliveryicon = '<i class="fa fa-clock-o" aria-hidden="true"></i>';
			$deliverymessage = get_string('list:deliverydigestpending', 'local_announcements');
		} elseif ( (!$this->data->forcesend) && $this->data->mailed ) {
			// Check if cron is still processing.
			if (announcement::is_mailing($this->data->id)) {
				$deliverystatus = 'digestsending';
				$deliveryicon = '<i class="fa fa-clock-o" aria-hidden="true"></i>';
				$deliverymessage = get_string('list:deliverydigestsending', 'local_announcements');
			} else {
				$deliverystatus = 'digestmailed';
				$deliveryicon = '<i class="fa fa-check-circle" aria-hidden="true"></i>';
				$deliverymessage = get_string('list:deliverydigestmailed', 'local_announcements');
			}
			
		}

	    return [
	        'audiences' => $audiencesgrouped,
	        'authorphoto' => $authorphoto,
	        'authorphototokenised' => $authorphototokenised->out(false),
	        'authorfullname' => $authorfullname,
	        'authorjobpositions' => $authorjobpositions,
	        'authorurl' => $authorurl->out(false),
	        'readabletime' => $readabletime,
	        'formattedattachments' => $formattedattachments,
	        'iscreator' => $iscreator,
	        'editurl' => $editurl,
	        'isavailable' => $isavailable,
	        'messagetokenized' => $messagetokenized,
	        'messageplain' => $messageplain,
	        'messagemobile' => $messagemobile,
	        'shortmessage' => $shortmessage,
	        'islong' => $islong,
	        'attachmentstokenized' => $attachmentstokenized,
	        'viewurl' => $viewurl,
	        'ismodapproved' => $ismodapproved,
	        'ismodpending' => $ismodpending,
	        'ismodrejected' => $ismodrejected,
	        'modinfo' => $modinfo,
	        'impersonatedbyusername' => $impersonatedbyusername,
	        'impersonatedbyphoto' => $impersonatedbyphoto,
	        'impersonatedbyfullname' => $impersonatedbyfullname,
	        'impersonatedbyurl' => $impersonatedbyurl,
	        'deliverystatus' => $deliverystatus,
	        'deliveryicon' => $deliveryicon,
	        'deliverymessage' => $deliverymessage,
	    ];
	}

	private function export_attachments(renderer_base $output) {
		global $CFG;

		$attachments = [];
		// We retrieve all files according to the time that they were created.  In the case that several files were uploaded
	    // at the sametime (e.g. in the case of drag/drop upload) we revert to using the filename.
	    $fs = get_file_storage();
	    $files = $fs->get_area_files($this->related['context']->id, 'local_announcements', 'attachment', $this->data->id, "filename", false);
	    if ($files) {
	        foreach ($files as $file) {
	            $filename = $file->get_filename();
	            $mimetype = $file->get_mimetype();
	            $iconimage = $output->pix_icon(file_file_icon($file), get_mimetype_description($file), 'moodle', array('class' => 'icon'));
	            $path = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$this->related['context']->id.'/local_announcements/attachment/'.$this->data->id.'/'.$filename);

	            $isimage = in_array($mimetype, array('image/gif', 'image/jpeg', 'image/png')) ? 1 : 0;

	            $attachment = [
	            	'filename' => $filename,
	            	'formattedfilename' => format_text($filename, FORMAT_HTML, array('context'=>$this->related['context'])),
	            	'mimetype' => $mimetype,
	            	'iconimage' => $iconimage,
	            	'path' => $path,
	            	'isimage' => $isimage,
	            ];
	            $attachments[] = $attachment;
	        }
	    }

	    return $attachments;
	}

	private function export_attachmentstokenized(renderer_base $output) {
		global $CFG;

		$attachments = [];
		// We retrieve all files according to the time that they were created.  In the case that several files were uploaded
	    // at the sametime (e.g. in the case of drag/drop upload) we revert to using the filename.
	    $fs = get_file_storage();
	    $files = $fs->get_area_files($this->related['context']->id, 'local_announcements', 'attachment', $this->data->id, "filename", false);
	    if ($files) {
	        foreach ($files as $file) {
				$filename = $file->get_filename();
				$filepath = '@@PLUGINFILE@@/'.rawurlencode($filename);
	            $mimetype = $file->get_mimetype();
	            $iconimage = $output->pix_icon(file_file_icon($file), get_mimetype_description($file), 'moodle', array('class' => 'icon'));
	            $path = file_rewrite_pluginfile_urls($filepath,'pluginfile.php',$this->related['context']->id,
	        		'local_announcements','attachment',$this->data->id,['includetoken' => true]);
	            $isimage = in_array($mimetype, array('image/gif', 'image/jpeg', 'image/png')) ? 1 : 0;

	            $attachment = [
	            	'filename' => $filename,
	            	'formattedfilename' => format_text($filename, FORMAT_HTML, array('context'=>$this->related['context'])),
	            	'mimetype' => $mimetype,
	            	'iconimage' => $iconimage,
	            	'path' => $path,
	            	'isimage' => $isimage,
	            ];
	            $attachments[] = $attachment;
	        }
	    }
	    if(!empty($attachments)) {
	    	$lastix = count($attachments)-1;
	    	$attachments[$lastix]['last'] = true;
	    }

	    return $attachments;
	}


    /**
     * Get the formatting parameters for the message.
     *
     * @return array
     */
    protected function get_format_parameters_for_message() {
        return [
            'component' => 'local_announcements',
            'filearea' => 'announcement',
            'itemid' => $this->data->id,
            'options' => \local_announcements\forms\form_post::editor_options($this->data->id),
        ];
    }

}

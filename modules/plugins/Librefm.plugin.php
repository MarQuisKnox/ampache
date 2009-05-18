<?php
/*

 Copyright (c) Ampache.org
 All rights reserved.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License v2
 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.

*/

class Ampachelibrefm { 

	public $name		='Libre.FM'; 
	public $description	='Records your played songs to your Libre.FM Account'; 
	public $url		='';
	public $version		='000001';
	public $min_ampache	='350001';
	public $max_ampache	='360008';

	// These are internal settings used by this class, run this->load to 
	// fill em out
	private $username; 
	private $password; 
	private $hostname;
	private $port; 
	private $path; 
	private $challenge;

	/**
	 * Constructor
	 * This function does nothing...
	 */
	public function __construct() { 

		return true; 

	} // Pluginlibrefm

	/**
	 * install
	 * This is a required plugin function it inserts the required preferences
	 * into Ampache
	 */
	public function install() { 

		// Check and see if it's already installed (they've just hit refresh, those dorks)
		if (Preference::exists('librefm_user')) { return false; } 

		Preference::insert('librefm_user','Libre.FM Username','','25','string','plugins'); 
		Preference::insert('librefm_pass','Libre.FM Password','','25','string','plugins'); 
		Preference::insert('librefm_port','Libre.FM Submit Port','','25','string','internal'); 
		Preference::insert('librefm_host','Libre.FM Submit Host','','25','string','internal'); 
		Preference::insert('librefm_url','Libre.FM Submit URL','','25','string','internal'); 
		Preference::insert('librefm_challenge','Libre.FM Submit Challenge','','25','string','internal'); 

		return true; 

	} // install

	/**
	 * uninstall
	 * This is a required plugin function it removes the required preferences from
	 * the database returning it to its origional form
	 */
	public function uninstall() { 

		Preference::delete('librefm_pass'); 
		Preference::delete('librefm_user'); 
		Preference::delete('librefm_url'); 
		Preference::delete('librefm_host'); 
		Preference::delete('librefm_port'); 
		Preference::delete('librefm_challenge'); 

	} // uninstall

	/**
	 * submit
	 * This takes care of queueing and then submiting the tracks eventually this will make sure
	 * that you've haven't
	 */
	public function submit($song,$user_id) { 

		// Before we start let's pull the last song submited by this user
		$previous = Stats::get_last_song($user_id); 

		$diff = time() - $previous['date']; 
		
		// Make sure it wasn't within the last min
		if ($diff < 60) { 
			debug_event('librefm','Last song played within ' . $diff . ' seconds, not recording stats','3'); 
			return false; 
		} 

		if ($song->time < 30) { 
			debug_event('librefm','Song less then 30 seconds not queueing','3'); 
			return false; 
		} 

		// Make sure there's actually a username and password before we keep going
		if (!$this->username || !$this->password) { return false; } 
		
		// Create our scrobbler with everything this time and then queue it
		$scrobbler = new scrobbler($this->username,$this->password,$this->hostname,$this->port,$this->path,$this->challenge,'turtle.libre.fm'); 

		// Check to see if the scrobbling works
		if (!$scrobbler->queue_track($song->f_artist_full,$song->f_album_full,$song->title,time(),$song->time,$song->track)) { 
			// Depending on the error we might need to do soemthing here
			return false; 
		} 
		
		// Go ahead and submit it now	
		if (!$scrobbler->submit_tracks()) { 
			debug_event('librefm','Error Submit Failed: ' . $scrobbler->error_msg,'3'); 
			if ($scrobbler->reset_handshake) { 
				debug_event('librefm','Re-running Handshake due to error','3');
				$this->set_handshake($user_id); 
				// Try try again
				if ($scrobbler->submit_tracks()) { 
					return true; 
				} 
			} 
			return false; 
		}

		debug_event('librefm','Submission Successful','5'); 
		
		return true; 

	} // submit

	/** 
	 * set_handshake
	 * This runs a handshake and properly updates the preferences as needed, it returns the data
	 * as an array so we don't have to requery the db. This requires a userid so it knows who's 
	 * crap to update
	 */
	public function set_handshake($user_id) { 
	
		$scrobbler = new scrobbler($this->username,$this->password); 
		$data = $scrobbler->handshake(); 

		if (!$data) { 
			debug_event('librefm','Handshake Failed: ' . $scrobbler->error_msg,'3'); 
			return false; 
		} 

		$this->hostname = $data['submit_host']; 
		$this->port = $data['submit_port']; 
		$this->path = $data['submit_url']; 
		$this->challenge = $data['challenge']; 

                // Update the preferences
                Preference::update('librefm_port',$user_id,$data['submit_port']);
                Preference::update('librefm_host',$user_id,$data['submit_host']);
                Preference::update('librefm_url',$user_id,$data['submit_url']);
                Preference::update('librefm_challenge',$user_id,$data['challenge']);

		return true; 

	} // set_handshake

	/**
	 * load
	 * This loads up the data we need into this object, this stuff comes from the preferences
	 * it's passed as a key'd array
	 */
	public function load($data,$user_id) { 

		if (strlen(trim($data['librefm_user']))) { 
			$this->username = trim($data['librefm_user']); 
		} 
		else { 
			debug_event('librefm','No Username, not scrobbling','3'); 
			return false; 
		} 
		if (strlen(trim($data['librefm_pass']))) { 
			$this->password = trim($data['librefm_pass']); 
		} 
		else { 
			debug_event('librefm','No Password, not scrobbling','3'); 
			return false; 
		} 

		// If we don't have the other stuff try to get it before giving up
		if (!$data['librefm_host'] || !$data['librefm_port'] || !$data['librefm_url'] || !$data['librefm_challenge']) { 
			debug_event('librefm','Running Handshake, missing information','3'); 
			if (!$this->set_handshake($user_id)) { 
				debug_event('librefm','Handshake failed, you lose','3');
				return false; 
			} 
		}  
		else { 
	                $this->hostname = $data['librefm_host'];
	                $this->port = $data['librefm_port'];
	                $this->path = $data['librefm_url'];
	                $this->challenge = $data['librefm_challenge'];
		} 
		

		return true; 	

	} // load


} // end Ampachelibrefm
?>
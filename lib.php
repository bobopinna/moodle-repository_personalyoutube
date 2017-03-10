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
 * This plugin is used to access personalyoutube videos
 *
 * @package    repository_personalyoutube
 * @copyright  2017 Roberto Pinna
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/repository/lib.php');
require_once($CFG->libdir . '/google/lib.php');

/**
 * repository_personalyoutube class
 *
 * @package    repository_personalyoutube
 * @copyright  2017 Roberto Pinna
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_personalyoutube extends repository {
    /** @var int maximum number of thumbs per page */
    const YOUTUBE_THUMBS_PER_PAGE = 27;

    /**
     * Google Client.
     * @var Google_Client
     */
    private $client = null;

    /**
     * YouTube Service.
     * @var Google_Service_YouTube
     */
    private $service = null;

    /**
     * Session key to store the accesstoken.
     * @var string
     */
    const SESSIONKEY = 'personalyoutube_accesstoken';

    /**
     * URI to the callback file for OAuth.
     * @var string
     */
    const CALLBACKURL = '/admin/oauth2callback.php';

    /**
     * Youtube plugin constructor
     * @param int $repositoryid
     * @param object $context
     * @param array $options
     * @param int $readonly
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array(), $readonly = 0) {
        parent::__construct($repositoryid, $context, $options, $readonly = 0);

        $callbackurl = new moodle_url(self::CALLBACKURL);

        $this->client = get_google_client();
        $this->client->setClientId(get_config('personalyoutube', 'clientid'));
        $this->client->setClientSecret(get_config('personalyoutube', 'secret'));
        $this->client->setScopes(array(Google_Service_YouTube::YOUTUBE_READONLY));
        $this->client->setRedirectUri($callbackurl->out(false));
        $this->service = new Google_Service_YouTube($this->client);

        $this->check_login();
    }

    /**
     * Returns the access token if any.
     *
     * @return string|null access token.
     */
    protected function get_access_token() {
        global $SESSION;

        if (isset($SESSION->{self::SESSIONKEY})) {
            return $SESSION->{self::SESSIONKEY};
        }
        return null;
    }

    /**
     * Store the access token in the session.
     *
     * @param string $token token to store.
     * @return void
     */
    protected function store_access_token($token) {
        global $SESSION;
        $SESSION->{self::SESSIONKEY} = $token;
    }

    /**
     * Callback method during authentication.
     *
     * @return void
     */
    public function callback() {
        if ($code = optional_param('oauth2code', null, PARAM_RAW)) {
            $this->client->authenticate($code);
            $this->store_access_token($this->client->getAccessToken());
        }
    }

    /**
     * Checks whether the user is authenticate or not.
     *
     * @return bool true when logged in.
     */
    public function check_login() {
        if ($token = $this->get_access_token()) {
            $this->client->setAccessToken($token);
            return true;
        }
        return false;
    }

    /**
     * Print or return the login form.
     *
     * @return void|array for ajax.
     */
    public function print_login() {
        $returnurl = new moodle_url('/repository/repository_callback.php');
        $returnurl->param('callback', 'yes');
        $returnurl->param('repo_id', $this->id);
        $returnurl->param('sesskey', sesskey());

        $url = new moodle_url($this->client->createAuthUrl());
        $url->param('state', $returnurl->out_as_local_url(false));
        if ($this->options['ajax']) {
            $popup = new stdClass();
            $popup->type = 'popup';
            $popup->url = $url->out(false);
            return array('login' => array($popup));
        } else {
            echo '<a target="_blank" href="'.$url->out(false).'">'.get_string('login', 'repository').'</a>';
        }
    }

    /**
     * Logout.
     *
     * @return string
     */
    public function logout() {
        $this->store_access_token(null);
        return parent::logout();
    }

    /**
     * Personale Youtube plugin doesn't support global search
     */
    public function global_search() {
        return false;
    }

    /**
     * Get video listing
     *
     * @param string $path
     * @param string $page no paging is used in repository_local
     * @return mixed
     */
    public function get_listing($path='', $page = '') {
        // Check to ensure that the access token was successfully acquired.
        $channelid = '';
        $results = array();

        if ($this->client->getAccessToken()) {
            try {
                // Call the channels.list method to retrieve information about the
                // currently authenticated user's channel.
                $channelsresponse = $this->service->channels->listChannels('contentDetails', array( 'mine' => 'true'));

                foreach ($channelsresponse['items'] as $channel) {

                    $channelid = $channel['id'];

                    // Extract the unique playlist ID that identifies the list of videos
                    // uploaded to the channel, and then call the playlistItems.list method
                    // to retrieve that list.
                    $uploadslistid = $channel['contentDetails']['relatedPlaylists']['uploads'];

                    $playlistitemsresponse = $this->service->playlistItems->listPlaylistItems('snippet', array(
                            'playlistId' => $uploadslistid,
                            'maxResults' => 50
                    ));

                    foreach ($playlistitemsresponse['items'] as $playlistitem) {
                        $title = $playlistitem->snippet->title;
                        $source = 'http://www.youtube.com/watch?v=' . $playlistitem->snippet->resourceId->videoId . '#' . $title;
                        $thumb = $playlistitem->snippet->getThumbnails()->getDefault();

                        $results[] = array(
                                'shorttitle' => $title,
                                'thumbnail_title' => $playlistitem->snippet->description,
                                'title' => $title.'.mp4', // This is a hack so we accept this file by extension.
                                'thumbnail' => $thumb->url,
                                'thumbnail_width' => (int)$thumb->width,
                                'thumbnail_height' => (int)$thumb->height,
                                'size' => '',
                                'date' => '',
                                'source' => $source,
                        );
                    }
                }
            } catch (Google_Service_Exception $e) {
                // If we throw the google exception as-is, we may expose the apikey
                // to end users. The full message in the google exception includes
                // the apikey param, so we take just the part pertaining to the
                // actual error.
                $error = $e->getErrors()[0]['message'];
                throw new moodle_exception('apierror', 'repository_youtube', '', $error);
            } catch (Google_Exception $e) {
                $this->logout();
                return null;
            }

        } else {
            $this->logout();
            return null;
        }

        $ret = array();
        $ret['dynload'] = true;
        $ret['manage'] = 'https://www.youtube.com/channel/'.$channelid;
        $ret['path'] = array(array('name' => get_string('uploads', 'repository_personalyoutube'), 'path' => '/'));
        $ret['list'] = $results;
        return $ret;
    }

    /**
     * file types supported by personalyoutube plugin
     * @return array
     */
    public function supported_filetypes() {
        return array('video');
    }

    /**
     * Personal Youtube plugin only return external links
     * @return int
     */
    public function supported_returntypes() {
        return FILE_EXTERNAL;
    }

    /**
     * Is this repository accessing private data?
     *
     * @return bool
     */
    public function contains_private_data() {
        return true;
    }

    /**
     * Return names of the general options.
     * By default: no general option name.
     *
     * @return array
     */
    public static function get_type_option_names() {
        return array('clientid', 'secret', 'pluginname');
    }

    /**
     * Edit/Create Admin Settings Moodle form.
     *
     * @param moodleform $mform Moodle form (passed by reference).
     * @param string $classname repository class name.
     */
    public static function type_config_form($mform, $classname = 'repository') {

        $callbackurl = new moodle_url(self::CALLBACKURL);

        $a = new stdClass;
        $a->docsurl = get_docs_url('Google_OAuth_2.0_setup');
        $a->callbackurl = $callbackurl->out(false);

        $mform->addElement('static', null, '', get_string('oauthinfo', 'repository_personalyoutube', $a));

        parent::type_config_form($mform);
        $mform->addElement('text', 'clientid', get_string('clientid', 'repository_personalyoutube'));
        $mform->setType('clientid', PARAM_RAW_TRIMMED);
        $mform->addElement('text', 'secret', get_string('secret', 'repository_personalyoutube'));
        $mform->setType('secret', PARAM_RAW_TRIMMED);

        $strrequired = get_string('required');
        $mform->addRule('clientid', $strrequired, 'required', null, 'client');
        $mform->addRule('secret', $strrequired, 'required', null, 'client');
    }


    /**
     * Return search results
     * @param string $search_text
     * @param int $page
     * @return array
     */
    public function search($keyword, $page = 0) {
        $ret  = array();

        // Check to ensure that the access token was successfully acquired.
        if ($this->client->getAccessToken()) {
            $list = array();
            $error = null;
            try {
                $response = $this->service->search->listSearch('snippet', array(
                    'q' => $keyword,
                    'maxResults' => self::YOUTUBE_THUMBS_PER_PAGE,
                    'type' => 'video',
                    'forMine' => 'true',
                ));

                foreach ($response['items'] as $result) {
                    $title = $result->snippet->title;
                    $source = 'http://www.youtube.com/v/' . $result->id->videoId . '#' . $title;
                    $thumb = $result->snippet->getThumbnails()->getDefault();

                    $list[] = array(
                        'shorttitle' => $title,
                        'thumbnail_title' => $result->snippet->description,
                        'title' => $title.'.mp4', // This is a hack so we accept this file by extension.
                        'thumbnail' => $thumb->url,
                        'thumbnail_width' => (int)$thumb->width,
                        'thumbnail_height' => (int)$thumb->height,
                        'size' => '',
                        'date' => '',
                        'source' => $source,
                    );
                }
            } catch (Google_Service_Exception $e) {
                // If we throw the google exception as-is, we may expose the clientid
                // to end users. The full message in the google exception includes
                // the clientid param, so we take just the part pertaining to the
                // actual error.
                $error = $e->getErrors()[0]['message'];
                throw new moodle_exception('apierror', 'repository_personalyoutube', '', $error);
            }
            $ret['issearchresult'] = true;
            $ret['list'] = $list;
        } else {
            $this->logout();
            return null;
        }

        return $ret;
    }

}

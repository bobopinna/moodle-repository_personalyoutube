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

/**
 * Personal Youtube Plugin
 *
 * @package    repository_personalyoutube
 * @copyright  2017 Roberto Pinna
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_personalyoutube extends repository {
    /** @var int maximum number of thumbs per page */
    const PERSONALYOUTUBE_THUMBS_PER_PAGE = 29;

    /**
     * OAuth 2 Client.
     * @var \core\oauth2\client
     */
    private $client = null;

    /**
     * OAuth 2 Issuer
     * @var \core\oauth2\issuer
     */
    private $issuer = null;

    /**
     * Additional scopes required for drive.
     */
    const SCOPES = 'https://www.googleapis.com/auth/youtube';

    /**
     * Youtube plugin constructor
     *
     * @param int $repositoryid
     * @param object $context
     * @param array $options
     * @param int $readonly
     * @return void
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array(), $readonly = 0) {
        parent::__construct($repositoryid, $context, $options, $readonly = 0);

        try {
            $this->issuer = \core\oauth2\api::get_issuer(get_config('personalyoutube', 'issuerid'));
        } catch (dml_missing_record_exception $e) {
            $this->disabled = true;
        }

        if ($this->issuer && !$this->issuer->get('enabled')) {
            $this->disabled = true;
        }
    }

    /**
     * Get a cached user authenticated oauth client.
     *
     * @param moodle_url $overrideurl - Use this url instead of the repo callback.
     * @return \core\oauth2\client
     */
    protected function get_user_oauth_client($overrideurl = false) {
        if ($this->client) {
            return $this->client;
        }
        if ($overrideurl) {
            $returnurl = $overrideurl;
        } else {
            $returnurl = new moodle_url('/repository/repository_callback.php');
            $returnurl->param('callback', 'yes');
            $returnurl->param('repo_id', $this->id);
            $returnurl->param('sesskey', sesskey());
        }

        $this->client = \core\oauth2\api::get_user_oauth_client($this->issuer, $returnurl, self::SCOPES);

        return $this->client;
    }

    /**
     * Checks whether the user is authenticate or not.
     *
     * @return bool true when logged in.
     */
    public function check_login() {
        $client = $this->get_user_oauth_client();
        return $client->is_logged_in();
    }

    /**
     * Print or return the login form.
     *
     * @return void|array for ajax.
     */
    public function print_login() {
        $client = $this->get_user_oauth_client();
        $url = $client->get_login_url();

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
        $client = $this->get_user_oauth_client();
        $client->log_out();
        return parent::logout();
    }

    /**
     * Store the access token.
     */
    public function callback() {
        $client = $this->get_user_oauth_client();
        // This will upgrade to an access token if we have an authorization code and save the access token in the session.
        $client->is_logged_in();
    }

    /**
     * Personal Youtube plugin doesn't support global search
     */
    public function global_search() {
        return false;
    }

    /**
     * Get video listing
     *
     * @param string $path
     * @param string $page
     * @return mixed
     */
    public function get_listing($path='', $page = '') {
        global $SESSION;

        $ret = array();
        $ret['page'] = (int)$page;
        if ($ret['page'] < 1) {
            $ret['page'] = 1;
        }
        $start = ($ret['page'] - 1) * self::PERSONALYOUTUBE_THUMBS_PER_PAGE + 1;
        $max = self::PERSONALYOUTUBE_THUMBS_PER_PAGE;

        // The new API doesn't use "page" numbers for browsing through results.
        // It uses a prev and next token in each set that you need to use to
        // request the next page of results.
        $sesspagetoken = 'personalyoutube_'.$this->id.'_nextpagetoken';
        $pagetoken = '';
        if ($start > 1 && isset($SESSION->{$sesspagetoken})) {
            $pagetoken = $SESSION->{$sesspagetoken};
        }

        $channelid = '';
        $results = array();
        $total = 0;

        try {
            $client = $this->get_user_oauth_client();
            $service = new repository_personalyoutube\rest($client);

            $channelparams = array('part' => 'contentDetails', 'mine' => 'true');
            $channelsresponse = $this->youtube_decode($service->call('channels', $channelparams));

            foreach ($channelsresponse->items as $channel) {

                $channelid = $channel->id;

                // Extract the unique playlist ID that identifies the list of videos
                // uploaded to the channel, and then call the playlistItems.list method
                // to retrieve that list.
                $uploadslistid = $channel->contentDetails->relatedPlaylists->uploads;

                $playlistparams = array(
                        'part' => 'snippet',
                        'playlistId' => $uploadslistid,
                        'pageToken' => $pagetoken,
                        'maxResults' => self::PERSONALYOUTUBE_THUMBS_PER_PAGE
                );
                $playlistitemsresponse = $this->youtube_decode($service->call('playlistItems', $playlistparams));
                $total += $playlistitemsresponse->pageInfo->totalResults;

                // Track the next page token for the next request (when a user
                // scrolls down in the file picker for more videos).
                if (isset($playlistitemsresponse->nextPageToken)) {
                    $SESSION->{$sesspagetoken} = $playlistitemsresponse->nextPageToken;
                } else {
                    $SESSION->{$sesspagetoken} = '';
                }

                foreach ($playlistitemsresponse->items as $playlistitem) {
                    $title = $playlistitem->snippet->title;
                    $source = 'http://www.youtube.com/watch?v=' . $playlistitem->snippet->resourceId->videoId . '#' . $title;
                    $thumb = $playlistitem->snippet->thumbnails->default;

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
        } catch (Exception $e) {
            if ($e->getCode() == 403 && strpos($e->getMessage(), 'Access Not Configured') !== false) {
                // This is raised when the service YouTube API has not been enabled on Google APIs control panel.
                throw new repository_exception('servicenotenabled', 'repository_personalyoutube');
            } else {
                throw $e;
            }
        }

        $ret['dynload'] = true;
        $ret['total'] = $total;
        $ret['manage'] = 'https://www.youtube.com/channel/'.$channelid;
        $ret['path'] = array(array('name' => get_string('uploads', 'repository_personalyoutube'), 'path' => '/'));
        $ret['list'] = $results;
        // If the number of results is smaller than $max, it means we reached the last page.
        $ret['pages'] = (count($ret['list']) < $max) ? $ret['page'] : -1;
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
        return array('issuerid', 'pluginname');
    }

    /**
     * Edit/Create Admin Settings Moodle form.
     *
     * @param moodleform $mform Moodle form (passed by reference).
     * @param string $classname repository class name.
     */
    public static function type_config_form($mform, $classname = 'repository') {
        $url = new moodle_url('/admin/tool/oauth2/issuers.php');
        $url = $url->out();

        $mform->addElement('static', null, '', get_string('oauth2serviceslink', 'repository_personalyoutube', $url));

        parent::type_config_form($mform, $classname);
        $options = [];
        $issuers = \core\oauth2\api::get_all_issuers();

        foreach ($issuers as $issuer) {
            $options[$issuer->get('id')] = s($issuer->get('name'));
        }

        $strrequired = get_string('required');

        $mform->addElement('select', 'issuerid', get_string('issuer', 'repository_personalyoutube'), $options);
        $mform->addHelpButton('issuerid', 'issuer', 'repository_personalyoutube');
        $mform->addRule('issuerid', $strrequired, 'required', null, 'client');
    }

    /**
     * Return search results
     *
     * @param string $searchtext The text to search
     * @param int $page
     * @return array
     */
    public function search($searchtext, $page = 0) {
        global $SESSION;
        $sort = optional_param('personalyoutube_sort', '', PARAM_TEXT);
        $sesskeyword = 'personalyoutube_'.$this->id.'_keyword';
        $sesssort = 'personalyoutube_'.$this->id.'_sort';

        // This is the request of another page for the last search, retrieve the cached keyword and sort.
        if ($page && !$searchtext && isset($SESSION->{$sesskeyword})) {
            $searchtext = $SESSION->{$sesskeyword};
        }
        if ($page && !$sort && isset($SESSION->{$sesssort})) {
            $sort = $SESSION->{$sesssort};
        }
        if (!$sort) {
            $sort = 'relevance'; // Default.
        }

        // Save this search in session.
        $SESSION->{$sesskeyword} = $searchtext;
        $SESSION->{$sesssort} = $sort;

        $this->keyword = $searchtext;
        $ret  = array();
        $ret['page'] = (int)$page;
        if ($ret['page'] < 1) {
            $ret['page'] = 1;
        }
        $start = ($ret['page'] - 1) * self::PERSONALYOUTUBE_THUMBS_PER_PAGE + 1;
        $max = self::PERSONALYOUTUBE_THUMBS_PER_PAGE;
        $ret['list'] = $this->_get_collection($searchtext, $start, $max, $sort);
        // If the number of results is smaller than $max, it means we reached the last page.
        $ret['pages'] = (count($ret['list']) < $max) ? $ret['page'] : -1;
        return $ret;
    }

    /**
     * Private method to get youtube search results
     * @param string $keyword
     * @param int $start
     * @param int $max max results
     * @param string $sort
     * @throws moodle_exception If the google API returns an error.
     * @return array
     */
    private function _get_collection($keyword, $start, $max, $sort) {
        global $SESSION;

        // The new API doesn't use "page" numbers for browsing through results.
        // It uses a prev and next token in each set that you need to use to
        // request the next page of results.
        $sesspagetoken = 'personalyoutube_'.$this->id.'_nextpagetoken';
        $pagetoken = '';
        if ($start > 1 && isset($SESSION->{$sesspagetoken})) {
            $pagetoken = $SESSION->{$sesspagetoken};
        }

        $list = array();
        $error = null;

        try {
            $client = $this->get_user_oauth_client();
            $service = new repository_personalyoutube\rest($client);

            $params = array(
                'part' => 'snippet',
                'q' => $keyword,
                'maxResults' => self::PERSONALYOUTUBE_THUMBS_PER_PAGE,
                'order' => $sort,
                'pageToken' => $pagetoken,
                'type' => 'video',
                'forMine' => 'true',
            );
            $response = $this->youtube_decode($service->call('search', $params));

            // Track the next page token for the next request (when a user
            // scrolls down in the file picker for more videos).
            if (isset($response->nextPageToken)) {
                $SESSION->{$sesspagetoken} = $response->nextPageToken;
            } else {
                $SESSION->{$sesspagetoken} = '';
            }

            foreach ($response->items as $result) {
                $title = $result->snippet->title;
                $source = 'http://www.youtube.com/v/' . $result->id->videoId . '#' . $title;
                $thumb = $result->snippet->thumbnails->default;

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
        } catch (Exception $e) {
            if ($e->getCode() == 403 && strpos($e->getMessage(), 'Access Not Configured') !== false) {
                // This is raised when the service Drive API has not been enabled on Google APIs control panel.
                throw new repository_exception('servicenotenabled', 'repository_personalyoutube');
            } else {
                throw $e;
            }
        }

        return $list;
    }

    /**
     * Get YouTube API json response string perform quatas cleaning and return json object
     *
     * @param string $jsonstring
     * @return stdClass JSON object
     */
    private function youtube_decode($jsonstring) {
        return json_decode(preg_replace('/\"etag\": "\\\\"(.*)\\\\""/', '"etag": "$1"', $jsonstring));
    }
}

/**
 * Callback to get the required scopes for system account.
 *
 * @param \core\oauth2\issuer $issuer
 * @return string
 */
function repository_personaltube_oauth2_system_scopes(\core\oauth2\issuer $issuer) {
    if ($issuer->get('id') == get_config('personalyoutube', 'issuerid')) {
        return 'https://www.googleapis.com/auth/youtube';
    }
    return '';
}

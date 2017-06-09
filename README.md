Personal YouTube repository
===========================
Moodle repository plugin used to browse personal YouTube channels in order to publish public, unlisted and private videos.

This plugin take the starting idea from [Justin Hunt's MyTube plugin](https://github.com/justinhunt/moodle-repository_mytube), now outdated by YouTube updates.

The code is a mash up of [Moodle Google Drive](https://github.com/moodle/moodle/tree/master/repository/googledocs) and [Moodle YouTube](https://github.com/moodle/moodle/tree/master/repository/youtube) repositories for easy maintenance.

Requirements
------------
- YouTube API enabled in your Google API console
- OAuth2 Service for your moodle site as for Google Drive repository plugin; see [OAuth 2 Services](https://docs.moodle.org/en/OAuth_2_services) in Moodle Docs for all details

Installation
------------
- Download zip file
- Copy it in moodle/repository directory
- Unzip it and remove zip file
- Log in as administrator to your moodle site
- Follow online installation procedure
- Set as Enabled and Visible the Personal YouTube repository in Administration Block -&gt; Site Administration -&gt; Plugins -&gt; Repositories -&gt; Manage repositories
- Choose the Oauth service in configuration page.

Use (URL Resources)
-------------------
- Add an URL resource and click on "Choose a link..."
- In the Moodle File picker choose "Personal YouTube"
- Click on "Login to your account"
- Login with your Google account
- Allow access to your YouTube Channel (only the first time)
- All your uploaded videos will be shown
- Click on the one you want link in moodle and then on "Select this file"
- Set a name for your URL resouce and save.

Use (embed videos in moodle resources or activity)
--------------------------------------------------
**Note:** *Moodle administrator must enable Multimedia plugins filter to use embedded videos*

- Add a resource or an activity
- In any online text editor (Atto or TinyMCE) click on "Add Insert Media" icon (a piece of movie film with small triangle in the middle)
- In the Moodle File picker choose "Personal YouTube"
- Click on "Login to your account"
- Login with your Google account
- Allow access to your YouTube Channel (only the first time)
- All your uploaded videos will be shown
- Click on the one you want link in moodle and then on "Select this file"
- The video link will be added in the editor
- Save and view to see the result.

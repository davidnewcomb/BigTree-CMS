<?php
	namespace BigTree;

	$api = new \BigTreeFacebookAPI(false);
	$name = "Facebook";
	$route = "facebook";
	$key_name = "App ID";
	$secret_name = "App Secret";
	$show_test_environment = false;
	$scope_default = "user_about_me,user_actions.books,user_actions.fitness,user_actions.music,user_actions.news,user_actions.video,user_birthday,user_education_history,user_events,user_games_activity,user_hometown,user_likes,user_location,user_managed_groups,user_photos,user_posts,user_relationships,user_relationship_details,user_religion_politics,user_status,user_tagged_places,user_videos,user_website,user_work_history,manage_pages,publish_pages,publish_actions";
	$scope_help = Text::translate(' <small>(see <a href=":scope_link:" target="_blank">Permissions</a>)</small>', false, array(":scope_link:" => "https://developers.facebook.com/docs/facebook-login/permissions/v2.4"));
	$instructions = array(
		Text::translate('<a href=":facebook_link:" target="_blank">Add a new Facebook app</a>.', false, array(":facebook_link:" => "https://developers.facebook.com/apps/")),
		Text::translate('Inside your app\'s Dashboard, go to Settings and then the "Advanced" tab.'),
		Text::translate('Find the Client OAuth Settings section, add :redirect_uri: as a valid OAuth redirect URI.', false, array(":redirect_uri:" => "DEVELOPER_ROOT.'services/facebook/return/")),
		Text::translate('If you don\'t want your app showing up in your connected account\'s news feed, set Social Discovery to No.'),
		Text::translate('Click "Save Changes".'),
		Text::translate('Go back to the "Basic" tab and enter the App ID and App Secret from that page below.'),
		Text::translate('Follow the OAuth process of allowing BigTree/your application access to your Facebook account.')
	);

	function __localBigTreeAPIReturn(&$api) {
		$user = $api->getUser();
		$api->Settings["user_name"] = $user->Name;
		$api->Settings["user_image"] = $user->getPicture();
		$api->Settings["user_id"] = $user->ID;
	}
?>
<?php

//Setup, only do once
if (!get_option('twitter_db_version')) {
	global $wpdb;

	$table_name = $wpdb->prefix . 'tweets';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id bigint(11) NOT NULL AUTO_INCREMENT,
		tweet_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
		full_text text DEFAULT '' NOT NULL,
		source text DEFAULT '' NOT NULL,
		in_reply_to_status_id bigint(11),
		in_reply_to_screen_name text,
		entities text,
		PRIMARY KEY id (id)
	) $charset_collate;";

	require_once(ABSPATH.'wp-admin/includes/upgrade.php');
	dbDelta($sql);

	add_option('twitter_db_version', '1');
}

// Displays a twitter feed
function display_tweets(int $num) {
	$tweets = get_tweets();
	if ($tweets['error']) {
		echo '<div class="error">Error: '.$tweets['error'].'</div>';
		return;
	}
	
	$username = get_option('twitter_sn');
	$html = ''; $i=0;
	echo '<ul class="tweetlist">';
	if ($tweets) foreach ($tweets as $tweet) if ($i < $num) {
		$un = $tweet['source'] == 'RT' ? $tweet['in_reply_to_screen_name'] : $username;
		$dbhtml = get_the_datebox(true,$tweet['tweet_date'],"https://twitter.com/{$un}/status/{$tweet['id']}");
		$text = filter_tweet($tweet['full_text']);
				
		echo "<li class=\"tweet\">{$dbhtml}<div class=\"tweet-content\">{$text}";
		if ($tweet['in_reply_to_status_id'] && $tweet['source'] != 'RT')
			echo "<p class=\"viewconvo\"><a href=\"https://twitter.com/{$tweet['in_reply_to_screen_name']}/status/{$tweet['in_reply_to_status_id']}\">&#9668; In reply to @{$tweet['in_reply_to_screen_name']}</a></p>";
		echo "</div></li>";
		$i++;
	}
	echo "</ul>";
}

//The sidebar widget
class twitter_widget extends WP_Widget {
	function __construct() {
		parent::__construct('twitter', 'Twitter', [
			'classname' => 'widget_twitter',
			'description' => 'Displays tweets',
			'customize_selective_refresh' => true
		]);
	}
	
	function widget($args, $instance) {
		echo $args['before_widget'];
		$args['before_title'] .= "<a href=\"https://twitter.com/".get_option('twitter_sn').'">';
		$args['after_title'] = '</a>'.$args['after_title'];
		echo $args['before_title'].apply_filters('widget_title', $instance['title']).$args['after_title'];
		
		display_tweets($instance['num_tweets']);
		echo $args['after_widget'];
	}
	
	function form($instance) {
		$title = !empty($instance['title']) ? $instance['title'] : 'Twitter';
		$num_tweets = !empty($instance['num_tweets']) ? $instance['num_tweets'] : 2 ?>
		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>">Title:</label> 
			<input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>">
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('num_tweets'); ?>">Number of Tweets:</label> 
			<input id="<?php echo $this->get_field_id('num_tweets'); ?>" name="<?php echo $this->get_field_name('num_tweets'); ?>" type="number" value="<?php echo esc_attr($num_tweets); ?>">
		</p><?php 
	}

	function update($new_instance, $old_instance) {
		$instance = [
			'title' => !empty($new_instance['title']) ? strip_tags($new_instance['title']) : '',
			'num_tweets' => !empty($new_instance['num_tweets']) ? strip_tags($new_instance['num_tweets']) : ''
		];
		return $instance;
	}
}
add_action('widgets_init', function() {
	register_widget('twitter_widget');
});

function filter_tweet(string $tweet, array $highlights=[]) {
	//Truncates and linkifies http:// links
	preg_match_all("#([\w]+?://[\w]+[^ \"\n\r\t<)]*)#is", $tweet, $l);
	foreach ($l[0] as $url) {
		$bare_url = str_replace(['http://','https://'],'',$url);
		$short_url = (strlen($bare_url) > 23 ? substr($bare_url,0,22).'&hellip;' : $bare_url);
		$tweet = str_replace($url,'<a href="'.$url.'" title="'.$bare_url.'">'.$short_url.'</a>',$tweet);
	}
	
	//Linkifies @replies
	$tweet = preg_replace("#(^|[\n \.])@([^ \:\.\"\t\n\r\(\)<,&]*)#is", "\\1<a href=\"https://www.twitter.com/\\2\" >@\\2</a>", $tweet);
	
	//Linkifies Hashtags
	$tweet = preg_replace('/(^|\s)#(\w*[a-zA-Z_]+\w*)/', '\1<a href="https://twitter.com/search?q=%23\2">#\2</a>', $tweet);
	
	//Highlights search terms
	foreach ($highlights as $term)
		$tweet = preg_replace("/<[^>]*>(*SKIP)(*F)|(".preg_quote($term,'/').")/i", "<strong class=\"search_highlight\">\\1</strong>", $tweet);
	
	return wptexturize(nl2br($tweet));
}

//Get tweets from public JSON, cache them, and write them to the database if they're not already there.
//Starts at 0, not 1.
function get_tweets(int $start=null, int $number=null) {
	global $wpdb;
	
	//If we've got to query the Tweets database, do that and skip all the rest
	if (($start && $number) || $number > 5) {
		$q = "SELECT * FROM {$wpdb->prefix}tweets ORDER BY id DESC LIMIT $start,$number";
		$tweets = $wpdb->get_results($q, ARRAY_A);
	
	} else {
		//Check if we've got tweets. If not, query the Twitter API
		$tweets = get_transient('tweets');
		if ($tweets===false) {
			
			//Jump through hoops for the lords of Twitter and Abraham's awful mess of a library
			$connection = new Abraham\TwitterOAuth\TwitterOAuth(
				get_option('twitter_consumer_key'),
				get_option('twitter_consumer_secret'),
				get_option('twitter_access_key'),
				get_option('twitter_access_token_secret')
			);
			try {
				$tweets = $connection->get("statuses/user_timeline", [
					'screen_name' => get_option('twitter_sn'),
					'tweet_mode' => 'extended'
				]);
			} catch (Exception $e) {
				$message = $e->getMessage();
			}
			if (!$tweets) return ['error' => $message ? $message : 'No Tweets'];
	
			//Check if the tweet has been archived. If not add it to the database.
			$q="SELECT id FROM {$wpdb->prefix}tweets ORDER BY id DESC LIMIT 1";
			$r = $wpdb->get_results($q);
			foreach ($r as $i) $maxid = $i->id;
			
			if (isset($tweets->errors)) {
				foreach ($tweets->errors as $error)
					throw new Exception("Error {$error->code}: {$error->message}");
				return [];
			} else foreach ($tweets as &$tweet) {
				$tdata = prepare_tweet_array($tweet);
				
				if ($tdata['id'] > $maxid) {
					$sqinsert = $tdata; //Prepare for SQL
					foreach ($sqinsert as &$var)
						if (empty($var) && $var !== 0) $var = NULL;	//Don't convert 0 int to null
						elseif (is_array($var)) $var = serialize($var);
					extract($sqinsert);
					
					//Insert into DB
					$r = $wpdb->insert($wpdb->prefix.'tweets', [
						'id' => $id,
						'tweet_date' => $tweet_date,
						'full_text' => $full_text,
						'source' => $source,
						'in_reply_to_status_id' => $in_reply_to_status_id,
						'in_reply_to_screen_name' => $in_reply_to_screen_name,
						'entities' => $entities
					], ['%d', '%s', '%s', '%s', '%d', '%s', '%s']);
					echo $wpdb->last_error;
				}
				$tweet = $tdata;
			}
			set_transient('tweets', $tweets, 900); //Only query once every fifteen minutes, at most
		}
	
		if (is_numeric($number)) $tweets = array_slice($tweets,0,$number);
	}
	
	//Prepare tweet array
	foreach ($tweets as &$tweet) {
		$tweet['tweet_date'] = strtotime($tweet['tweet_date']);
		if (is_string($tweet['entities'])) $tweet['entities'] = unserialize($tweet['entities']);
	}
	return $tweets;
}

//Takes an object straight from the Twitter API
//Second argument is whether to include the user array
//Recursive, so also prepares the array in quoted_status
function prepare_tweet_array($tweet, $is_qt=false) {
	$tdata = []; //Where we're going to put all the data
	$tdata['id'] = $tweet->id_str; //The numeric IDs are too big for the integer field
	$tdata['in_reply_to_status_id'] = $tweet->in_reply_to_status_id_str;
	$tdata['tweet_date'] = date("Y-m-d H:i:s",strtotime($tweet->created_at)-5*3600); //GMT-5
	// $tdata['retweet_count'] = $tweet->retweet_count;
	
	//Get retweet data if it exists, capturing the whole tweet.
	if ($tweet->retweeted_status) {
		$tdata['in_reply_to_status_id'] = $tweet->retweeted_status->id_str;
		$tdata['in_reply_to_screen_name'] = $tweet->retweeted_status->user->screen_name;
		$tdata['source'] = 'RT';
		$tdata['full_text'] = "RT @{$tweet->retweeted_status->user->screen_name}: ".$tweet->retweeted_status->full_text;
		$tdata['quoted_status'] = $tweet->retweeted_status->quoted_status;
		$tdata['entities'] = (array)$tweet->retweeted_status->entities;
		if ($tweet->retweeted_status->extended_entities) 
			foreach ($tweet->retweeted_status->extended_entities as $k => $v)
				$tdata['entities'][$k] = (array)$v;
	} else {
		$tdata['in_reply_to_status_id'] = ($tweet->in_reply_to_status_id ? $tweet->in_reply_to_status_id : NULL);
		$tdata['in_reply_to_screen_name'] = ($tweet->in_reply_to_screen_name ? $tweet->in_reply_to_screen_name : NULL);
		$tdata['source'] = strip_tags($tweet->source);
		$tdata['full_text'] = $tweet->full_text;
		$tdata['quoted_status'] = $tweet->quoted_status;
		$tdata['entities'] = (array)$tweet->entities;
		if ($tweet->extended_entities)
			foreach ($tweet->extended_entities as $k => $v)
				$tdata['entities'][$k] = (array)$v;
	}
	// $tdata['full_text'] = wp_encode_emoji($tdata['full_text']); //Since htmlentities doesn't capture emoji
	if ($tdata['quoted_status']) $tdata['entities']['quoted_status'] = prepare_tweet_array($tdata['quoted_status'], true);
	unset($tdata['quoted_status']);
	
	//Keep user data if not qt, so we can cache it and use it on the homepage
	//Otherwise get rid of it so we don't store it all in the entities column
	$tdata['user'] = $tweet->user;
	if ($is_qt)
		foreach ($tdata['user'] as $k => $v)
			if (!in_array($k, ['id', 'name', 'screen_name']))
				unset($tdata['user']->{$k});
	
	
	//Cull unnecessary data
	foreach ($tdata['entities'] as $k => &$etype)
		if (empty($etype))
			unset($tdata['entities'][$k]);
		else foreach ($etype as &$entity) {
			$entity->id = $entity->id_str;
			unset($entity->id_str);
			
			//Use real URLs
			if ($k == 'urls')
				$tdata['full_text'] = str_replace($entity->url, $entity->expanded_url, $tdata['full_text']);
			
			elseif ($k=='media')
				// $tdata['full_text'] = str_replace($entity->url, $entity->expanded_url, $tdata['full_text']);
				unset($entity->media_url, $entity->sizes->thumb, $entity->expanded_url, $entity->features);
		}
	
	//If we've replaced all the urls with real ones, we don't need this
	if (isset($tdata['entities']['urls']))
		unset($tdata['entities']['urls']);
	
	return $tdata;
}

//Abraham's stupid autoloader, called whenever a class is instantiated (jeez)
//v1.0.1
spl_autoload_register(function ($class) {
	$prefix = 'Abraham\\TwitterOAuth\\';
	$len = strlen($prefix);						//If class is in our namespace, include file by that name
	if (strncmp($prefix, $class, $len) !== 0) return;
	$file = __DIR__.'/twitteroauth/'.str_replace('\\', '/', substr($class, $len)).'.php';
	if (file_exists($file)) include_once $file;
});

/*
 * SETTINGS
 */

add_action('admin_init', function() {
	add_settings_section('twitter', 'Twitter API', function() {}, 'reading');
		
	$vars = [
		'sn' => 'Twitter handle',
		'consumer_key' => 'Consumer key',
		'consumer_secret' => 'Consumer secret',
		'access_key' => 'Access key',
		'access_token_secret' => 'Access token secret'
	];
	foreach ($vars as $var => $name) {
		$var = "twitter_$var";
		register_setting('reading', $var);
		add_settings_field($var, $name, function() use ($var) {
			$default = get_option($var);
			echo "<input type=\"text\" value=\"{$default}\" class=\"regular-text ltr\" name=\"{$var}\" id=\"{$var}\" />";
		}, 'reading', 'twitter');
	}
});

/*
 * REST API
 */

add_action('rest_api_init', function() {
	register_rest_route('tweets', '/recent', [
		'methods' => 'GET',
		'callback' => 'twitter_rest_query',
		'permission_callback' => '__return_true'
	]);
	
	register_rest_route('tweets', '/search', [
		'methods' => 'GET',
		'callback' => 'twitter_rest_search',
		'permission_callback' => '__return_true'
	]);
	
	register_rest_route('tweets', '/since', [
		'methods' => 'GET',
		'callback' => 'twitter_rest_since',
		'permission_callback' => '__return_true'
	]);
});

function twitter_rest_query(WP_REST_Request $request) {	
	try {
		$return = get_tweets((int)$request['start'],(int)$request['number']);
		foreach ($return as &$tweet) {
			$tweet['full_text'] = filter_tweet($tweet['full_text']);
			if ($tweet['entities']['quoted_status']) $tweet['entities']['quoted_status']['full_text'] = nl2br($tweet['entities']['quoted_status']['full_text']);
		}
		return $return;
	} catch (Exception $e) {
		return new WP_Error('tweet_error', 'No tweets found', ['status' => 400]);
	}
}

function twitter_rest_since(WP_REST_Request $request) {
	global $wpdb;
	get_tweets(0,10); //Make sure the database is up to date
	
	$q = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}tweets WHERE id > %d ORDER BY id DESC", (int)$request['id']);
	$tweets = $wpdb->get_results($q);
	foreach ($tweets as &$t) {
		$t->full_text = filter_tweet($t->full_text);
		$t->tweet_date = strtotime($t->tweet_date);
		if (is_string($t->entities)) $t->entities = unserialize($t->entities);
		if ($t->entities->quoted_status) $t->entities->quoted_status->full_text = nl2br($t->entities->quoted_status->full_text);
	}
	
	return $tweets;
}

function twitter_rest_search(WP_REST_Request $request) {
	global $wpdb;
	
	$terms = explode(' ',$wpdb->escape($request['term']));
	foreach ($terms as $term) $conds[] = "full_text LIKE '%$term%'";
	$cond = implode(' AND ', $conds);
	
	$num = (int)$request['number'];
	$offset = (int)$request['start'];
	if (!$offset) $offset = 0;
	
	$q="SELECT * FROM {$wpdb->prefix}tweets
		WHERE $cond";
	$q .= ' ORDER BY tweet_date DESC';
	if ($num) $q .= " LIMIT $offset,$num";
	$return = $wpdb->get_results($q);
	foreach ($return as &$r) {
		$r->full_text = filter_tweet($r->full_text, $terms);
		$r->tweet_date = strtotime($r->tweet_date);
		if (is_string($r->entities)) $r->entities = unserialize($r->entities);
		if ($r->entities->quoted_status) $r->entities->quoted_status->full_text = nl2br($r->entities->quoted_status->full_text);
	}
	
	return $return;
}
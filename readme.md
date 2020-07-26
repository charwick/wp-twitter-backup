# Backup and Display Tweets on Wordpress

This code for Wordpress sites archives your tweets in your own database automatically as you tweet, and provides functions for you to display them. Tweets are stored in the wp\_tweets table. It's useful not only for displaying tweets, but also as a backup of your tweets that _you_ own. Features include:

* Minimal code setup (all you have to do is add an `include` statement in functions.php)
* GUI setup in the wp-admin control panel
* Includes tweet metadata (@-replies, threading information, links to media – though not polls, since they are invisible to the public API)
* Follows t.co links and stores the original link. The truncated version is displayed in the markup, same as how it appears on Twitter.
* A sidebar widget
* A generic function to display tweets anywhere
* Markup linkifies links, @-replies, and hashtags
* REST API endpoints

## Setup

Just place the file and the folder in your Wordpress theme folder, and include the following line in your `functions.php`:

	require_once('functions-twitter.php');
	
Then go to Settings → Reading in the admin panel. There will be five textboxes that must be set to retrieve tweets. In order to get the last four, you'll have to [register an app](https://developer.twitter.com/en/apps) on developer.twitter.com. This is what authorizes you to access data through the API. You'll get the API key, secret key, token, and token secret when you register the app.

After authenticating, you can use the sidebar widget in your theme. Or if you prefer something lower-level, `display_tweets($num)` can be used anywhere in the theme. Or if you want fine-grained control over the markup, `get_tweets($start, $number)` returns an array of tweet data that you can display however you like.

Requires PHP 7 and Wordpress 4.7 in principle, though it's only tested on the latest version.

## How It Works

Every time you call a function that retrieves recent tweets, the code does the following:

* Check the cache. If the appropriate number of tweets is included _and_ the cache is less than fifteen minutes old, it displays from the cache. This ensures that high-traffic sites don't get rate-limited or banned from the Twitter API.
* Otherwise, go to Twitter and fetch the number of tweets specified.
* If there are any new tweets, write them to the database
* Cache the result for fifteen minutes

Note that only the tweets following the most recent 10 after you install the code will be archived. To back up previous tweets and import them will require other tools.

## REST API Endpoints

Useful for frontend development. All of the endpoints return a standard JSON object with tweet data.

### `tweets/recent`
Retrieves tweets in reverse chronological order. Takes two `GET` parameters:

* `number`: The number of tweets to retrieve.
* `start`: The offset.

For example, `/tweets/recent?number=5&start=10` will retrieve tweets 11-15 counting from the most recent. This allows for pagination.

### `tweets/since`
Retrieves all tweets whose ID is greater than the `id` parameter. For example, `/tweets/since?id=1283401644856811520` will retrieve all tweets after (but not including) the one with that ID.

### `tweets/search`
Retrieves all tweets containing the search term(s) specified in the `term` parameter; words with a space in between are searched as separate terms. For example, `/tweets/search?term=hello goodbye` will return all archived tweets that contain the words 'hello' and 'goodbye', though not necessarily contiguously. The terms are highlighted with `<strong>` tags in the returned markup.

## Credits and License

* Code by [Cameron Harwick](https://cameronharwick.com) (Twitter [@C_Harwick](https://twitter.com/C_Harwick)). Do what you want with the code.
* Relies on Abraham Williams' [TwitterOauth](https://twitteroauth.com/) library. MIT licensed.


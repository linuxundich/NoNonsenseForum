<?php //display a particular thread’s contents
/* ====================================================================================================================== */
/* NoNonsense Forum v21 © Copyright (CC-BY) Kroc Camen 2012
   licenced under Creative Commons Attribution 3.0 <creativecommons.org/licenses/by/3.0/deed.en_GB>
   you may do whatever you want to this code as long as you give credit to Kroc Camen, <camendesign.com>
*/

//bootstrap the forum; you should read that file first
require_once './start.php';

//get the post message, the other fields (name / pass) are retrieved automatically in 'start.php'
define ('TEXT', safeGet (@$_POST['text'], SIZE_TEXT));

//which thread to show
//TODO: an error here should generate a 404, but we can't create a 404 in PHP that will send the server's provided 404 page.
//      I may revist this if I create an NNF-provided 404 page
$FILE   = (preg_match ('/^[_a-z0-9-]+$/', @$_GET['file']) ? $_GET['file'] : '') or die ('Malformed request');

//load the thread (have to read lock status from the file)
//TODO: if file is missing, give 404, as above
$xml    = @simplexml_load_file ("$FILE.rss") or require FORUM_LIB.'error_xml.php';
$thread = $xml->channel->xpath ('item');

//handle a rounding problem with working out the number of pages (PHP 5.3 has a fix for this)
$PAGES = count ($thread) % FORUM_POSTS == 1 ? floor (count ($thread) / FORUM_POSTS) : ceil (count ($thread) / FORUM_POSTS);
//validate the page number, when no page number is specified default to the last page
$PAGE  = !PAGE || PAGE > $PAGES ? $PAGES : PAGE;

//access rights for the current user
define ('CAN_REPLY', FORUM_ENABLED && (
	//- if you are a moderator (doesn’t matter if the forum or thread is locked)
	IS_MOD ||
	//- if you are a member, the forum lock doesn’t matter, but you can’t reply to locked threads (only mods can)
	(!(bool) $xml->channel->xpath ('category[.="locked"]') && IS_MEMBER) ||
	//- if you are neither a mod nor a member, then as long as: 1. the thread is not locked, and
	//  2. the forum is such that anybody can reply (unlocked or thread-locked), then you can reply
	(!(bool) $xml->channel->xpath ('category[.="locked"]') && (!FORUM_LOCK || FORUM_LOCK == 'threads'))
));


/* ======================================================================================================================
   thread lock / unlock action
   ====================================================================================================================== */
if ((isset ($_GET['lock']) || isset ($_GET['unlock'])) && IS_MOD && AUTH) {
	//get a read/write lock on the file so that between now and saving, no other posts could slip in
	//normally we could use a write-only lock 'c', but on Windows you can't read the file when write-locked!
	$f   = fopen ("$FILE.rss", 'r+'); flock ($f, LOCK_EX);
	//we have to read the XML using the file handle that's locked because in Windows, functions like
	//`get_file_contents`, or even `simplexml_load_file`, won't work due to the lock
	$xml = simplexml_load_string (fread ($f, filesize ("$FILE.rss"))) or require FORUM_LIB.'error_xml.php';
	
	//if there’s a "locked" category, remove it
	if ((bool) $xml->channel->xpath ('category[.="locked"]')) {
		//note: for simplicity this removes *all* channel categories as NNF only uses one at the moment,
		//      in the future the specific "locked" category needs to be removed
		unset ($xml->channel->category);
		//when unlocking, go to the thread
		$url = FORUM_URL.url ('thread', PATH_URL, $FILE).'#nnf_reply-form';
	} else {
		//if no "locked" category, add it
		$xml->channel->category[] = 'locked';
		//if locking, return to the index
		//(todo: could return to the particular page in the index the thread is on--complex!)
		$url = FORUM_URL.url ('index', PATH_URL);
	}
	
	//commit the data
	rewind ($f); ftruncate ($f, 0); fwrite ($f, $xml->asXML ());
	//close the lock / file
	flock ($f, LOCK_UN); fclose ($f);
	
	//try set the modified date of the file back to the time of the last reply
	//(un/locking a thread does not push the thread back to the top of the index)
	//note: this may fail if the file is not owned by the Apache process
	@touch ("$FILE.rss", strtotime ($xml->channel->item[0]->pubDate));
	
	//regenerate the folder's RSS file
	indexRSS ();
	
	header ("Location: $url", true, 303);
	exit;
}


/* ======================================================================================================================
   append link clicked
   ====================================================================================================================== */
if ($ID = (preg_match ('/^[A-Z0-9]+$/i', @$_GET['append']) ? $_GET['append'] : false)) {
	//get a write lock on the file so that between now and saving, no other posts could slip in
	$f   = fopen ("$FILE.rss", 'r+'); flock ($f, LOCK_EX);
	$xml = simplexml_load_string (fread ($f, filesize ("$FILE.rss"))) or require FORUM_LIB.'error_xml.php';
	
	//find the post using the ID (we need to know the numerical index for later)
	for ($i=0; $i<count ($xml->channel->item); $i++) if (strstr ($xml->channel->item[$i]->link, '#') == "#$ID") break;
	$post = $xml->channel->item[$i];
	
	/* has the un/pw been submitted to authenticate the append?
	   -------------------------------------------------------------------------------------------------------------- */
	if (AUTH && TEXT && CAN_REPLY && (
		//a moderator can always append
		IS_MOD ||
		//the owner of a post can append
		(strtolower (NAME) == strtolower ($post->author) && (
			//if the forum is post-locked, they must be a member to append to their own posts
			(!FORUM_LOCK || FORUM_LOCK == 'threads') || IS_MEMBER
		))
	)) {
		//append the given text to the reply
		//(see 'theme.config.php' if it exists, otherwise 'theme.config.default.php' for `THEME_APPEND`)
		$post->description .= "\n".sprintf (THEME_APPEND,
			safeHTML (NAME),		//author
			gmdate ('r', time ()),		//machine-readable time
			date (DATE_FORMAT, time ())	//human-readable time
		).formatText (TEXT, $xml);
		
		//commit the data
		rewind ($f); ftruncate ($f, 0); fwrite ($f, $xml->asXML ());
		//close the lock / file
		flock ($f, LOCK_UN); fclose ($f);
		
		//try set the modified date of the file back to the time of the last reply
		//(appending to a post does not push the thread back to the top of the index)
		//note: this may fail if the file is not owned by the Apache process
		@touch ("$FILE.rss", strtotime ($xml->channel->item[0]->pubDate));
		
		//regenerate the folder's RSS file
		indexRSS ();
		
		//return to the appended post
		header ('Location: '.FORUM_URL.url ('thread', PATH_URL, $FILE, $PAGE)."#$ID", true, 303);
		exit;
	}
	
	//close the lock / file
	flock ($f, LOCK_UN); fclose ($f);
	
	/* template the append page
	   -------------------------------------------------------------------------------------------------------------- */
	$template = prepareTemplate (
		THEME_ROOT.'append.html', sprintf (THEME_TITLE_APPEND, $post->title),
		//provide the current page parameters to construct the signin link
		'append', $FILE, PATH_URL, $PAGE, $ID, true
	
	//the preview post:
	)->set (array (
		'#nnf_post-title'		=> $xml->channel->title,
		'#nnf_post-title@id'		=> substr (strstr ($post->link, '#'), 1),
		'time#nnf_post-time'		=> date (DATE_FORMAT, strtotime ($post->pubDate)),
		'time#nnf_post-time@datetime'	=> gmdate ('r', strtotime ($post->pubDate)),
		'#nnf_post-author'		=> $post->author
	))->setValue (
		'#nnf_post-text', $post->description, true
	)->remove (array (
		//if the user who made the post is a mod, also mark the whole post as by a mod
		//(you might want to style any posts made by a mod differently)
		'#nnf_post@class, #nnf_post-author@class' => !isMod ($post->author) ? 'mod' : false
	
	//the append form:
	))->set (array (
		//set the field values from what was typed in before
		'input#nnf_name-field-http@value'	=> NAME, //set the maximum field sizes
		'input#nnf_name-field@value'		=> NAME, 'input#nnf_name-field@maxlength'	=> SIZE_NAME,
		'input#nnf_pass-field@value'		=> PASS, 'input#nnf_pass-field@maxlength'	=> SIZE_PASS,
		'textarea#nnf_text-field'		=> TEXT, 'textarea#nnf_text-field@maxlength'	=> SIZE_TEXT
		
	//is the user already signed-in?
	))->remove (AUTH_HTTP
		//don’t need the usual name / password fields and the deafult message for anonymous users
		? '#nnf_name, #nnf_pass, #nnf_email, #nnf_error-none-append'
		//user is not signed in, remove the "you are signed in as:" field and the message for signed in users
		: '#nnf_name-http, #nnf_error-none-http'
		
	//handle error messages
	)->remove (array (
		//if there's an error of any sort, remove the default messages
		'#nnf_error-none-append, #nnf_error-none-http' => !empty ($_POST),
		//if the username & password are correct, remove the error message
		'#nnf_error-auth-append' => empty ($_POST) || !TEXT || !NAME || !PASS || AUTH,
		//if the password is valid, remove the error message
		'#nnf_error-pass-append' => empty ($_POST) || !TEXT || !NAME || PASS,
		//if the name is valid, remove the error message
		'#nnf_error-name-append' => empty ($_POST) || !TEXT || NAME,
		//if the message text is valid, remove the error message
		'#nnf_error-text'        => empty ($_POST) || TEXT
	));
	
	//call the theme-specific templating function, in 'theme.php', before outputting
	theme_custom ($template);
	exit ($template->html ());
}


/* ======================================================================================================================
   delete link clicked
   ====================================================================================================================== */
if (isset ($_GET['delete'])) {
	//the ID of the post to delete. will be omitted if deleting the whole thread
	$ID = (preg_match ('/^[A-Z0-9]+$/i', @$_GET['delete']) ? $_GET['delete'] : false);
	//get a write lock on the file so that between now and saving, no other posts could slip in
	$f = fopen ("$FILE.rss", 'r+'); flock ($f, LOCK_EX);
	
	//load the thread to get the post preview
	$xml = simplexml_load_string (fread ($f, filesize ("$FILE.rss"))) or require FORUM_LIB.'error_xml.php';
	
	//access the particular post. if no ID is provided (deleting the whole thread) use the last item in the RSS file
	//(the first post), otherwise find the ID of the specific post
	if (!$ID) {
		$post = $xml->channel->item[count ($xml->channel->item) - 1];
	} else {
		//find the post using the ID (we need to know the numerical index for later)
		for ($i=0; $i<count ($xml->channel->item); $i++) if (
			strstr ($xml->channel->item[$i]->link, '#') == "#$ID"
		) break;
		$post = $xml->channel->item[$i];
	}
	
	/* has the un/pw been submitted to authenticate the delete?
	   -------------------------------------------------------------------------------------------------------------- */
	if (AUTH && CAN_REPLY && (
		//a moderator can always delete
		IS_MOD ||
		//the owner of a post can delete
		(strtolower (NAME) == strtolower ($post->author) && (
			//if the forum is post-locked, they must be a member to delete their own posts
			(!FORUM_LOCK || FORUM_LOCK == 'threads') || IS_MEMBER
		))
	//deleting a post?
	)) if ($ID) {
		if (	//full delete? (option ticked, is moderator, and post is on the last page)
			(IS_MOD && $i <= (count ($xml->channel->item)-2) % FORUM_POSTS) &&
			//if the post has already been blanked, delete it fully
			(isset ($_POST['remove']) || $post->xpath ('category[.="deleted"]'))
		) {
			//remove the post from the thread entirely
			unset ($xml->channel->item[$i]);
			
			//we’ll redirect to the last page (which may have changed when the post was deleted)
			$url = FORUM_URL.url ('thread', PATH_URL, $FILE).'#nnf_replies';
		} else {
			//remove the post text and replace with the deleted messgae
			$post->description = (NAME == (string) $post->author) ? THEME_DEL_USER : THEME_DEL_MOD;
			//add a "deleted" category so we know to no longer allow it to be edited or deleted again
			if (!$post->xpath ('category[.="deleted"]')) $post->category[] = 'deleted';
			
			//need to know what page this post is on to redirect back to it
			$url = FORUM_URL.url ('thread', PATH_URL, $FILE, $PAGE)."#$ID";
		}
		
		//commit the data
		rewind ($f); ftruncate ($f, 0); fwrite ($f, $xml->asXML ());
		//close the lock / file
		flock ($f, LOCK_UN); fclose ($f);
		
		//try set the modified date of the file back to the time of the last reply
		//(so that deleting does not push the thread back to the top of the index)
		//note: this may fail if the file is not owned by the Apache process
		@touch ("$FILE.rss", strtotime ($xml->channel->item[0]->pubDate));
		
		//regenerate the folder's RSS file
		indexRSS ();
		
		//return to the deleted post / last page
		header ("Location: $url", true, 303);
		exit;
	} else {
		//close the lock / file
		flock ($f, LOCK_UN); fclose ($f);
		
		//delete the thread for reals
		@unlink (FORUM_ROOT.PATH_DIR."$FILE.rss");
		
		//regenerate the folder's RSS file
		indexRSS ();
		
		//return to the index
		header ('Location: '.FORUM_URL.url ('index', PATH_URL), true, 303);
		exit;
	}
	
	//close the lock / file
	flock ($f, LOCK_UN); fclose ($f);
	
	/* template the delete page
	   -------------------------------------------------------------------------------------------------------------- */
	$template = prepareTemplate (
		THEME_ROOT.'delete.html', sprintf (THEME_TITLE_DELETE, $post->title),
		//provide the current page parameters to construct the signin link
		'delete', $FILE, PATH_URL, $PAGE, $ID, true
	
	//the preview post:
	)->set (array (
		'#nnf_post-title'		=> $post->title,
		'#nnf_post-title@id'		=> substr (strstr ($post->link, '#'), 1),
		'time#nnf_post-time'		=> date (DATE_FORMAT, strtotime ($post->pubDate)),
		'time#nnf_post-time@datetime'	=> gmdate ('r', strtotime ($post->pubDate)),
		'#nnf_post-author'		=> $post->author
	))->setValue (
		'#nnf_post-text', $post->description, true
	)->remove (array (
		//if the user who made the post is a mod, also mark the whole post as by a mod
		//(you might want to style any posts made by a mod differently)
		'#nnf_post@class, #nnf_post-author@class' => !isMod ($post->author) ? 'mod' : false
	
	//the authentication form:
	))->set (array (
		//set the field values from input	 //set the maximum field sizes
		'input#nnf_name-field@value'	=> NAME, 'input#nnf_name-field@maxlength'	=> SIZE_NAME,
		'input#nnf_pass-field@value'	=> PASS, 'input#nnf_pass-field@maxlength'	=> SIZE_PASS
		
	//are we deleting the whole thread, or just one reply?
	))->remove ($ID
		? '#nnf_error-none-thread'
		: '#nnf_error-none-reply, #nnf_remove'	//if deleting the whole thread, also remove the checkbox option
		
	//handle error messages
	)->remove (array (
		//if there's an error of any sort, remove the default messages
		'#nnf_error-none-thread, #nnf_error-none-reply' => !empty ($_POST),
		//if the username & password are correct, remove the error message
		'#nnf_error-auth-delete' => empty ($_POST) || !NAME || !PASS || AUTH,
		//if the password is valid, remove the error message
		'#nnf_error-pass-delete' => empty ($_POST) || !NAME || PASS,
		//if the name is valid, remove the error message
		'#nnf_error-name-delete' => empty ($_POST) || NAME
	));
	
	//call the theme-specific templating function, in 'theme.php', before outputting
	theme_custom ($template);
	exit ($template->html ());
}


/* ======================================================================================================================
   new reply submitted
   ====================================================================================================================== */
//was the submit button clicked? (and is the info valid?)
if (CAN_REPLY && AUTH && TEXT) {
	//get a read/write lock on the file so that between now and saving, no other posts could slip in
	//normally we could use a write-only lock 'c', but on Windows you can't read the file when write-locked!
	$f = fopen ("$FILE.rss", 'r+'); flock ($f, LOCK_EX);
	//we have to read the XML using the file handle that's locked because in Windows, functions like
	//`get_file_contents`, or even `simplexml_load_file`, won't work due to the lock
	$xml = simplexml_load_string (fread ($f, filesize ("$FILE.rss"))) or require FORUM_LIB.'error_xml.php';
	
	if (!(
		//ignore a double-post (could be an accident with the back button)
		NAME == $xml->channel->item[0]->author && formatText (TEXT, $xml) == $xml->channel->item[0]->description &&
		//can’t post if the thread is locked
		!$xml->channel->xpath ('category[.="locked"]')
	)) {
		//where to?
		$page = (count ($thread)+1) % FORUM_POSTS == 1
			? floor ((count ($thread)+1) / FORUM_POSTS)
			: ceil  ((count ($thread)+1) / FORUM_POSTS)
		;
		$url  = FORUM_URL.url ('thread', PATH_URL, $FILE, $page).'#'.base_convert (microtime (), 10, 36);
		
		//re-template the whole thread:
		$rss = new DOMTemplate (file_get_contents (FORUM_LIB.'rss-template.xml'));
		$rss->set (array (
			'/rss/channel/title'		=> $xml->channel->title,
			'/rss/channel/link'		=> FORUM_URL.url ('thread', PATH_URL, $FILE)
		))->remove (array (
			//is the thread unlocked?
			'/rss/channel/category'		=> !$xml->channel->xpath ('category[.="locked"]')
		));
		
		//template the new reply first
		$items = $rss->repeat ('/rss/channel/item');
		$items->set (array (
			//add the "RE:" prefix, and reply number to the title
			//(see 'theme.config.php' if it exists, otherwise 'theme.config.deafult.php',
			// in the theme's folder for the definition of `THEME_RE`)
			'./title'		=> sprintf (THEME_RE,
				count ($xml->channel->item),	//number of the reply
				$xml->channel->title		//thread title
			),
			'./link'		=> $url,
			'./author'		=> NAME,
			'./pubDate'		=> gmdate ('r'),
			'./description'		=> formatText (TEXT, $xml)
		))->remove (
			//the new reply isn’t deleted, so remove the category marker
			'./category'
		)->next ();
		
		//copy the remaining replies across
		foreach ($xml->channel->item as $item) $items->set (array (
			'./title'		=> $item->title,
			'./link'		=> $item->link,
			'./author'		=> $item->author,
			'./pubDate'		=> $item->pubDate,
			'./description'		=> $item->description
		))->remove (array (
			//has the reply been deleted? (blanked)
			'./category'		=> !$item->xpath ('./category')
		))->next ();
		
		//write the file: first move the write-head to 0, remove the file's contents, and then write new one
		rewind ($f); ftruncate ($f, 0); fwrite ($f, $rss->html ());
	} else {
		//if a double-post, link back to the previous post
		$url = $xml->channel->item[0]->link;
	}
	
	//close the lock / file
	flock ($f, LOCK_UN); fclose ($f);
	
	//regenerate the forum / sub-forums's RSS file
	indexRSS ();
	
	//refresh page to see the new post added
	header ("Location: $url", true, 303);
	exit;
}


/* ======================================================================================================================
   template thread
   ====================================================================================================================== */
//load the template into DOM where we can manipulate it:
//(see 'lib/domtemplate.php' or <camendesign.com/dom_templating> for more details)
$template = prepareTemplate (
	THEME_ROOT.'thread.html',
	//HTML title: (this is defined in 'theme.config.php' if it exists, else 'theme.config.default.php')
	sprintf (THEME_TITLE,
		//title of the thread, obviously
		$xml->channel->title,
		//if on page 2 or greater, include the page number in the title
		$PAGE>1 ? sprintf (THEME_TITLE_PAGENO, $PAGE) : ''
	),
	//provide the current page parameters to construct the signin link
	'thread', $FILE, PATH_URL, $PAGE, '', true
	
)->set (array (
	//the thread itself is the RSS feed :)
	'a#nnf_rss@href'	=> FORUM_PATH.PATH_URL."$FILE.rss",
	//set the hyperlinks for lock / unlock actions (append current URL with 'lock' / 'unlock' querystrings)
	'a#nnf_lock@href'	=> url ('lock',   PATH_URL, $FILE, $PAGE),
	'a#nnf_unlock@href'	=> url ('unlock', PATH_URL, $FILE, $PAGE)
))->remove (array (
	//if replies can't be added (forum or thread is locked, user is not moderator / member),
	//remove the "add reply" link and anything else (like the input form) related to posting
	'#nnf_reply, #nnf_reply-form'	=> !CAN_REPLY,
	//if the forum is not post-locked (only mods can post / reply) then remove the warning message
	'.nnf_forum-locked'		=> FORUM_LOCK != 'posts',
	//is the user a mod and can lock / unlock the thread?
	'#nnf_admin'			=> !IS_MOD,
	//is the thread already locked?
	'#nnf_lock'			=>  $xml->channel->xpath ('category[.="locked"]'),
	'#nnf_unlock'			=> !$xml->channel->xpath ('category[.="locked"]')
));

/* post
   ---------------------------------------------------------------------------------------------------------------------- */
//take the first post from the thread (removing it from the rest)
$post = array_pop ($thread);
//remember the original poster’s name, for marking replies by the OP
$author = (string) $post->author;

//prepare the first post, which on this forum appears above all pages of replies
$template->set (array (
	'#nnf_post-title'		=> $xml->channel->title,
	'time#nnf_post-time'		=> date (DATE_FORMAT, strtotime ($post->pubDate)),
	'time#nnf_post-time@datetime'	=> gmdate ('r', strtotime ($post->pubDate)),
	'#nnf_post-author'		=> $post->author,
	'a#nnf_post-append@href'	=> url ('append', PATH_URL, $FILE, $PAGE,
					        substr (strstr ($post->link, '#'), 1)).'#append',
	'a#nnf_post-delete@href'	=> url ('delete', PATH_URL, $FILE, $PAGE)
))->setValue (
	'#nnf_post-text', $post->description, true
)->remove (array (
	//if the user who made the post is a mod, also mark the whole post as by a mod
	//(you might want to style any posts made by a mod differently)
	'#nnf_post@class, #nnf_post-author@class' => !isMod ($post->author) ? 'mod' : false,
	
	//append / delete links?
	'#nnf_post-append, #nnf_post-delete' => !CAN_REPLY
));

/* replies
   ---------------------------------------------------------------------------------------------------------------------- */
if (!count ($thread)) {
	$template->remove ('#nnf_replies');
} else {
	//sort the other way around
	//<stackoverflow.com/questions/2119686/sorting-an-array-of-simplexml-objects/2120569#2120569>
	foreach ($thread as &$node) $sort[] = strtotime ($node->pubDate);
	array_multisort ($sort, SORT_ASC, $thread);
	
	//do the page links
	theme_pageList ($template, $FILE, $PAGE, $PAGES);
	//slice the full list into the current page
	$thread = array_slice ($thread, ($PAGE-1) * FORUM_POSTS, FORUM_POSTS);
	
	//get the dummy list-item to repeat (removes it and takes a copy)
	$item = $template->repeat ('.nnf_reply');
	
	//index number of the replies, accounting for which page we are on
	$no = ($PAGE-1) * FORUM_POSTS;
	//apply the data to the template (a reply)
	foreach ($thread as &$reply) $item->set (array (
		'./@id'				=> substr (strstr ($reply->link, '#'), 1),
		'time.nnf_reply-time'		=> date (DATE_FORMAT, strtotime ($reply->pubDate)),
		'time.nnf_reply-time@datetime'	=> gmdate ('r', strtotime ($reply->pubDate)),
		'.nnf_reply-author'		=> $reply->author,
		'a.nnf_reply-number'		=> sprintf (THEME_REPLYNO, ++$no),
		'a.nnf_reply-number@href'	=> url ('thread', PATH_URL, $FILE, $PAGE).strstr ($reply->link,'#'),
		'a.nnf_reply-append@href'	=> url ('append', PATH_URL, $FILE, $PAGE,
						        substr (strstr ($reply->link, '#'), 1)).'#append',
		'a.nnf_reply-delete@href'	=> url ('delete', PATH_URL, $FILE, $PAGE,
						        substr (strstr ($reply->link, '#'), 1))
	))->setValue (
		'.nnf_reply-text', $reply->description, true
	)->remove (array (
		//has the reply been deleted (blanked)?
		'./@class'			=> $reply->xpath ('category[.="deleted"]') ? false : 'deleted',
	))->remove (array (
		//is this reply from the person who started the thread?
		'./@class'			=> strtolower ($reply->author) == strtolower ($author) ? false :'op'
	))->remove (array (
		//if the user who made the reply is a mod, also mark the whole post as by a mod
		//(you might want to style any posts made by a mod differently)
		'./@class, .nnf_reply-author@class' => isMod ($reply->author) ? false : 'mod'
	))->remove (array (
		//if the current user in the curent forum can append/delete the current reply:
		'.nnf_reply-append, .nnf_reply-delete' => !(CAN_REPLY && (
			//moderators can always see append/delete links on all replies
			IS_MOD ||
			//if you are not signed in, all append/delete links are shown (if forum/thread locking is off)
			//if you are signed in, then only links on replies with your name will show
			!AUTH_HTTP ||
			//if this reply is the by the owner (they can append/delete to their own replies)
			(strtolower (NAME) == strtolower ($reply->author) && (
				//if the forum is post-locked, they must be a member to append/delete their own replies
				(!FORUM_LOCK || FORUM_LOCK == 'threads') || IS_MEMBER
			))
		)),
		//append link not available when the reply has been deleted
		'.nnf_reply-append' => $reply->xpath ('category[.="deleted"]'),
		//delete link not available when the reply has been deleted, except to mods
		'.nnf_reply-delete' => $reply->xpath ('category[.="deleted"]') && !IS_MOD
	))->next ();
}

/* reply form
   ---------------------------------------------------------------------------------------------------------------------- */
if (CAN_REPLY) $template->set (array (
	//set the field values from what was typed in before
	'input#nnf_name-field-http@value'	=> NAME, //set the maximum field sizes
	'input#nnf_name-field@value'		=> NAME, 'input#nnf_name-field@maxlength'	=> SIZE_NAME,
	'input#nnf_pass-field@value'		=> PASS, 'input#nnf_pass-field@maxlength'	=> SIZE_PASS,
	'textarea#nnf_text-field'		=> TEXT, 'textarea#nnf_text-field@maxlength'	=> SIZE_TEXT
	
//is the user already signed-in?
))->remove (AUTH_HTTP
	//don’t need the usual name / password fields and the deafult message for anonymous users
	? '#nnf_name, #nnf_pass, #nnf_email, #nnf_error-none'
	//user is not signed in, remove the "you are signed in as:" field and the message for signed in users
	: '#nnf_name-http, #nnf_error-none-http'
	
//are new registrations allowed?
)->remove (FORUM_NEWBIES
	? '#nnf_error-newbies'	//yes: remove the warning message
	: '#nnf_error-none'	//no:  remove the default message
	
//handle error messages
)->remove (array (
	//if there's an error of any sort, remove the default messages
	'#nnf_error-none, #nnf_error-none-http, #nnf_error-newbies' => !empty ($_POST),
	//if the username & password are correct, remove the error message
	'#nnf_error-auth'  => empty ($_POST) || !TEXT || !NAME || !PASS || AUTH,
	//if the password is valid, remove the error message
	'#nnf_error-pass'  => empty ($_POST) || !TEXT || !NAME || PASS,
	//if the name is valid, remove the error message
	'#nnf_error-name'  => empty ($_POST) || !TEXT || NAME,
	//if the message text is valid, remove the error message
	'#nnf_error-text'  => empty ($_POST) || TEXT
));

//call the theme-specific templating function, in 'theme.php', before outputting
theme_custom ($template);
exit ($template->html ());

?>
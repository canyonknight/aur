<?php

/**
 * Determine if an HTTP request variable is set
 *
 * @param string $name The request variable to test for
 *
 * @return string Return the value of the request variable, otherwise blank
 */
function in_request($name) {
	if (isset($_REQUEST[$name])) {
		return $_REQUEST[$name];
	}
	return "";
}

/**
 * Format the PGP key fingerprint
 *
 * @param string $fingerprint An unformatted PGP key fingerprint
 *
 * @return string PGP fingerprint with spaces every 4 characters
 */
function html_format_pgp_fingerprint($fingerprint) {
	if (strlen($fingerprint) != 40 || !ctype_xdigit($fingerprint)) {
		return $fingerprint;
	}

	return htmlspecialchars(substr($fingerprint, 0, 4) . " " .
		substr($fingerprint, 4, 4) . " " .
		substr($fingerprint, 8, 4) . " " .
		substr($fingerprint, 12, 4) . " " .
		substr($fingerprint, 16, 4) . "  " .
		substr($fingerprint, 20, 4) . " " .
		substr($fingerprint, 24, 4) . " " .
		substr($fingerprint, 28, 4) . " " .
		substr($fingerprint, 32, 4) . " " .
		substr($fingerprint, 36, 4) . " ", ENT_QUOTES);
}

/**
 * Loads the account editing form, with any values that are already saved
 *
 * @global array $SUPPORTED_LANGS Languages that are supported by the AUR
 * @param string $UTYPE User type of the account accessing the form
 * @param string $A Form to use, either UpdateAccount or NewAccount
 * @param string $U The username to display
 * @param string $T The account type of the displayed user
 * @param string $S Whether the displayed user has a suspended account
 * @param string $E The e-mail address of the displayed user
 * @param string $P The password value of the displayed user
 * @param string $C The confirmed password value of the displayed user
 * @param string $R The real name of the displayed user
 * @param string $L The language preference of the displayed user
 * @param string $I The IRC nickname of the displayed user
 * @param string $K The PGP key fingerprint of the displayed user
 * @param string $J The inactivity status of the displayed user
 * @param string $UID The user ID of the displayed user
 *
 * @return void
 */
function display_account_form($UTYPE,$A,$U="",$T="",$S="",
		$E="",$P="",$C="",$R="",$L="",$I="",$K="",$J="", $UID=0) {
	global $SUPPORTED_LANGS;

	include("account_edit_form.php");
	return;
} # function display_account_form()

/**
 * Process information given to new/edit account form
 *
 * @global array $SUPPORTED_LANGS Languages that are supported by the AUR
 * @param string $UTYPE The account type of the user modifying the account
 * @param string $TYPE Either "edit" for editing or "new" for registering an account
 * @param string $A Form to use, either UpdateAccount or NewAccount
 * @param string $U The username for the account
 * @param string $T The account type for the user
 * @param string $S Whether or not the account is suspended
 * @param string $E The e-mail address for the user
 * @param string $P The password for the user
 * @param string $C The confirmed password for the user
 * @param string $R The real name of the user
 * @param string $L The language preference of the user
 * @param string $I The IRC nickname of the user
 * @param string $K The PGP fingerprint of the user
 * @param string $J The inactivity status of the user
 * @param string $UID The user ID of the modified account
 *
 * @return string|void Return void if successful, otherwise return error
 */
function process_account_form($UTYPE,$TYPE,$A,$U="",$T="",$S="",$E="",
			$P="",$C="",$R="",$L="",$I="",$K="",$J="",$UID=0) {

	# error check and process request for a new/modified account
	global $SUPPORTED_LANGS, $AUR_LOCATION;

	$error = '';

	if (is_ipbanned()) {
		$error = __('Account registration has been disabled ' .
					'for your IP address, probably due ' .
					'to sustained spam attacks. Sorry for the ' .
					'inconvenience.');
	}

	$dbh = DB::connect();

	if(isset($_COOKIE['AURSID'])) {
		$editor_user = uid_from_sid($_COOKIE['AURSID']);
	}
	else {
		$editor_user = null;
	}

	if (empty($E) || empty($U)) {
		$error = __("Missing a required field.");
	}

	if ($TYPE != "new" && !$UID) {
		$error = __("Missing User ID");
	}

  if (!$error && !valid_username($U) && !user_is_privileged($editor_user))
	$error = __("The username is invalid.") . "<ul>\n"
			."<li>" . __("It must be between %s and %s characters long",
			USERNAME_MIN_LEN,  USERNAME_MAX_LEN )
			. "</li>"
			. "<li>" . __("Start and end with a letter or number") . "</li>"
			. "<li>" . __("Can contain only one period, underscore or hyphen.")
			. "</li>\n</ul>";

	if (!$error && $P && $C && ($P != $C)) {
		$error = __("Password fields do not match.");
	}
	if (!$error && $P != '' && !good_passwd($P))
		$error = __("Your password must be at least %s characters.",PASSWD_MIN_LEN);

	if (!$error && !valid_email($E)) {
		$error = __("The email address is invalid.");
	}

	if (!$error && $K != '' && !valid_pgp_fingerprint($K)) {
		$error = __("The PGP key fingerprint is invalid.");
	}

	if (($UTYPE == "User" && $T > 1) || ($UTYPE == "Trusted User" && $T > 2)) {
		$error = __("Cannot increase account permissions.");
	}
	if (!$error && !array_key_exists($L, $SUPPORTED_LANGS)) {
		$error = __("Language is not currently supported.");
	}
	if (!$error) {
		# check to see if this username is available
		# NOTE: a race condition exists here if we care...
		#
		$q = "SELECT COUNT(*) AS CNT FROM Users ";
		$q.= "WHERE Username = " . $dbh->quote($U);
		if ($TYPE == "edit") {
			$q.= " AND ID != ".intval($UID);
		}
		$result = $dbh->query($q);
		$row = $result->fetch(PDO::FETCH_NUM);

		if ($row[0]) {
			$error = __("The username, %s%s%s, is already in use.",
				"<strong>", htmlspecialchars($U,ENT_QUOTES), "</strong>");
		}
	}
	if (!$error) {
		# check to see if this email address is available
		# NOTE: a race condition exists here if we care...
		#
		$q = "SELECT COUNT(*) AS CNT FROM Users ";
		$q.= "WHERE Email = " . $dbh->quote($E);
		if ($TYPE == "edit") {
			$q.= " AND ID != ".intval($UID);
		}
		$result = $dbh->query($q);
		$row = $result->fetch(PDO::FETCH_NUM);

		if ($row[0]) {
			$error = __("The address, %s%s%s, is already in use.",
					"<strong>", htmlspecialchars($E,ENT_QUOTES), "</strong>");
		}
	}
	if ($error) {
		print "<ul class='errorlist'><li>".$error."</li></ul>\n";
		display_account_form($UTYPE, $A, $U, $T, $S, $E, "", "",
				$R, $L, $I, $K, $J, $UID);
	} else {
		if ($TYPE == "new") {
			# no errors, go ahead and create the unprivileged user
			$salt = generate_salt();
			if (empty($P)) {
				$send_resetkey = true;
				$email = $E;
			} else {
				$send_resetkey = false;
				$P = salted_hash($P, $salt);
			}
			$U = $dbh->quote($U);
			$E = $dbh->quote($E);
			$P = $dbh->quote($P);
			$salt = $dbh->quote($salt);
			$R = $dbh->quote($R);
			$L = $dbh->quote($L);
			$I = $dbh->quote($I);
			$K = $dbh->quote(str_replace(" ", "", $K));
			$q = "INSERT INTO Users (AccountTypeID, Suspended, ";
			$q.= "InactivityTS, Username, Email, Passwd, Salt, ";
			$q.= "RealName, LangPreference, IRCNick, PGPKey) ";
			$q.= "VALUES (1, 0, 0, $U, $E, $P, $salt, $R, $L, ";
			$q.= "$I, $K)";
			$result = $dbh->exec($q);
			if (!$result) {
				print __("Error trying to create account, %s%s%s.",
						"<strong>", htmlspecialchars($U,ENT_QUOTES), "</strong>");
			} else {
				# account created/modified, tell them so.
				#
				print __("The account, %s%s%s, has been successfully created.",
						"<strong>", htmlspecialchars($U,ENT_QUOTES), "</strong>");
				print "<p>\n";
				if ($send_resetkey) {
					$body = __('Welcome to %s! In order ' .
						'to set an initial password ' .
						'for your new account, ' .
						'please click the link ' .
						'below. If the link does ' .
						'not work try copying and ' .
						'pasting it into your ' .
						'browser.',
						$AUR_LOCATION);
					send_resetkey($email, $body);
					print __("A password reset key has been sent to your e-mail address.");
				} else {
					print __("Click on the Login link above to use your account.");
				}
				print "</p>\n";
			}

		} else {
			# no errors, go ahead and modify the user account

			$q = "SELECT InactivityTS FROM Users WHERE ";
			$q.= "ID = " . intval($UID);
			$result = $dbh->query($q);
			$row = $result->fetch(PDO::FETCH_NUM);
			if ($row[0] && $J) {
				$inactivity_ts = $row[0];
			} elseif ($J) {
				$inactivity_ts = time();
			} else {
				$inactivity_ts = 0;
			}

			$q = "UPDATE Users SET ";
			$q.= "Username = " . $dbh->quote($U);
			if ($T) {
				$q.= ", AccountTypeID = ".intval($T);
			}
			if ($S) {
				/* Ensure suspended users can't keep an active session */
				delete_user_sessions($UID);
				$q.= ", Suspended = 1";
			} else {
				$q.= ", Suspended = 0";
			}
			$q.= ", Email = " . $dbh->quote($E);
			if ($P) {
				$salt = generate_salt();
				$hash = salted_hash($P, $salt);
				$q .= ", Passwd = '$hash', Salt = '$salt'";
			}
			$q.= ", RealName = " . $dbh->quote($R);
			$q.= ", LangPreference = " . $dbh->quote($L);
			$q.= ", IRCNick = " . $dbh->quote($I);
			$q.= ", PGPKey = " . $dbh->quote(str_replace(" ", "", $K));
			$q.= ", InactivityTS = " . $inactivity_ts;
			$q.= " WHERE ID = ".intval($UID);
			$result = $dbh->exec($q);
			if (!$result) {
				print __("No changes were made to the account, %s%s%s.",
						"<strong>", htmlspecialchars($U,ENT_QUOTES), "</strong>");
			} else {
				print __("The account, %s%s%s, has been successfully modified.",
						"<strong>", htmlspecialchars($U,ENT_QUOTES), "</strong>");
			}
		}
	}
	return;
}

/**
 * Display the search results page
 *
 * @param string $UTYPE User type of the account accessing the form
 * @param string $O The offset for the results page
 * @param string $SB The column to sort the results page by
 * @param string $U The username search criteria
 * @param string $T The account type search criteria
 * @param string $S Whether the account is suspended search criteria
 * @param string $E The e-mail address search criteria
 * @param string $R The real name search criteria
 * @param string $I The IRC nickname search criteria
 * @param string $K The PGP key fingerprint search criteria
 *
 * @return void
 */
function search_results_page($UTYPE,$O=0,$SB="",$U="",$T="",
		$S="",$E="",$R="",$I="",$K="") {

	$HITS_PER_PAGE = 50;
	if ($O) {
		$OFFSET = intval($O);
	} else {
		$OFFSET = 0;
	}
	if ($OFFSET < 0) {
		$OFFSET = 0;
	}
	$search_vars = array();

	$dbh = DB::connect();

	$q = "SELECT Users.*, AccountTypes.AccountType ";
	$q.= "FROM Users, AccountTypes ";
	$q.= "WHERE AccountTypes.ID = Users.AccountTypeID ";
	if ($T == "u") {
		$q.= "AND AccountTypes.ID = 1 ";
		$search_vars[] = "T";
	} elseif ($T == "t") {
		$q.= "AND AccountTypes.ID = 2 ";
		$search_vars[] = "T";
	} elseif ($T == "d") {
		$q.= "AND AccountTypes.ID = 3 ";
		$search_vars[] = "T";
	}
	if ($S) {
		$q.= "AND Users.Suspended = 1 ";
		$search_vars[] = "S";
	}
	if ($U) {
		$U = "%" . addcslashes($U, '%_') . "%";
		$q.= "AND Username LIKE " . $dbh->quote($U) . " ";
		$search_vars[] = "U";
	}
	if ($E) {
		$E = "%" . addcslashes($E, '%_') . "%";
		$q.= "AND Email LIKE " . $dbh->quote($E) . " ";
		$search_vars[] = "E";
	}
	if ($R) {
		$R = "%" . addcslashes($R, '%_') . "%";
		$q.= "AND RealName LIKE " . $dbh->quote($R) . " ";
		$search_vars[] = "R";
	}
	if ($I) {
		$I = "%" . addcslashes($I, '%_') . "%";
		$q.= "AND IRCNick LIKE " . $dbh->quote($I) . " ";
		$search_vars[] = "I";
	}
	if ($K) {
		$K = "%" . addcslashes(str_replace(" ", "", $K), '%_') . "%";
		$q.= "AND PGPKey LIKE " . $dbh->quote($K) . " ";
		$search_vars[] = "K";
	}
	switch ($SB) {
		case 't':
			$q.= "ORDER BY AccountTypeID, Username ";
			break;
		case 'r':
			$q.= "ORDER BY RealName, AccountTypeID ";
			break;
		case 'i':
			$q.= "ORDER BY IRCNick, AccountTypeID ";
			break;
		case 'v':
			$q.= "ORDER BY LastVoted, Username ";
			break;
		default:
			$q.= "ORDER BY Username, AccountTypeID ";
			break;
	}
	$search_vars[] = "SB";
	$q.= "LIMIT " . $HITS_PER_PAGE . " OFFSET " . $OFFSET;

	$dbh = DB::connect();

	$result = $dbh->query($q);

	while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
		$userinfo[] = $row;
	}

	include("account_search_results.php");
	return;
}

/**
 * Attempt to login and generate a session
 *
 * @global int $MAX_SESSIONS_PER_USER Maximum sessions a single user may have open
 * @global int $PERSISTENT_COOKIE_TIMEOUT Time until cookie expires
 *
 * @return array Session ID for user, error message if applicable
 */
function try_login() {
	global $MAX_SESSIONS_PER_USER, $PERSISTENT_COOKIE_TIMEOUT;

	$login_error = "";
	$new_sid = "";
	$userID = null;

	if ( isset($_REQUEST['user']) || isset($_REQUEST['passwd']) ) {
		if (is_ipbanned()) {
			$login_error = __('The login form is currently disabled ' .
							'for your IP address, probably due ' .
							'to sustained spam attacks. Sorry for the ' .
							'inconvenience.');
			return array('SID' => '', 'error' => $login_error);
		}
		$dbh = DB::connect();
		$userID = valid_user($_REQUEST['user']);

		if ( user_suspended($userID) ) {
			$login_error = "Account Suspended.";
		}
		elseif ( $userID && isset($_REQUEST['passwd'])
		  && valid_passwd($userID, $_REQUEST['passwd']) ) {

			$logged_in = 0;
			$num_tries = 0;

			# Account looks good.  Generate a SID and store it.

			while (!$logged_in && $num_tries < 5) {
				if ($MAX_SESSIONS_PER_USER) {
					# Delete all user sessions except the
					# last ($MAX_SESSIONS_PER_USER - 1).
					$q = "DELETE s.* FROM Sessions s ";
					$q.= "LEFT JOIN (SELECT SessionID FROM Sessions ";
					$q.= "WHERE UsersId = " . $userID . " ";
					$q.= "ORDER BY LastUpdateTS DESC ";
					$q.= "LIMIT " . ($MAX_SESSIONS_PER_USER - 1) . ") q ";
					$q.= "ON s.SessionID = q.SessionID ";
					$q.= "WHERE s.UsersId = " . $userID . " ";
					$q.= "AND q.SessionID IS NULL;";
					$dbh->query($q);
				}

				$new_sid = new_sid();
				$q = "INSERT INTO Sessions (UsersID, SessionID, LastUpdateTS)"
				  ." VALUES (" . $userID . ", '" . $new_sid . "', UNIX_TIMESTAMP())";
				$result = $dbh->exec($q);

				# Query will fail if $new_sid is not unique
				if ($result) {
					$logged_in = 1;
					break;
				}

				$num_tries++;
			}

			if ($logged_in) {
				$q = "UPDATE Users SET LastLogin = UNIX_TIMESTAMP(), ";
				$q.= "LastLoginIPAddress = " . $dbh->quote(ip2long($_SERVER['REMOTE_ADDR'])) . " ";
				$q.= "WHERE ID = '$userID'";
				$dbh->exec($q);

				# set our SID cookie
				if (isset($_POST['remember_me']) &&
					$_POST['remember_me'] == "on") {
					# Set cookies for 30 days.
					$cookie_time = time() + $PERSISTENT_COOKIE_TIMEOUT;

					# Set session for 30 days.
					$q = "UPDATE Sessions SET LastUpdateTS = $cookie_time ";
					$q.= "WHERE SessionID = '$new_sid'";
					$dbh->exec($q);
				}
				else
					$cookie_time = 0;

				setcookie("AURSID", $new_sid, $cookie_time, "/", null, !empty($_SERVER['HTTPS']), true);
				header("Location: " . get_uri('/'));
				$login_error = "";

			}
			else {
				$login_error = "Error trying to generate session id.";
			}
		} elseif (passwd_is_empty($userID)) {
			$login_error = __('Your password has been reset. ' .
				'If you just created a new account, please ' .
				'use the link from the confirmation email ' .
				'to set an initial password. Otherwise, ' .
				'please request a reset key on the %s' .
				'Password Reset%s page.', '<a href="' .
				htmlspecialchars(get_uri('/passreset')) . '">',
				'</a>');
		} else {
			$login_error = __("Bad username or password.");
		}
	}
	return array('SID' => $new_sid, 'error' => $login_error);
}

/**
 * Determine if the user is using a banned IP address
 *
 * @return bool True if IP address is banned, otherwise false
 */
function is_ipbanned() {
	$dbh = DB::connect();

	$q = "SELECT * FROM Bans WHERE IPAddress = " . $dbh->quote(ip2long($_SERVER['REMOTE_ADDR']));
	$result = $dbh->query($q);

	if ($result->fetchColumn()) {
		return true;
	} else {
		return false;
	}
}

/**
 * Validate a username against a collection of rules
 *
 * The username must be longer or equal to USERNAME_MIN_LEN. It must be shorter
 * or equal to USERNAME_MAX_LEN. It must start and end with either a letter or
 * a number. It can contain one period, hypen, or underscore. Returns boolean
 * of whether name is valid.
 *
 * @param string $user Username to validate
 *
 * @return bool True if username meets criteria, otherwise false
 */
function valid_username($user) {
	if (!empty($user)) {

		#Is username at not too short or too long?
		if ( strlen($user) >= USERNAME_MIN_LEN &&
		  strlen($user) <= USERNAME_MAX_LEN ) {

			$user = strtolower($user);
			# Does username:
			# start and end with a letter or number
			# contain only letters and numbers,
			#  and at most has one dash, period, or underscore
			if ( preg_match("/^[a-z0-9]+[.\-_]?[a-z0-9]+$/", $user) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Determine if a username exists in the database
 *
 * @param string $user Username to check in the database
 *
 * @return string|void Return user ID if in database, otherwise void
 */
function valid_user($user) {
	/*	if ( $user = valid_username($user) ) { */

	$dbh = DB::connect();

	if ( $user ) {
		$q = "SELECT ID FROM Users ";
		$q.= "WHERE Username = " . $dbh->quote($user);

		$result = $dbh->query($q);
		# Is the username in the database?
		if ($result) {
			$row = $result->fetch(PDO::FETCH_NUM);
			return $row[0];
		}
	}
	return;
}

/**
 * Determine if a user already has a proposal open about themselves
 *
 * @param string $user Username to checkout for open proposal
 *
 * @return bool True if there is an open proposal about the user, otherwise false
 */
function open_user_proposals($user) {
	$dbh = DB::connect();
	$q = "SELECT * FROM TU_VoteInfo WHERE User = " . $dbh->quote($user) . " ";
	$q.= "AND End > UNIX_TIMESTAMP()";
	$result = $dbh->query($q);
	if ($result->fetchColumn()) {
		return true;
	}
	else {
		return false;
	}
}

/**
 * Add a new Trusted User proposal to the database
 *
 * @param string $agenda The agenda of the vote
 * @param string $user The use the vote is about
 * @param int $votelength The length of time for the vote to last
 * @param string $submitteruid The user ID of the individual who submitted the proposal
 *
 * @return void
 */
function add_tu_proposal($agenda, $user, $votelength, $quorum, $submitteruid) {
	$dbh = DB::connect();

	$q = "SELECT COUNT(*) FROM Users WHERE AccountTypeID = 2";
	$result = $dbh->query($q);
	$row = $result->fetch(PDO::FETCH_NUM);
	$active_tus = $row[0];

	$q = "INSERT INTO TU_VoteInfo (Agenda, User, Submitted, End, Quorum, ";
	$q.= "SubmitterID, ActiveTUs) VALUES ";
	$q.= "(" . $dbh->quote($agenda) . ", " . $dbh->quote($user) . ", ";
	$q.= "UNIX_TIMESTAMP(), UNIX_TIMESTAMP() + " . $dbh->quote($votelength);
	$q.= ", " . $dbh->quote($quorum) . ", " . $submitteruid . ", ";
	$q.= $active_tus . ")";
	$result = $dbh->exec($q);
}

/**
 * Add a reset key to the database for a specified user
 *
 * @param string $resetkey A password reset key to be stored in database
 * @param string $uid The user ID to store the reset key for
 *
 * @return void
 */
function create_resetkey($resetkey, $uid) {
	$dbh = DB::connect();
	$q = "UPDATE Users ";
	$q.= "SET ResetKey = '" . $resetkey . "' ";
	$q.= "WHERE ID = " . $uid;
	$dbh->exec($q);
}

/**
 * Send a reset key to a specific e-mail address
 *
 * @param string $email E-mail address of the user resetting their password
 * @param string $body Body of the email
 *
 * @return void
 */
function send_resetkey($email, $body) {
	global $AUR_LOCATION;

	$uid = uid_from_email($email);
	if ($uid != NULL && $uid != 'None') {
		# We (ab)use new_sid() to get a random 32 characters long string
		$resetkey = new_sid();
		create_resetkey($resetkey, $uid);
		# Send email with confirmation link
		$body = wordwrap($body, 70);
		$body .=  "\n\n".
			  "{$AUR_LOCATION}/" . get_uri('/passreset/') . "?".
		          "resetkey={$resetkey}";
		$headers = "Reply-to: nobody@archlinux.org\nFrom:aur-notify@archlinux.org\nX-Mailer: PHP\nX-MimeOLE: Produced By AUR";
		@mail($email, 'AUR Password Reset', $body, $headers);
	}
}

/**
 * Change a user's password in the database if reset key and e-mail are correct
 *
 * @param string $hash New MD5 hash of a user's password
 * @param string $salt New salt for the user's password
 * @param string $resetkey Code e-mailed to a user to reset a password
 * @param string $email E-mail address of the user resetting their password
 *
 * @return string|void Redirect page if successful, otherwise return error message
 */
function password_reset($hash, $salt, $resetkey, $email) {
	$dbh = DB::connect();
	$q = "UPDATE Users ";
	$q.= "SET Passwd = '$hash', ";
	$q.= "Salt = '$salt', ";
	$q.= "ResetKey = '' ";
	$q.= "WHERE ResetKey != '' ";
	$q.= "AND ResetKey = " . $dbh->quote($resetkey) . " ";
	$q.= "AND Email = " . $dbh->quote($email);
	$result = $dbh->exec($q);

	if (!$result) {
		$error = __('Invalid e-mail and reset key combination.');
		return $error;
	} else {
		header('Location: ' . get_uri('/passreset/') . '?step=complete');
		exit();
	}
}

/**
 * Determine if the password is longer than the minimum length
 *
 * @param string $passwd The password to check
 *
 * @return bool True if longer than minimum length, otherwise false
 */
function good_passwd($passwd) {
	if ( strlen($passwd) >= PASSWD_MIN_LEN ) {
		return true;
	}
	return false;
}

/**
 * Determine if the password is correct and salt it if it hasn't been already
 *
 * @param string $userID The user ID to check the password against
 * @param string $passwd The password the visitor sent
 *
 * @return bool True if password was correct and properly salted, otherwise false
 */
function valid_passwd($userID, $passwd) {
	$dbh = DB::connect();
	if ( strlen($passwd) > 0 ) {
		# get salt for this user
		$salt = get_salt($userID);
		if ($salt) {
			# use salt
			$q = "SELECT ID FROM Users ";
			$q.= "WHERE ID = " . $userID . " ";
			$q.= "AND Passwd = " . $dbh->quote(salted_hash($passwd, $salt));
			$result = $dbh->query($q);
			if ($result) {
				$row = $result->fetch(PDO::FETCH_NUM);
				if ($row[0]) {
					return true;
				}
			}
		} else {
			# check without salt
			$q = "SELECT ID FROM Users ";
			$q.= "WHERE ID = " . $userID . " ";
			$q.= "AND Passwd = " . $dbh->quote(md5($passwd));
			$result = $dbh->query($q);
			if ($result) {
				$row = $result->fetch(PDO::FETCH_NUM);
				if ($row[0]) {
					# password correct, but salt it first
					if (!save_salt($userID, $passwd)) {
						trigger_error("Unable to salt user's password;" .
							" ID " . $userID, E_USER_WARNING);
						return false;
					}
					return true;
				}
			}
		}
	}
	return false;
}

/**
 * Determine if a user's password is empty
 *
 * @param string $uid The user ID to check for an empty password
 *
 * @return bool True if the user's password is empty, otherwise false
 */
function passwd_is_empty($uid) {
	$dbh = DB::connect();

	$q = "SELECT * FROM Users WHERE ID = " . $dbh->quote($uid) . " ";
	$q .= "AND Passwd = " . $dbh->quote('');
	$result = $dbh->query($q);

	if ($result->fetchColumn()) {
		return true;
	} else {
		return false;
	}
}

/**
 * Determine if the PGP key fingerprint is valid (must be 40 hexadecimal digits)
 *
 * @param string $fingerprint PGP fingerprint to check if valid
 *
 * @return bool True if the fingerprint is 40 hexadecimal digits, otherwise false
 */
function valid_pgp_fingerprint($fingerprint) {
	$fingerprint = str_replace(" ", "", $fingerprint);
	return (strlen($fingerprint) == 40 && ctype_xdigit($fingerprint));
}

/**
 * Determine if the user account has been suspended
 *
 * @param string $id The ID of user to check if suspended
 *
 * @return bool True if the user is suspended, otherwise false
 */
function user_suspended($id) {
	$dbh = DB::connect();
	if (!$id) {
		return false;
	}
	$q = "SELECT Suspended FROM Users WHERE ID = " . $id;
	$result = $dbh->query($q);
	if ($result) {
		$row = $result->fetch(PDO::FETCH_NUM);
		if ($row[0]) {
			return true;
		}
	}
	return false;
}

/**
 * Delete a specified user account from the database
 *
 * @param int $id The user ID of the account to be deleted
 *
 * @return void
 */
function user_delete($id) {
	$dbh = DB::connect();
	$q = "DELETE FROM Users WHERE ID = " . $id;
	$dbh->query($q);
	return;
}

/**
 * Determine if a user is either a Trusted User or Developer
 *
 * @param string $id The ID of the user to check if privileged
 *
 * @return int|string Return  0 if un-privileged, "2" if Trusted User, "3" if Developer
 */
function user_is_privileged($id) {
	$dbh = DB::connect();
	$q = "SELECT AccountTypeID FROM Users WHERE ID = " . $id;
	$result = $dbh->query($q);
	if ($result) {
		$row = $result->fetch(PDO::FETCH_NUM);
		if($row[0] > 1) {
			return $row[0];
		}
	}
	return 0;

}

/**
 * Remove the session from the database on logout
 *
 * @param string $sid User's session ID
 *
 * @return void
 */
function delete_session_id($sid) {
	$dbh = DB::connect();

	$q = "DELETE FROM Sessions WHERE SessionID = " . $dbh->quote($sid);
	$dbh->query($q);
}

/**
 * Remove all sessions belonging to a particular user
 *
 * @param int $uid ID of user to remove all sessions for
 *
 * @return void
 */
function delete_user_sessions($uid) {
	$dbh = DB::connect();

	$q = "DELETE FROM Sessions WHERE UsersID = " . intval($uid);
	$dbh->exec($q);
}

/**
 * Remove sessions from the database that have exceed the timeout
 *
 * @global int $LOGIN_TIMEOUT Time until session expires
 *
 * @return void
 */
function clear_expired_sessions() {
	global $LOGIN_TIMEOUT;

	$dbh = DB::connect();

	$q = "DELETE FROM Sessions WHERE LastUpdateTS < (UNIX_TIMESTAMP() - $LOGIN_TIMEOUT)";
	$dbh->query($q);

	return;
}

/**
 * Get account details for a specific user
 *
 * @param string $uid The User ID of account to get information for
 * @param string $username The username of the account to get for
 *
 * @return array Account details for the specified user
 */
function account_details($uid, $username) {
	$dbh = DB::connect();
	$q = "SELECT Users.*, AccountTypes.AccountType ";
	$q.= "FROM Users, AccountTypes ";
	$q.= "WHERE AccountTypes.ID = Users.AccountTypeID ";
	if (!empty($uid)) {
		$q.= "AND Users.ID = ".intval($uid);
	} else {
		$q.= "AND Users.Username = " . $dbh->quote($username);
	}
	$result = $dbh->query($q);

	if ($result) {
		$row = $result->fetch(PDO::FETCH_ASSOC);
	}

	return $row;
}

/**
 * Determine if a user has already voted on a specific proposal
 *
 * @param string $voteid The ID of the Trusted User proposal
 * @param string $uid The ID to check if the user already voted
 *
 * @return bool True if the user has already voted, otherwise false
 */
function tu_voted($voteid, $uid) {
	$dbh = DB::connect();

	$q = "SELECT COUNT(*) FROM TU_Votes ";
	$q.= "WHERE VoteID = " . intval($voteid) . " AND UserID = " . intval($uid);
	$result = $dbh->query($q);
	if ($result->fetchColumn() > 0) {
		return true;
	}
	else {
		return false;
	}
}

/**
 * Get all current Trusted User proposals from the database
 *
 * @param string $order Ascending or descending order for the proposal listing
 *
 * @return array The details for all current Trusted User proposals
 */
function current_proposal_list($order) {
	$dbh = DB::connect();

	$q = "SELECT * FROM TU_VoteInfo WHERE End > " . time() . " ORDER BY Submitted " . $order;
	$result = $dbh->query($q);

	$details = array();
	while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
		$details[] = $row;
	}

	return $details;
}

/**
 * Get a subset of all past Trusted User proposals from the database
 *
 * @param string $order Ascending or descending order for the proposal listing
 * @param string $lim The number of proposals to list with the offset
 *
 * @return array The details for the subset of past Trusted User proposals
 */
function past_proposal_list($order, $lim) {
	$dbh = DB::connect();

	$q = "SELECT * FROM TU_VoteInfo WHERE End < " . time() . " ORDER BY Submitted " . $order . $lim;
	$result = $dbh->query($q);

	$details = array();
	while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
		$details[] = $row;
	}

	return $details;
}

/**
 * Get the vote ID of the last vote of all Trusted Users
 *
 * @return array The vote ID of the last vote of each Trusted User
 */
function last_votes_list() {
	$dbh = DB::connect();

	$q = "SELECT UserID, MAX(VoteID) AS LastVote FROM TU_Votes, ";
	$q .= "TU_VoteInfo, Users WHERE TU_VoteInfo.ID = TU_Votes.VoteID AND ";
	$q .= "TU_VoteInfo.End < UNIX_TIMESTAMP() AND ";
	$q .= "Users.ID = TU_Votes.UserID AND Users.AccountTypeID = 2 ";
	$q .= "GROUP BY UserID ORDER BY LastVote DESC";
	$result = $dbh->query($q);

	$details = array();
	while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
		$details[] = $row;
	}

	return $details;
}

/**
 * Determine the total number of Trusted User proposals
 *
 * @return string The total number of Trusted User proposals
 */
function proposal_count() {
	$dbh = DB::connect();
	$q = "SELECT COUNT(*) FROM TU_VoteInfo";
	$result = $dbh->query($q);
	$row = $result->fetch(PDO::FETCH_NUM);

	return $row[0];
}

/**
 * Get all details related to a specific vote from the database
 *
 * @param string $voteid The ID of the Trusted User proposal
 *
 * @return array All stored details for a specific vote
 */
function vote_details($voteid) {
	$dbh = DB::connect();

	$q = "SELECT * FROM TU_VoteInfo ";
	$q.= "WHERE ID = " . intval($voteid);

	$result = $dbh->query($q);
	$row = $result->fetch(PDO::FETCH_ASSOC);

	return $row;
}

/**
 * Get an alphabetical list of users who voted for a proposal with HTML links
 *
 * @param string $voteid The ID of the Trusted User proposal
 *
 * @return array All users who voted for a specific proposal
 */
function voter_list($voteid) {
	$dbh = DB::connect();

	$whovoted = array();

	$q = "SELECT tv.UserID,U.Username ";
	$q.= "FROM TU_Votes tv, Users U ";
	$q.= "WHERE tv.VoteID = " . intval($voteid);
	$q.= " AND tv.UserID = U.ID ";
	$q.= "ORDER BY Username";

	$result = $dbh->query($q);
	if ($result) {
		while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
			$whovoted[] = $row['Username'];
		}
	}
	return $whovoted;
}

/**
 * Cast a vote for a specific user proposal
 *
 * @param string $voteid The ID of the proposal being voted on
 * @param string $uid The user ID of the individual voting
 * @param string $vote Vote position, either "Yes", "No", or "Abstain"
 * @param int $newtotal The total number of votes after the user has voted
 *
 * @return void
 */
function cast_proposal_vote($voteid, $uid, $vote, $newtotal) {
	$dbh = DB::connect();

	$q = "UPDATE TU_VoteInfo SET " . $vote . " = (" . $newtotal . ") WHERE ID = " . $voteid;
	$result = $dbh->exec($q);

	$q = "INSERT INTO TU_Votes (VoteID, UserID) VALUES (" . intval($voteid) . ", " . intval($uid) . ")";
	$result = $dbh->exec($q);
}

/**
 * Verify a user has the proper permissions to edit an account
 *
 * @param string $atype Account type of the editing user
 * @param array $acctinfo User account information for edited account
 * @param int $uid User ID of the editing user
 *
 * @return bool True if permission to edit the account, otherwise false
 */
function can_edit_account($atype, $acctinfo, $uid) {
	/* Developers can edit any account */
	if ($atype == 'Developer') {
		return true;
	}

	/* Trusted Users can edit all accounts except Developer accounts */
	if ($atype == 'Trusted User' &&
		$acctinfo['AccountType'] != 'Developer') {
			return true;
	}

	/* Users can edit only their own account */
	if ($acctinfo['ID'] == $uid) {
		return true;
	}

	return false;
}

<?php

include_once('aur.inc.php');

/**
 * Display the most recent 10 packages
 *
 * @return void
 */
function updates_table() {
	$dbh = DB::connect();
	$key = 'recent_updates';
	if(!($newest_packages = get_cache_value($key))) {
		$q = 'SELECT * FROM Packages ORDER BY ModifiedTS DESC LIMIT 10';
		$result = $dbh->query($q);

		$newest_packages = new ArrayObject();
		while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
			$newest_packages->append($row);
		}
		set_cache_value($key, $newest_packages);
	}
	include('stats/updates_table.php');
}

/**
 * Display a user's statistics table
 *
 * @param string $userid The user ID of the person to get package statistics for
 *
 * @return void
 */
function user_table($userid) {
	$base_q = "SELECT count(*) FROM Packages WHERE Packages.MaintainerUID = " . $userid;

	$maintainer_unsupported_count = db_cache_value($base_q,
		'user_unsupported_count:' . $userid);

	$q = "SELECT count(*) FROM Packages WHERE Packages.OutOfDateTS IS NOT NULL AND Packages.MaintainerUID = " . $userid;

	$flagged_outdated = db_cache_value($q, 'user_flagged_outdated:' . $userid);

	include('stats/user_table.php');
}

/**
 * Display the general package statistics table
 *
 * @return void
 */
function general_stats_table() {
	# AUR statistics
	$q = "SELECT count(*) FROM Packages";
	$unsupported_count = db_cache_value($q, 'unsupported_count');

	$q = "SELECT count(*) FROM Packages WHERE MaintainerUID IS NULL";
	$orphan_count = db_cache_value($q, 'orphan_count');

	$q = "SELECT count(*) FROM Users";
	$user_count = db_cache_value($q, 'user_count');

	$q = "SELECT count(*) FROM Users,AccountTypes WHERE Users.AccountTypeID = AccountTypes.ID AND AccountTypes.AccountType = 'Trusted User'";
	$tu_count = db_cache_value($q, 'tu_count');

	$targstamp = intval(strtotime("-7 days"));
	$yearstamp = intval(strtotime("-1 year"));

	$q = "SELECT count(*) FROM Packages WHERE Packages.ModifiedTS >= $targstamp AND Packages.ModifiedTS = Packages.SubmittedTS";
	$add_count = db_cache_value($q, 'add_count');

	$q = "SELECT count(*) FROM Packages WHERE Packages.ModifiedTS >= $targstamp AND Packages.ModifiedTS != Packages.SubmittedTS";
	$update_count = db_cache_value($q, 'update_count');

	$q = "SELECT count(*) FROM Packages WHERE Packages.ModifiedTS >= $yearstamp AND Packages.ModifiedTS != Packages.SubmittedTS";
	$update_year_count = db_cache_value($q, 'update_year_count');

	$q = "SELECT count(*) FROM Packages WHERE Packages.ModifiedTS = Packages.SubmittedTS";
	$never_update_count = db_cache_value($q, 'never_update_count');

	include('stats/general_stats_table.php');
}

<?php

set_include_path(get_include_path() . PATH_SEPARATOR . '../lib');
include_once("config.inc.php");

require_once('Archive/Tar.php');

include_once("aur.inc.php");         # access AUR common functions
include_once("pkgfuncs.inc.php");    # package functions

set_lang();                 # this sets up the visitor's language
check_sid();                # see if they're still logged in

$cwd = getcwd();

if ($_COOKIE["AURSID"]) {
	$uid = uid_from_sid($_COOKIE['AURSID']);
}
else {
	$uid = NULL;
}

if ($uid):

	# Track upload errors
	$error = "";

	if (isset($_REQUEST['pkgsubmit'])) {

		# Make sure authenticated user submitted the package themselves
		if (!check_token()) {
			$error = __("Invalid token for user action.");
		}

		# Before processing, make sure we even have a file
		switch($_FILES['pfile']['error']) {
			case UPLOAD_ERR_INI_SIZE:
				$maxsize =  ini_get('upload_max_filesize');
				$error = __("Error - Uploaded file larger than maximum allowed size (%s)", $maxsize);
				break;
			case UPLOAD_ERR_PARTIAL:
				$error = __("Error - File partially uploaded");
				break;
			case UPLOAD_ERR_NO_FILE:
				$error = __("Error - No file uploaded");
				break;
			case UPLOAD_ERR_NO_TMP_DIR:
				$error = __("Error - Could not locate temporary upload folder");
				break;
			case UPLOAD_ERR_CANT_WRITE:
				$error = __("Error - File could not be written");
				break;
		}

		# Check whether the file is gzip'ed
		if (!$error) {
			$fh = fopen($_FILES['pfile']['tmp_name'], 'rb');
			fseek($fh, 0, SEEK_SET);
			list(, $magic) = unpack('v', fread($fh, 2));

			if ($magic != 0x8b1f) {
				$error = __("Error - unsupported file format (please submit gzip'ed tarballs generated by makepkg(8) only).");
			}
		}

		# Check uncompressed file size (ZIP bomb protection)
		if (!$error && $MAX_FILESIZE_UNCOMPRESSED) {
			fseek($fh, -4, SEEK_END);
			list(, $filesize_uncompressed) = unpack('V', fread($fh, 4));

			if ($filesize_uncompressed > $MAX_FILESIZE_UNCOMPRESSED) {
				$error = __("Error - uncompressed file size too large.");
			}
		}

		# Close file handle before extracting stuff
		if (isset($fh) && is_resource($fh)) {
			fclose($fh);
		}

		if (!$error) {
			$tar = new Archive_Tar($_FILES['pfile']['tmp_name']);

			# Extract PKGBUILD and .AURINFO into a string
			$pkgbuild_raw = $srcinfo_raw = '';
			$dircount = 0;
			foreach ($tar->listContent() as $tar_file) {
				if ($tar_file['typeflag'] == 0) {
					if (strchr($tar_file['filename'], '/') === false) {
						$error = __("Error - source tarball may not contain files outside a directory.");
						break;
					}
					elseif (substr($tar_file['filename'], -9) == '/PKGBUILD') {
						$pkgbuild_raw = $tar->extractInString($tar_file['filename']);
					}
					elseif (substr($tar_file['filename'], -9) == '/.AURINFO') {
						$srcinfo_raw = $tar->extractInString($tar_file['filename']);
					}
				}
				elseif ($tar_file['typeflag'] == 5) {
					if (substr_count($tar_file['filename'], "/") > 1) {
						$error = __("Error - source tarball may not contain nested subdirectories.");
						break;
					}
					elseif (++$dircount > 1) {
						$error = __("Error - source tarball may not contain more than one directory.");
						break;
					}
				}
			}

			if (!$error && $dircount !== 1) {
				$error = __("Error - source tarball may not contain files outside a directory.");
			}

			if (!$error && empty($pkgbuild_raw)) {
				$error = __("Error trying to unpack upload - PKGBUILD does not exist.");
			}
		}

		# if no error, get list of directory contents and process PKGBUILD
		# TODO: This needs to be completely rewritten to support stuff like arrays
		# and variable substitution among other things.
		if (!$error) {
			# process PKGBUILD - remove line concatenation
			#
			$pkgbuild = array();
			$line_no = 0;
			$lines = array();
			$continuation_line = 0;
			$current_line = "";
			$paren_depth = 0;
			foreach (explode("\n", $pkgbuild_raw) as $line) {
				$line = trim($line);
				# Remove comments
				$line = preg_replace('/\s*#.*/', '', $line);

				$char_counts = count_chars($line, 0);
				$paren_depth += $char_counts[ord('(')] - $char_counts[ord(')')];
				if (substr($line, strlen($line)-1) == "\\") {
					# continue appending onto existing line_no
					#
					$current_line .= substr($line, 0, strlen($line)-1);
					$continuation_line = 1;
				} elseif ($paren_depth > 0) {
					# assumed continuation
					# continue appending onto existing line_no
					#
					$current_line .= $line . " ";
					$continuation_line = 1;
				} else {
					# maybe the last line in a continuation, or a standalone line?
					#
					if ($continuation_line) {
						# append onto existing line_no
						#
						$current_line .= $line;
						$lines[$line_no] = $current_line;
						$current_line = "";
					} else {
						# it's own line_no
						#
						$lines[$line_no] = $line;
					}
					$continuation_line = 0;
					$line_no++;
				}
			}

			# Now process the lines and put any var=val lines into the
			# 'pkgbuild' array.
			while (list($k, $line) = each($lines)) {
				# Neutralize parameter substitution
				$line = preg_replace('/\${(\w+)#(\w*)}?/', '$1$2', $line);

				$lparts = Array();
				# Match variable assignment only.
				if (preg_match('/^\s*[_\w]+=[^=].*/', $line, $matches)) {
					$lparts = explode("=", $matches[0], 2);
				}

				if (!empty($lparts)) {
					# this is a variable/value pair, strip out
					# array parens and any quoting, except in pkgdesc
					# for pkgdesc, only remove start/end pairs of " or '
					if ($lparts[0]=="pkgdesc") {
						if ($lparts[1]{0} == '"' &&
								$lparts[1]{strlen($lparts[1])-1} == '"') {
							$pkgbuild[$lparts[0]] = substr($lparts[1], 1, -1);
						}
						elseif
							($lparts[1]{0} == "'" &&
							 $lparts[1]{strlen($lparts[1])-1} == "'") {
							$pkgbuild[$lparts[0]] = substr($lparts[1], 1, -1);
						} else {
							$pkgbuild[$lparts[0]] = $lparts[1];
						}
					} else {
						$pkgbuild[$lparts[0]] = str_replace(array("(",")","\"","'"), "",
								$lparts[1]);
					}
				}
			}

			# some error checking on PKGBUILD contents - just make sure each
			# variable has a value. This does not do any validity checking
			# on the values, or attempts to fix line continuation/wrapping.
			$req_vars = array("url", "pkgdesc", "license", "pkgrel", "pkgver", "arch", "pkgname");
			foreach ($req_vars as $var) {
				if (!array_key_exists($var, $pkgbuild)) {
					$error = __('Missing %s variable in PKGBUILD.', $var);
					break;
				}
			}
		}

		# Now, run through the pkgbuild array, and do "eval" and simple substituions.
		if (!$error) {
			while (list($k, $v) = each($pkgbuild)) {
				if (strpos($k,'eval ') !== false) {
					$k = preg_replace('/^eval[\s]*/', "", $k);
					##"eval" replacements
					$pattern_eval = '/{\$({?)([\w]+)(}?)}/';
					while (preg_match($pattern_eval,$v,$regs)) {
						$pieces = explode(",",$pkgbuild["$regs[2]"]);
						## nongreedy matching! - preserving the order of "eval"
						$pattern = '/([\S]*?){\$'.$regs[1].$regs[2].$regs[3].'}([\S]*)/';
						while (preg_match($pattern,$v,$regs_replace)) {
							$replacement = "";
							for ($i = 0; $i < sizeof($pieces); $i++) {
								$replacement .= $regs_replace[1].$pieces[$i].$regs_replace[2]." ";
							}
							$v=preg_replace($pattern, $replacement, $v, 1);
						}
					}
				}

				# Simple variable replacement
				$pattern_var = '/\$({?)([_\w]+)(}?)/';
				$offset = 0;
				while (preg_match($pattern_var, $v, $regs, PREG_OFFSET_CAPTURE, $offset)) {
					$var = $regs[2][0];
					$pos = $regs[0][1];
					$len = strlen($regs[0][0]);

					if (isset($new_pkgbuild[$var])) {
						$replacement = substr($new_pkgbuild[$var], strpos($new_pkgbuild[$var], " "));
					}
					else {
						$replacement = '';
					}

					$v = substr_replace($v, $replacement, $pos, $len);
					$offset = $pos + strlen($replacement);
				}
				$new_pkgbuild[$k] = $v;
			}
		}

		# Parse .AURINFO and overwrite PKGBUILD fields accordingly
		unset($pkg_version);
		$depends = array();
		foreach (explode("\n", $srcinfo_raw) as $line) {
			if (empty($line) || $line[0] == '#') {
				continue;
			}
			list($key, $value) = explode(' = ', $line, 2);
			switch ($key) {
			case 'pkgname':
			case 'pkgdesc':
			case 'url':
			case 'license':
				$new_pkgbuild[$key] = $value;
				break;
			case 'pkgver':
				$pkg_version = $value;
				break;
			case 'depend':
				$depends[] = $value;
				break;
			}
		}

		# Validate package name
		if (!$error) {
			$pkg_name = $new_pkgbuild['pkgname'];
			if ($pkg_name[0] == '(') {
				$error = __("Error - The AUR does not support split packages!");
			}
			if (!preg_match("/^[a-z0-9][a-z0-9\.+_-]*$/", $pkg_name)) {
				$error = __("Invalid name: only lowercase letters are allowed.");
			}
		}

		# Determine the full package version with epoch
		if (!$error && !isset($pkg_version)) {
			if (isset($new_pkgbuild['epoch']) && (int)$new_pkgbuild['epoch'] > 0) {
				$pkg_version = sprintf('%d:%s-%s', $new_pkgbuild['epoch'], $new_pkgbuild['pkgver'], $new_pkgbuild['pkgrel']);
			} else {
				$pkg_version = sprintf('%s-%s', $new_pkgbuild['pkgver'], $new_pkgbuild['pkgrel']);
			}
		}

		# Check for http:// or other protocol in url
		if (!$error) {
			$parsed_url = parse_url($new_pkgbuild['url']);
			if (!$parsed_url['scheme']) {
				$error = __("Package URL is missing a protocol (ie. http:// ,ftp://)");
			}
		}

		# TODO: This is where other additional error checking can be
		# performed. Examples: #md5sums == #sources?, md5sums of any
		# included files match?, install scriptlet file exists?

		# The DB schema imposes limitations on number of allowed characters
		# Print error message when these limitations are exceeded
		if (!$error) {
			if (strlen($pkg_name) > 64) {
				$error = __("Error - Package name cannot be greater than %d characters", 64);
			}
			if (strlen($new_pkgbuild['url']) > 255) {
				$error = __("Error - Package URL cannot be greater than %d characters", 255);
			}
			if (strlen($new_pkgbuild['pkgdesc']) > 255) {
				$error = __("Error - Package description cannot be greater than %d characters", 255);
			}
			if (strlen($new_pkgbuild['license']) > 40) {
				$error = __("Error - Package license cannot be greater than %d characters", 40);
			}
			if (strlen($pkg_version) > 32) {
				$error = __("Error - Package version cannot be greater than %d characters", 32);
			}
		}

		if (isset($pkg_name)) {
			$incoming_pkgdir = INCOMING_DIR . substr($pkg_name, 0, 2) . "/" . $pkg_name;
		}

		if (!$error) {
			# First, see if this package already exists, and if it can be overwritten
			$pkg_id = pkgid_from_name($pkg_name);
			if (can_submit_pkg($pkg_name, $_COOKIE["AURSID"])) {
				if (file_exists($incoming_pkgdir)) {
					# Blow away the existing file/dir and contents
					rm_tree($incoming_pkgdir);
				}

				# The mode is masked by the current umask, so not as scary as it looks
				if (!mkdir($incoming_pkgdir, 0777, true)) {
					$error = __( "Could not create directory %s.", $incoming_pkgdir);
				}
			} else {
				$error = __( "You are not allowed to overwrite the %s%s%s package.", "<strong>", $pkg_name, "</strong>");
			}

		if (!$error) {
			# Check if package name is blacklisted.
			if (!$pkg_id && pkgname_is_blacklisted($pkg_name)) {
				if (!canSubmitBlacklisted(account_from_sid($_COOKIE["AURSID"]))) {
					$error = __( "%s is on the package blacklist, please check if it's available in the official repos.", $pkg_name);
				}
			}
		}
		}

		if (!$error) {
			if (!chdir($incoming_pkgdir)) {
				$error = __("Could not change directory to %s.", $incoming_pkgdir);
			}

			file_put_contents('PKGBUILD', $pkgbuild_raw);
			move_uploaded_file($_FILES['pfile']['tmp_name'], $pkg_name . '.tar.gz');
		}

		# Update the backend database
		if (!$error) {
			begin_atomic_commit();

			$pdata = pkgdetails_by_pkgname($new_pkgbuild['pkgname']);

			# Check the category to use, "1" meaning "none" (or "keep category" for
			# existing packages).
			if (isset($_POST['category'])) {
				$category_id = intval($_POST['category']);
				if ($category_id <= 0) {
					$category_id = 1;
				}
			}
			else {
				$category_id = 1;
			}

			if ($pdata) {
				# This is an overwrite of an existing package, the database ID
				# needs to be preserved so that any votes are retained. However,
				# PackageDepends and PackageSources can be purged.
				$packageID = $pdata["ID"];

				# Flush out old data that will be replaced with new data
				remove_pkg_deps($packageID);
				remove_pkg_sources($packageID);

				# If a new category was chosen, change it to that
				if ($category_id > 1) {
					update_pkg_category($packageID, $category_id);
				}

				# Update package data
				update_pkgdetails($new_pkgbuild['pkgname'], $new_pkgbuild['license'], $pkg_version, $new_pkgbuild['pkgdesc'], $new_pkgbuild['url'], $uid, $packageID);
			} else {
				# This is a brand new package
				new_pkgdetails($new_pkgbuild['pkgname'], $new_pkgbuild['license'], $pkg_version, $category_id, $new_pkgbuild['pkgdesc'], $new_pkgbuild['url'], $uid);
				$packageID = last_insert_id();

			}

			# Update package depends
			if (empty($depends) && !empty($new_pkgbuild['depends'])) {
				$depends = explode(" ", $new_pkgbuild['depends']);
			}
			if (!empty($depends)) {
				foreach ($depends as $dep) {
					$deppkgname = preg_replace("/(<|<=|=|>=|>).*/", "", $dep);
					$depcondition = str_replace($deppkgname, "", $dep);

					if ($deppkgname == "") {
						continue;
					}
					else if ($deppkgname == "#") {
						break;
					}
					add_pkg_dep($packageID, $deppkgname, $depcondition);
				}
			}

			# Insert sources
			if (!empty($new_pkgbuild['source'])) {
				$sources = explode(" ", $new_pkgbuild['source']);
				foreach ($sources as $src) {
					add_pkg_src($packageID, $src);
				}
			}

			# If we just created this package, or it was an orphan and we
			# auto-adopted, add submitting user to the notification list.
			if (!$pdata || $pdata["MaintainerUID"] === NULL) {
				pkg_notify(account_from_sid($_COOKIE["AURSID"]), array($packageID), true);
			}

			# Entire package creation process is atomic
			end_atomic_commit();

			header('Location: ' . get_pkg_uri($pkg_name));
		}

		chdir($cwd);
	}

# Logic over, let's do some output

html_header("Submit");

?>

<?php if ($error): ?>
	<p class="pkgoutput"><?= $error ?></p>
<?php endif; ?>

<div class="box">
	<h2><?= __("Submit"); ?></h2>
	<p><?= __("Upload your source packages here. Create source packages with `makepkg --source`.") ?></p>

<?php
	if (empty($_REQUEST['pkgsubmit']) || $error):
		# User is not uploading, or there were errors uploading - then
		# give the visitor the default upload form
		if (ini_get("file_uploads")):

			$pkg_categories = pkgCategories();
?>

<form action="<?= get_uri('/submit/'); ?>" method="post" enctype="multipart/form-data">
	<fieldset>
		<div>
			<input type="hidden" name="pkgsubmit" value="1" />
			<input type="hidden" name="token" value="<?= htmlspecialchars($_COOKIE['AURSID']) ?>" />
		</div>
		<p>
			<label for="id_category"><?= __("Package Category"); ?>:</label>
			<select id="id_category" name="category">
				<option value="1"><?= __("Select Category"); ?></option>
				<?php
					foreach ($pkg_categories as $num => $cat):
						print '<option value="' . $num . '"';
						if (isset($_POST['category']) && $_POST['category'] == $cat):
							print ' selected="selected"';
						endif;
						print '>' . $cat . '</option>';
					endforeach;
				?>
			</select>
		</p>
		<p>
			<label for="id_file"><?= __("Upload package file"); ?>:</label>
			<input id="id_file" type="file" name="pfile" size='30' />
		</p>
		<p>
			<label></label>
			<input class="button" type="submit" value="<?= __("Upload"); ?>" />
		</p>
	</fieldset>
</form>
</div>
<?php
		else:
			print __("Sorry, uploads are not permitted by this server.");
?>

<br />
</div>
<?php
		endif;
	endif;
else:
	# Visitor is not logged in
	html_header("Submit");
	print __("You must create an account before you can upload packages.");
?>

<br />

<?php
endif;
?>



<?php
html_footer(AUR_VERSION);


<?php

set_include_path(get_include_path() . PATH_SEPARATOR . '../lib');

include_once("aur.inc.php");
include_once("pkgfuncs.inc.php");

set_lang();
check_sid();

html_header(__("Package Deletion"));

$atype = "";

if (isset($_COOKIE["AURSID"])) {
	$atype = account_from_sid($_COOKIE["AURSID"]);
}

if ($atype == "Trusted User" || $atype == "Developer"): ?>
<div class="box">
	<h2><?= __('Delete Package: %s', htmlspecialchars($pkgname)) ?></h2>
	<p>
		<?= __('Use this form to delete the package (%s%s%s) from the AUR. ',
			'<strong>', htmlspecialchars($pkgname), '</strong>'); ?>
		<?= __('Deletion of a package is permanent. '); ?>
		<?= __('Select the checkbox to confirm action.') ?>
	</p>
	<form action="<?= get_uri('/packages/'); ?>" method="post">
		<fieldset>
			<input type="hidden" name="IDs[<?= $pkgid ?>]" value="1" />
			<input type="hidden" name="ID" value="<?= $pkgid ?>" />
			<input type="hidden" name="token" value="<?= htmlspecialchars($_COOKIE['AURSID']) ?>" />
			<p><input type="checkbox" name="confirm_Delete" value="1" />
			<?= __("Confirm package deletion") ?></p>
			<p><input type="submit" class="button" name="do_Delete" value="<?= __("Delete") ?>" /></p>
		</fieldset>
	</form>
</div>

<?php else:
	print __("Only Trusted Users and Developers can delete packages.");
endif;

html_footer(AUR_VERSION);

<table class="arch-bio-entry">
	<tr>
		<td>
			<h3><?= htmlspecialchars($row["Username"], ENT_QUOTES) ?></h3>
			<table class="bio">
				<tr>
					<th><?= __("Username") . ":" ?></th>
					<td><?= $row["Username"] ?></td>
				</tr>
				<tr>
					<th><?= __("Account Type") . ":" ?></th>
					<td>
						<?php
						if ($row["AccountType"] == "User") {
							print __("User");
						} elseif ($row["AccountType"] == "Trusted User") {
							print __("Trusted User");
						} elseif ($row["AccountType"] == "Developer") {
							print __("Developer");
						}
						?>
					</td>
				</tr>
				<tr>
					<th><?= __("Email Address") . ":" ?></th>
					<td><a href="mailto:<?= htmlspecialchars($row["Email"], ENT_QUOTES) ?>"><?= htmlspecialchars($row["Email"], ENT_QUOTES) ?></a></td>
				</tr>
				<tr>
					<th><?= __("Real Name") . ":" ?></th>
					<td><?= htmlspecialchars($row["RealName"], ENT_QUOTES) ?></td>
				</tr>
				<tr>
					<th><?= __("IRC Nick") . ":" ?></th>
					<td><?= htmlspecialchars($row["IRCNick"], ENT_QUOTES) ?></td>
				</tr>
				<tr>
					<th><?= __("PGP Key Fingerprint") . ":" ?></th>
					<td><?= html_format_pgp_fingerprint($row["PGPKey"]) ?></td>
				</tr>
				<tr>
					<th><?= __("Status") . ":" ?></th>
					<td>
					<?= $row["InactivityTS"] ? __("Inactive since") . ' ' . date("Y-m-d H:i", $row["InactivityTS"]) : __("Active"); ?>
					</td>
				</tr>
				<tr>
					<th><?= __("Last Voted") . ":" ?></th>
					<td>
					<?= $row["LastVoted"] ? date("Y-m-d", $row["LastVoted"]) : __("Never"); ?>
					</td>
				</tr>
				<tr>
					<th>Links:</th>
					<td>
						<a href="<?= get_uri('/packages/'); ?>?K=<?= $row['Username'] ?>&amp;SeB=m"><?= __("View this user's packages") ?></a>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>

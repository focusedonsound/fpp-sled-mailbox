<?php
# menu.inc — SLED Smart Letters to Santa
# FPP reads this file from each plugin directory and injects matching entries
# into the nav dropdown for the current menu section ($menu variable).

$menuEntries = Array(
	Array(
		'text' => 'SLED Smart Letters',
		'type' => 'content',
		'page' => 'www/index.php',
		'wrap' => 1
	),
	Array(
		'text' => 'SLED Analytics',
		'type' => 'status',
		'page' => 'www/status.php',
		'wrap' => 1
	)
);
# MQTT & Home Assistant settings (www/mqtt.php) are reachable via a button
# on the main dashboard (www/index.php) rather than their own menu entry --
# this plugin already has one 'content' entry (SLED Smart Letters above),
# and each menu area should have at most one.

foreach ($menuEntries as $entry)
{
	if ($entry['type'] != $menu)
		continue;

	$nopage = '';
	if (isset($entry['wrap']) && ($entry['wrap'] == 0))
		$nopage = '&nopage=1';

	printf("<li><a href='plugin.php?plugin=%s&page=%s%s'>%s</a></li>\n",
		$plugin, $entry['page'], $nopage, $entry['text']);
}
?>

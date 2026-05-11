<?php
# menu.inc — SLED Smart Letters to Santa
# FPP reads this file from each plugin directory and calls list_plugin_entries()
# with $menu set to the current nav section. We register under "content"
# (Content Setup) so the page appears in that dropdown.

$menuEntries = Array(
	Array(
		'text' => 'SLED Smart Letters',
		'type' => 'content',
		'page' => 'www/index.php',
		'wrap' => 1
	)
);

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

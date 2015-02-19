<?php
include 'lib/Bootstrap.php';

ftb::$db_file = __DIR__ . '/ftb_triggers.sqlite';
ftb::$log_file = __DIR__ . '/ftb_log.sqlite';
ftb::$backup_path = __DIR__ . '/backup_triggers.sqlite';

startBot('irc.esper.net', 6667, 'Druss', Array('#ftb'), TRUE);

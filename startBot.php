<?php
include 'lib/Bootstrap.php';

ftbbot::$db_file = __DIR__ . '/ftb_triggers.sqlite';
ftbbot::$log_file = __DIR__ . '/ftb_log.sqlite';
ftbbot::$backup_path = __DIR__ . '/backup_triggers.sqlite';

startBot('irc.esper.net', 6667, 'Druss', Array('#ftb'), TRUE);

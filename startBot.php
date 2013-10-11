<?php
include 'lib/Bootstrap.php';

kenbot::$db_file = '/home/niel/ftb_triggers.sqlite';
kenbot::$backup_path = '/home/niel/backup_triggers.sqlite';

startBot('irc.esper.net', 6667, 'FTB-Bot', Array('#kenbot'), TRUE);

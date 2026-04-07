<?php
// Probe what path Herd's PHP uses for sessions when called via the web
header('Content-Type: text/plain');
echo "save_path ini: " . (ini_get('session.save_path') ?: '(empty)') . "\n";
echo "session_save_path(): " . session_save_path() . "\n";
echo "sys_get_temp_dir(): " . sys_get_temp_dir() . "\n";
echo "session.name: " . ini_get('session.name') . "\n";
session_start();
echo "Started SID: " . session_id() . "\n";
echo "Save dir of THIS session would be: " . (session_save_path() ?: sys_get_temp_dir()) . "\n";

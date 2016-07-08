<?php
$channels = Mysql::getInstance()->from('itv')->where(array('status' => 1, 'xmltv_id !=' => ''))->orderby('number')->get()->all();
echo $channels;
?>
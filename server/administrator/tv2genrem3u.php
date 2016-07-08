<?php

$config = parse_ini_file("../custom.ini");
header("Content-Type: text/plain;charset=utf-8");

setlocale(LC_MESSAGES, "ru_RU");
setlocale(LC_TIME, "ru_RU");
putenv('LC_MESSAGES=ru_RU');
bindtextdomain('stb', '../locale');
textdomain('stb');
bind_textdomain_codeset('stb', 'UTF-8');

try
{
    $db = new PDO("mysql:host={$config["mysql_host"]};dbname={$config["db_name"]};charset=UTF8", $config["mysql_user"], $config["mysql_pass"], array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ));

    // Get channels
    $chQuery1 = "SELECT g.title AS genre_name, i.name, i.cmd, i.descr, i.correct_time, i.xmltv_id, i.number FROM itv AS i, tv_genre AS g WHERE i.status<>0 AND i.censored<1 AND g.id = i.tv_genre_id AND i.number<21 ORDER BY name ASC";
    $channels = $db->query($chQuery1)->fetchAll(PDO::FETCH_ASSOC);


    $m3uContent = "#EXTM3U url-tvg=\"http://38net.ilimnet.ru/xmltv/xmltv.xml.gz\" \n";
    foreach ($channels as $channel)
    {
    $deletenumber = array("01 ", "02 ", "03 ", "04 ","05 ","06 ","07 ","08 ","09 ","10 ","11 ","12 ","13 ","14 ","15 ","16 ","17", "18", "19 ","20");
    $channelname = str_replace($deletenumber, "",$channel["name"]);
    
        $m3uContent .= "#EXTINF:-1";
        $m3uContent .= ' group-title="Государственные"';
        $m3uContent .= ' tvg-id="' . _($channel["xmltv_id"]).'"';
        $m3uContent .= ' tvg-shift="' ._(($channel["correct_time"])/60).'"';
        $m3uContent .=  ' ' ._($channel["descr"]);
        $m3uContent .= ",{$channelname}\n";
        $m3uContent .= preg_replace("/^(.*?)\ /", "", $channel["cmd"])."\n";
    }

    $chQuery2 = "SELECT g.title AS genre_name, i.name, i.cmd, i.descr, i.correct_time, i.xmltv_id FROM itv AS i, tv_genre AS g WHERE i.status<>0 AND i.censored<1 AND g.id = i.tv_genre_id ORDER BY i.tv_genre_id ASC";
    $channels = $db->query($chQuery2)->fetchAll(PDO::FETCH_ASSOC);
    
        foreach ($channels as $channel)
    {
    $chan = $channel["xmltv_id"];
    if (empty($chan))
    $chan = 0;
    $tvgid = $channel["descr"];
    $deletenumber = array("01 ", "02 ", "03 ", "04 ","05 ","06 ","07 ","08 ","09 ","10 ", "11 ","12 ","13 ","14 ","15 ","16 ","17 ","18 ","19 ","20 ");
    $channelname = str_replace($deletenumber, "",$channel["name"]);

        $m3uContent .= "#EXTINF:-1";
        $m3uContent .= ' group-title="'._($channel["genre_name"]).'"';
        $m3uContent .= ' tvg-id="' . _($chan).'"';
        $m3uContent .= ' tvg-shift="' ._(($channel["correct_time"])/60).'"';
        if (!empty($tvgid))
        $m3uContent .= ' ' ._($channel["descr"]);
        $m3uContent .= ",{$channelname}\n";
        $m3uContent .= preg_replace("/^(.*?)\ /", "", $channel["cmd"])."\n";
    }
    
    echo $m3uContent;
}
catch(PDOException $e)
{
}

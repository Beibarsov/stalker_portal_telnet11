<?php

use Stalker\Lib\Core\Mysql;
use Stalker\Lib\Core\Stb;
use Stalker\Lib\Core\Config;
use Stalker\Lib\Core\Cache;

/**
 * Main VOD class.
 *
 * @package stalker_portal
 * @author zhurbitsky@gmail.com
 */

class Vod extends AjaxResponse implements \Stalker\Lib\StbApi\Vod
{
    private static $instance = NULL;

    public static function getInstance()
    {
        if (self::$instance == NULL) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    public function __construct()
    {
        parent::__construct();
    }

    public function createLink()
    {
        // Check for movie id and series exists in request
        if (array_key_exists("cmd", $_REQUEST) && array_key_exists("series", $_REQUEST) && $_REQUEST["series"] * 1 > 0)
        {
            $id = (int) $_REQUEST["cmd"];
            $series = (int) $_REQUEST["series"];
        } else {
            return array("cmd" => $_REQUEST["cmd"]);
        }

        $offset = $series - 1;
        $query = "SELECT f.path FROM files f, movies_files mf WHERE mf.movie_id=".$id." AND f.file_id = mf.file_id ORDER BY f.name ASC LIMIT ".$offset.",1";

        $result["cmd"] = str_replace("/mnt/media/Stream", "ffrt3 http://media.ilimnet.ru/content", $this->mediaDbFetchColumn($query));

        return $result;
    }

    public function getLinkByVideoId($video_id, $series = 0, $forced_storage = "")
    {

        $video_id = intval($video_id);

        if (Config::get('enable_tariff_plans')){

            $user = User::getInstance($this->stb->id);
            $all_user_video_ids = $user->getServicesByType('video', 'single');

            if ($all_user_video_ids === null){
                $all_user_video_ids = array();
            }

            if ($all_user_video_ids != 'all'){
                $all_user_video_ids = array_flip($all_user_video_ids);
            }

            $all_user_rented_video_ids = $user->getAllRentedVideo();

            if ((array_key_exists($video_id, $all_user_video_ids) || $all_user_video_ids == 'all') && !array_key_exists($video_id, $all_user_rented_video_ids)){
                return array(
                    'id'         => $video_id,
                    'error'      => 'access_denied'
                );
            }

            $video = Video::getById($video_id);

            if (!empty($video['rtsp_url'])){
                return array(
                    'id'  => $video_id,
                    'cmd' => $this->changeSeriesOnCustomURL($video['rtsp_url'], $series)
                );
            }
        }

        $master = new VideoMaster();

        try {
            $res = $master->play($video_id, intval($series), true, $forced_storage);
            $res['cmd'] = $this->changeSeriesOnCustomURL($res['cmd'], $series);
        } catch (Exception $e) {
            trigger_error($e->getMessage());
        }

        return $res;
    }

    public function getUrlByVideoId($video_id, $series = 0, $forced_storage = "")
    {

        $video = Video::getById($video_id);

        if (empty($video)) {
            throw new Exception("Video not found");
        }

        if (!empty($video['rtsp_url'])) {
            return $video['rtsp_url'];
        }

        $link = $this->getLinkByVideoId($video_id, $series, $forced_storage);

        if (empty($link['cmd'])) {
            throw new Exception("Obtaining url failed");
        }

        if (!empty($link['storage_id'])){
            $storage = Master::getStorageById($link['storage_id']);
            if (!empty($storage)){
                $cache = Cache::getInstance();
                $cache->set($this->stb->id.'_playback',
                    array('type' => 'video', 'id' => $link['id'], 'storage' => $storage['storage_name']), 0, 10);
            }
        }else{
            $cache = Cache::getInstance();
            $cache->del($this->stb->id.'_playback');
        }

        return $link['cmd'];
    }

    public function delLink()
    {

        $item = $_REQUEST['item'];

        if (preg_match("/\/(\w+)$/", $item, $tmp_arr)) {

            $key = $tmp_arr[1];

            var_dump($tmp_arr, strlen($key));

            if (strlen($key) != 32) {
                return false;
            }

            return Cache::getInstance()->del($key);
        }

        return false;
    }

    public function getMediaCats()
    {

        return $this->db->get('media_category')->all();

    }

    public function setVote()
    {

        if ($_REQUEST['vote'] == 'good') {
            $good = 1;
            $bad = 0;
        } else {
            $good = 0;
            $bad = 1;
        }

        $type = $_REQUEST['type'];

        $this->db->insert('vclub_vote',
            array(
                'media_id' => intval($_REQUEST['media_id']),
                'uid' => $this->stb->id,
                'vote_type' => $type,
                'good' => $good,
                'bad' => $bad,
                'added' => 'NOW()'
            ));

        //$video = $this->db->getFirstData('video', array('id' => intval($_REQUEST['media_id'])));
        $video = $this->db->from('video')->where(array('id' => intval($_REQUEST['media_id'])))->get()->first();

        $this->db->update('video',
            array(
                'vote_' . $type . '_good' => $video['vote_' . $type . '_good'] + $good,
                'vote_' . $type . '_bad' => $video['vote_' . $type . '_bad'] + $bad,
            ),
            array('id' => intval($_REQUEST['media_id'])));

        return true;
    }

    public function setPlayed()
    {

        $video_id = intval($_REQUEST['video_id']);
        $storage_id = intval($_REQUEST['storage_id']);

        if (date("j") <= 15) {
            $field_name = 'count_first_0_5';
        } else {
            $field_name = 'count_second_0_5';
        }

        $video = $this->db->from('video')->where(array('id' => $video_id))->get()->first();

        $this->db->update('video',
            array(
                $field_name => $video[$field_name] + 1,
                'count' => $video['count'] + 1,
                'last_played' => 'NOW()'
            ),
            array('id' => $video_id));

        $this->db->insert('played_video',
            array(
                'video_id' => $video_id,
                'uid' => $this->stb->id,
                'storage' => $storage_id,
                'playtime' => 'NOW()'
            ));

        $this->db->update('users',
            array('time_last_play_video' => 'NOW()'),
            array('id' => $this->stb->id));

        $today_record = $this->db->from('daily_played_video')->where(array('date' => date('Y-m-d')))->get()->first();

        if (empty($today_record)) {

            $this->db->insert('daily_played_video',
                array(
                    'count' => 1,
                    'date' => date('Y-m-d')
                ));

        } else {

            $this->db->update('daily_played_video',
                array(
                    'count' => $today_record['count'] + 1,
                    'date' => date('Y-m-d')
                ),
                array(
                    'id' => $today_record['id']
                ));

        }

        $played_video = $this->db->from('stb_played_video')
            ->where(array(
            'uid' => $this->stb->id,
            'video_id' => $video_id
        ))
            ->get()
            ->all();

        if (empty($played_video)) {

            $this->db->insert('stb_played_video',
                array(
                    'uid' => $this->stb->id,
                    'video_id' => $video_id,
                    'playtime' => 'NOW()'
                ));

        } else {

            $this->db->update('stb_played_video',
                array('playtime' => 'NOW()'),
                array(
                    'uid' => $this->stb->id,
                    'video_id' => $video_id
                ));

        }

        if (Config::getSafe('enable_tariff_plans', false)){

            $user = User::getInstance(Stb::getInstance()->id);
            $package = $user->getPackageByVideoId($video['id']);

            if (!empty($package) && $package['service_type'] == 'single'){

                $video_rent_history = Mysql::getInstance()
                    ->from('video_rent_history')
                    ->where(array(
                        'video_id' => $video['id'],
                        'uid'      => Stb::getInstance()->id
                    ))
                    ->orderby('rent_date', 'DESC')
                    ->get()
                    ->first();

                if (!empty($video_rent_history)){
                    Mysql::getInstance()->update('video_rent_history', array('watched' => $video_rent_history['watched'] + 1), array('id' => $video_rent_history['id']));
                }
            }
        }

        return true;
    }

    public function setFav()
    {

        $new_id = intval($_REQUEST['video_id']);

        $favorites = $this->getFav();

        if ($favorites === null) {
            $favorites = array($new_id);
        } else {
            $favorites[] = $new_id;
        }

        return $this->saveFav($favorites, $this->stb->id);

        /*if ($fav_video === null){
            $this->db->insert('fav_vclub',
                               array(
                                    'uid'       => $this->stb->id,
                                    'fav_video' => serialize(array($new_id)),
                                    'addtime'   => 'NOW()'
                               ));
             return true;                      
        }
        
        if (!in_array($new_id, $fav_video)){
            
            $fav_video[] = $new_id;
            $fav_video_s = serialize($fav_video);
            
            $this->db->update('fav_vclub',
                               array(
                                    'fav_video' => $fav_video_s,
                                    'edittime'  => 'NOW()'),
                               array('uid' => $this->stb->id));
            
        }
        
        return true;*/
    }

    public function saveFav(array $fav_array, $uid)
    {

        if (empty($uid)) {
            return false;
        }

        $fav_videos_str = serialize($fav_array);

        $fav_video = $this->getFav($uid);

        //var_dump($this->stb->id, $fav_video);

        if ($fav_video === null) {
            return $this->db->insert('fav_vclub',
                array(
                    'uid' => $uid,
                    'fav_video' => $fav_videos_str,
                    'addtime' => 'NOW()'
                ))->insert_id();
        } else {
            return $this->db->update('fav_vclub',
                array(
                    'fav_video' => $fav_videos_str,
                    'edittime' => 'NOW()'),
                array('uid' => $uid))->result();
        }
    }

    public function getFav($uid = null){

        if (!$uid){
            $uid = $this->stb->id;
        }

        return $this->getFavByUid($uid);

        /*$fav_video_arr = $this->db->from('fav_vclub')->where(array('uid' => $this->stb->id))->get()->first();

       if ($fav_video_arr === null){
           return null;
       }

       if (empty($fav_video_arr)){
           return array();
       }

       $fav_video = unserialize($fav_video_arr['fav_video']);

       if (!is_array($fav_video)){
           $fav_video = array();
       }

       return $fav_video;*/
    }

    public function getFavByUid($uid)
    {

        $uid = (int)$uid;

        $fav_video_arr = $this->db->from('fav_vclub')->where(array('uid' => $uid))->get()->first();

        if ($fav_video_arr === null) {
            return null;
        }

        if (empty($fav_video_arr)) {
            return array();
        }

        $fav_video = unserialize($fav_video_arr['fav_video']);

        if (!is_array($fav_video)) {
            $fav_video = array();
        }

        return $fav_video;
    }

    public function delFav()
    {

        $del_id = intval($_REQUEST['video_id']);

        $fav_video = $this->getFav();

        if (is_array($fav_video)) {

            if (in_array($del_id, $fav_video)) {

                unset($fav_video[array_search($del_id, $fav_video)]);

                $fav_video_s = serialize($fav_video);

                $this->db->update('fav_vclub',
                    array(
                        'fav_video' => $fav_video_s,
                        'edittime' => 'NOW()'
                    ),
                    array('uid' => $this->stb->id));

            }
        }

        return true;
    }

    public function setEnded()
    {
        $video_id = intval($_REQUEST['video_id']);

        $not_ended = $this->db->from('vclub_not_ended')
            ->where(array(
            'uid' => $this->stb->id,
            'video_id' => $video_id
        ))
            ->get()
            ->first();

        if (!empty($not_ended)){
            return Mysql::getInstance()->delete('vclub_not_ended', array('uid' => $this->stb->id, 'video_id' => $video_id))->result();
        }

        return true;
    }

    public function setNotEnded()
    {

        $video_id = intval($_REQUEST['video_id']);
        $series = intval($_REQUEST['series']);
        $end_time = intval($_REQUEST['end_time']);

        /*$not_ended = $this->db->getFirstData('vclub_not_ended',
        array(
             'uid' => $this->stb->id,
             'video_id' => $video_id
        ));*/
        $not_ended = $this->db->from('vclub_not_ended')
            ->where(array(
            'uid' => $this->stb->id,
            'video_id' => $video_id
        ))
            ->get()
            ->first();


        if (empty($not_ended)) {

            $this->db->insert('vclub_not_ended',
                array(
                    'uid' => $this->stb->id,
                    'video_id' => $video_id,
                    'series' => $series,
                    'end_time' => $end_time,
                    'added' => 'NOW()'
                ));

        } else {

            $this->db->update('vclub_not_ended',
                array(
                    'series' => $series,
                    'end_time' => $end_time,
                    'added' => 'NOW()'
                ),
                array(
                    'uid' => $this->stb->id,
                    'video_id' => $video_id
                ));

        }

        return true;
    }

    private function getData()
    {

        $offset = $this->page * self::max_page_items;

        $where = array();

        if (@$_REQUEST['hd']) {
            $where['hd'] = 1;
        } else {
            $where['hd<='] = 1;
        }

        if (!empty($_REQUEST['category']) && $_REQUEST['category'] == 'coming_soon'){
            $tasks_video = Mysql::getInstance()->from('moderator_tasks')->where(array('ended' => 0, 'media_type' => 2))->get()->all('media_id');
            $scheduled_video = Mysql::getInstance()->from('video_on_tasks')->get()->all('video_id');

            $ids = array_unique(array_merge($tasks_video, $scheduled_video));
        }elseif (@$_REQUEST['category'] && @$_REQUEST['category'] !== '*') {
            $where['category_id'] = intval($_REQUEST['category']);
        }

        if (!$this->stb->isModerator()) {
            if (!isset($ids)){
                $where['accessed'] = 1;
            }

            $where['status'] = 1;

            if ($this->stb->hd) {
                $where['disable_for_hd_devices'] = 0;
            }
        } else {
            $where['status>='] = 1;
        }

        if (@$_REQUEST['years'] && @$_REQUEST['years'] !== '*') {
            $where['year'] = $_REQUEST['years'];
        }

        if ((empty($_REQUEST['category']) || $_REQUEST['category'] == '*') && !Config::getSafe('show_adult_movies_in_common_list', true)){
            $where['category_id!='] = (int) Mysql::getInstance()->from('media_category')->where(array('category_alias' => 'adult'))->get()->first('id');
        }

        $like = array();

        if (@$_REQUEST['abc'] && @$_REQUEST['abc'] !== '*') {

            $letter = $_REQUEST['abc'];

            $like = array('video.name' => $letter . '%');
        }

        $where_genre = array();

        if (@$_REQUEST['genre'] && @$_REQUEST['genre'] !== '*' && $_REQUEST['category'] !== '*') {

            $genre = intval($_REQUEST['genre']);

            $where_genre['cat_genre_id_1'] = $genre;
            $where_genre['cat_genre_id_2'] = $genre;
            $where_genre['cat_genre_id_3'] = $genre;
            $where_genre['cat_genre_id_4'] = $genre;
        }

        if (@$_REQUEST['category'] == '*' && @$_REQUEST['genre'] !== '*') {

            $genre_title = $this->db->from('cat_genre')->where(array('id' => intval($_REQUEST['genre'])))->get()->first('title');

            $genres_ids = $this->db->from('cat_genre')->where(array('title' => $genre_title))->get()->all('id');
        }

        $search = array();

        if (!empty($_REQUEST['search'])) {

            $letters = $_REQUEST['search'];

            $search['video.name'] = '%' . $letters . '%';
            $search['o_name'] = '%' . $letters . '%';
            $search['actors'] = '%' . $letters . '%';
            $search['director'] = '%' . $letters . '%';
            $search['year'] = '%' . $letters . '%';
        }

        $data = $this->db
            ->select('video.*, (select group_concat(screenshots.id) from screenshots where media_id=video.id) as screenshots')
            ->from('video')
            ->where($where)
            ->where($where_genre, 'OR ');

        if (isset($ids)){
            $data->in('id', $ids);
        }

        if (!empty($genres_ids) && is_array($genres_ids)) {

            $data = $data->group_in(array(
                'cat_genre_id_1' => $genres_ids,
                'cat_genre_id_2' => $genres_ids,
                'cat_genre_id_3' => $genres_ids,
                'cat_genre_id_4' => $genres_ids,
            ), 'OR');
        }

        $data = $data->like($like)
            ->like($search, 'OR ')
        //->groupby('video.path')
            ->limit(self::max_page_items, $offset);

        return $data;
    }

    public function getOrderedList()
    {

        $fav = $this->getFav();

        // Conditions rules
        $orderBy = array(
            "added" => "m.updated_at DESC", // Added condition SQL
            "name" => "m.name ASC",
        );

        // Category condition
        $category = (array_key_exists('category', $_REQUEST) && $_REQUEST['category'] != "*") ?
            "mgen.genre_id = ".$_REQUEST['category']: "";

        // Sort by condition
        $sortBy = (array_key_exists('sortby', $_REQUEST)) ?
            $_REQUEST['sortby'] : 'added';

        // Favorites
        if(array_key_exists('fav', $_REQUEST) && $_REQUEST['fav'] == 1) {
            if($fav) {
                $favCond = implode(' OR m.movie_id = ', $fav);
                $favCond = "(m.movie_id = ".$favCond.")";
            } else {
                return array(
                            "total_items" => 0,
                            "max_page_items" => 14,
                            "cur_page" => 1,
                            "data" => null,
                        );
            }
        }

        // Search term
        $search = (array_key_exists('search', $_REQUEST)) ?
            $_REQUEST['search'] : null;

        // Pagination
        $page = (array_key_exists('p', $_REQUEST)) ?
            $_REQUEST['p'] : 1;

        $query = "SELECT m.movie_id, 
                         m.international_name AS o_name,
                         m.description AS description,
                         m.name AS name,
                         m.covers AS covers,
                         m.year AS year,
                         m.updated_at AS added,
                         GROUP_CONCAT(DISTINCT ps.name SEPARATOR ', ') as director,
                         GROUP_CONCAT(DISTINCT f.path ORDER BY f.name SEPARATOR '###') as files,
                         count(DISTINCT f.path) as series_count
                  FROM
                         movies AS m".
                         
                  // Category
                  (($category) ? "
                  LEFT OUTER JOIN movies_genres AS mgen ON
                         (m.movie_id = mgen.movie_id)" : "") ."

                  LEFT OUTER JOIN participants p ON
                         m.movie_id = p.movie_id
                  LEFT OUTER JOIN persones ps ON
                         ps.person_id = p.person_id AND
                         p.role_id = 1         /* Directors */

                  INNER JOIN movies_files mf ON
                         mf.movie_id = m.movie_id
                  INNER JOIN files f ON
                         f.file_id = mf.file_id
                  WHERE
                         m.hidden=0".
                         
                         (($category) ? " AND ".$category : "" ).
                         (($search) ? " AND m.name LIKE '%".$search."%' " : "").
                         (($favCond) ? " AND ".$favCond : "").

                         "
                  GROUP BY m.name
                  ORDER BY ".$orderBy[$sortBy]. "
                  LIMIT ". (($page - 1) * 14) .",14";

        //return array("query", $query);

        $movies = $this->mediaDbFetchAssocAll($query);
        
        $countQuery = ($category) ? 
            "SELECT COUNT(*) FROM movies m, movies_genres mgen WHERE ".$category." AND m.movie_id=mgen.movie_id AND m.hidden=0". (($search) ? " AND m.name LIKE '%".$search."%'" : "") :
            "SELECT COUNT(*) FROM movies m". (($search) ? " WHERE m.name LIKE '%".$search."%'" : "");
        $countMovies = $this->mediaDbFetchColumn($countQuery);

        foreach($movies as &$movie) {

            // Wrap ID
            $movie["id"] = $movie["movie_id"];
            unset($movie["movie_id"]);

            // Mark fav
            if(in_array($movie["id"], $fav)) {
                $movie["fav"] = 1;
            }

            // Get covers
            $covers = explode("\n", $movie['covers']);
            $hash = md5($covers[0]);
            $movie["screenshot_uri"] = "http://media.ilimnet.ru/media/images/".implode("/", str_split(substr($hash, 0, 2)))."/".$hash."/image.jpg";
            unset($movie['covers']);

            // Get files
            $filePathArr = explode("###", $movie["files"]);

            if(count($filePathArr) == 1) {
                $movie["cmd"] = str_replace("/mnt/media/Stream", "ffrt3 http://media.ilimnet.ru/content", $filePathArr[0]);
            } else {
                $movie["cmd"] = $movie["id"];
            }

            $movie["series"] = (count($filePathArr) <= 1) ? false : range(1, $movie["series_count"]); //count($filePathArr));
            unset($movie["files"]);

            // Get genres
            $gQuery = "SELECT 
                                GROUP_CONCAT(DISTINCT g.name SEPARATOR ' / ') AS genres_str
                       FROM
                                genres g, movies_genres mg
                       WHERE
                                mg.movie_id = ".$movie["id"]." AND
                                g.genre_id = mg.genre_id
                       GROUP BY mg.movie_id";
            $movie["genres_str"] = $this->mediaDbFetchColumn($gQuery);

            // Get time
            $timeQuery = "SELECT f.metainfo FROM files f WHERE f.path='".addslashes($filePathArr[0])."'";
            //var_dump($timeQuery);
            $metainfo = $this->mediaDbFetchColumn($timeQuery);
            $metainfo = unserialize($metainfo);
            $movie["time"] = gmdate("h:i:s", $metainfo["playtime_seconds"]);

            // Get actors
            $actorsQuery = "SELECT GROUP_CONCAT(DISTINCT ps.name SEPARATOR ', ') AS actors FROM persones ps, participants p WHERE p.movie_id = ".$movie["id"]." AND ps.person_id = p.person_id AND (p.role_id = 3 OR p.role_id = 4) ORDER BY p.movie_id";
            $movie["actors"] = $this->mediaDbFetchColumn($actorsQuery);
            
        };

        return array(
            //"query" => preg_replace('/\s+/', ' ', $query),
            "total_items" => $countMovies,
            "max_page_items" => 14,
            "cur_page" => $page,
            "data" => $movies,
        );
    }

    public function prepareData()
    {

        $fav = $this->getFav();

        $not_ended = Video::getNotEnded();

        if (Config::get('enable_tariff_plans')){
            $user = User::getInstance($this->stb->id);
            $for_rent = $user->getServicesByType('video', 'single');

            if ($for_rent === null){
                $for_rent = array();
            }

            $rented_video = $user->getAllRentedVideo();

            if ($for_rent != 'all'){
                $for_rent = array_flip($for_rent);
            }else{
                $for_rent = array();
            }
        }else{
            $for_rent = array();
            $rented_video = array();
        }

        for ($i = 0; $i < count($this->response['data']); $i++) {

            if ($this->response['data'][$i]['hd']) {
                $this->response['data'][$i]['sd'] = 0;
            } else {
                $this->response['data'][$i]['sd'] = 1;
            }

            /// TRANSLATORS: "%2$s" - original video name, "%1$s" - video name.
            $this->response['data'][$i]['name'] = sprintf(_('video_name_format'), $this->response['data'][$i]['name'], $this->response['data'][$i]['o_name']);

            $this->response['data'][$i]['hd'] = intval($this->response['data'][$i]['hd']);

            if ($this->response['data'][$i]['censored']) {
                $this->response['data'][$i]['lock'] = 1;
            } else {
                $this->response['data'][$i]['lock'] = 0;
            }

            if ($fav !== null && in_array($this->response['data'][$i]['id'], $fav)) {
                $this->response['data'][$i]['fav'] = 1;
            } else {
                $this->response['data'][$i]['fav'] = 0;
            }

            if (array_key_exists($this->response['data'][$i]['id'], $for_rent) || $for_rent == 'all'){
                $this->response['data'][$i]['for_rent'] = 1;

                if (array_key_exists($this->response['data'][$i]['id'], $rented_video)){
                    $this->response['data'][$i]['rent_info'] = $rented_video[$this->response['data'][$i]['id']];
                }else{
                    $this->response['data'][$i]['open'] = 0;
                }

            }else{
                $this->response['data'][$i]['for_rent'] = 0;
            }

            $this->response['data'][$i]['series'] = unserialize($this->response['data'][$i]['series']);

            if (!empty($this->response['data'][$i]['series'])) {
                $this->response['data'][$i]['position'] = 0;
            }

            if (!empty($not_ended[$this->response['data'][$i]['id']]) && !empty($this->response['data'][$i]['series'])){
                $this->response['data'][$i]['cur_series'] = $not_ended[$this->response['data'][$i]['id']]['series'];
            }

            //$this->response['data'][$i]['screenshot_uri'] = $this->getImgUri($this->response['data'][$i]['screenshot_id']);

            //var_dump('!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!', $this->response['data'][$i]['screenshots']);

            if ($this->response['data'][$i]['screenshots'] === null) {
                $this->response['data'][$i]['screenshots'] = '0';
            }

            $screenshots = explode(",", $this->response['data'][$i]['screenshots']);

            $this->response['data'][$i]['screenshot_uri'] = $this->getImgUri($screenshots[0]);

            $this->response['data'][$i]['genres_str'] = $this->getGenresStrByItem($this->response['data'][$i]);

            if (!empty($this->response['data'][$i]['rtsp_url']) && $this->response['data'][$i]['for_rent'] == 0) {
                if (!empty($this->response['data'][$i]['series'])) {
                    $this->response['data'][$i]['cmd'] = $this->response['data'][$i]['rtsp_url'] = $this->changeSeriesOnCustomURL( $this->response['data'][$i]['rtsp_url'], $this->response['data'][$i]['cur_series']);
                }else{
                    $this->response['data'][$i]['cmd'] = $this->response['data'][$i]['rtsp_url'];
                }
            } else {
                $this->response['data'][$i]['cmd'] = '/media/' . $this->response['data'][$i]['id'] . '.mpg';
            }

            if (@$_REQUEST['sortby'] && @$_REQUEST['sortby'] == 'added') {
                $this->response['data'][$i] = array_merge($this->response['data'][$i], $this->getAddedArr($this->response['data'][$i]['added']));
            }

            if (Config::getSafe('enable_video_low_quality_option', false)){
                $this->response['data'][$i]['low_quality'] = intval($this->response['data'][$i]['low_quality']);
            }else{
                $this->response['data'][$i]['low_quality'] = 0;
            }
        }

        return $this->response;
    }

    private function getAddedArr($datetime)
    {

        $added_time = strtotime($datetime);

        $added_arr = array(
            //'str'       => '',
            //'bg_level'  => ''
        );

        $this_mm = date("m");
        $this_dd = date("d");
        $this_yy = date("Y");

        if ($added_time > mktime(0, 0, 0, $this_mm, $this_dd, $this_yy)) {
            //$added_arr['today'] = System::word('vod_today');
            $added_arr['today'] = _('today');
        } elseif ($added_time > mktime(0, 0, 0, $this_mm, $this_dd - 1, $this_yy)) {
            //$added_arr['yesterday'] = System::word('vod_yesterday');
            $added_arr['yesterday'] = _('yesterday');
        } elseif ($added_time > mktime(0, 0, 0, $this_mm, $this_dd - 7, $this_yy)) {
            //$added_arr['week_and_more'] = System::word('vod_last_week');
            $added_arr['week_and_more'] = _('last week');
        } else {
            $added_arr['week_and_more'] = $this->months[date("n", $added_time) - 1] . ' ' . date("Y", $added_time);
        }

        return $added_arr;
    }

    public function getCategories()
    {
        $categories = $this->mediaDbFetchAssocAll(
            "SELECT genres.genre_id as id, genres.name as title FROM genres
            RIGHT JOIN movies_genres ON (genres.genre_id = movies_genres.genre_id)
            GROUP BY genres.genre_id ORDER BY genres.name"
        );

        // Adding wildcard category
        array_unshift($categories,
            array(
                "id" => "*",
                "title" => "ВСЕ",
                "alias" => "*"
            )
        );

        return $categories;
    }

    public function getGenresByCategoryAlias($cat_alias = '')
    {

        if (!$cat_alias) {
            $cat_alias = @$_REQUEST['cat_alias'];
        }

        $where = array();

        if ($cat_alias != '*') {
            $where['category_alias'] = $cat_alias;
        }

        $genres = $this->db
            ->select('id, title')
            ->from("cat_genre")
            ->where($where)
            ->groupby('title')
            ->orderby('title')
            ->get()
            ->all();

        array_unshift($genres, array('id' => '*', 'title' => '*'));

        $genres = array_map(function($item)
        {
            $item['title'] = _($item['title']);
            return $item;
        }, $genres);

        return $genres;
    }

    public function getYears()
    {
    }

    public function getAbc()
    {

        $abc = array();

        foreach ($this->abc as $item) {
            $abc[] = array(
                'id' => $item,
                'title' => $item
            );
        }

        return $abc;
    }

    public function getGenresStrByItem($item)
    {
    }

    public function setClaim()
    {

        return $this->setClaimGlobal('vclub');
    }

    public function changeSeriesOnCustomURL($url = '', $series = 1){
        $tmp_arr = array();
        if ($series < 1) {
            $series = 1;
        }
        if (preg_match("/(s\d+e)(\d+).*$/i", $url, $tmp_arr)){
            $search_str = $tmp_arr[1].$tmp_arr[2];
            $replace_str = $tmp_arr[1].str_pad($series, 2, '0',  STR_PAD_LEFT );
            $url = str_replace($search_str, $replace_str, $url);
        }
        return $url;
    }


    /**
     * MEDIADB FUNCTIONS
     */

    // Connect to database
    private function getMediaDb()
    {
        if (!$this->media_db)
        {
            try
            {
                $this->media_db = new PDO("mysql:host=".Config::get("mediadb_host").";dbname=".Config::get("mediadb_name"), Config::get("mediadb_user"), Config::get("mediadb_pass"));
                $this->media_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->media_db->exec('SET NAMES utf8');
            }
            catch (PDOException $e)
            {}
        }
        return $this->media_db;
    }

    private function mediaDbFetchAssocAll($query)
    {
        $mediadb = $this->getMediaDb();

        try
        {
            $result = $mediadb->query($query)->fetchAll(\PDO::FETCH_ASSOC);
        }
        catch (PDOException $e)
        { }

        return $result;
    }
    
    private function mediaDbFetchColumn($query)
    {
        $mediadb = $this->getMediaDb();

        try
        {
            $result = $mediadb->query($query)->fetch(\PDO::FETCH_COLUMN);
        }
        catch (PDOException $e)
        { }

        return $result;
    }

}

class VodLinkException extends Exception
{
}

?>

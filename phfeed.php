<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

set_include_path(get_include_path() . PATH_SEPARATOR . '/usr/local/lib/php');
require_once "config.php";
require_once "Cache/Lite.php";

$cache = new Cache_Lite(array(
    'cacheDir' => '/tmp/',
    'lifeTime' => 60*60*24*3,
));

$com_cache = new Cache_Lite(array(
    'cacheDir' => '/tmp/',
    'lifeTime' => 60*60*2,
));

function get(&$var, $default=null) {
    return isset($var) ? $var : $default;
}

function ph_api($endpoint){
    $ch = curl_init();

    $url = "https://api.producthunt.com/v1/$endpoint";

    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        20000);
    curl_setopt($ch, CURLOPT_ENCODING,       "gzip");
    curl_setopt($ch, CURLOPT_MAXREDIRS,      5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . PH_KEY,
    ));

    $r = curl_exec($ch);
    $curl_errno = curl_errno($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_errno > 0) {
        throw new Exception("CURL error: " . $curl_errno . ' ' . $curl_error);
    }

    return json_decode($r, true);
}

function get_ph_frontpage(){
    $data = ph_api('posts');
    return $data["posts"];
}

function get_page($url){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        20000);
    curl_setopt($ch, CURLOPT_ENCODING,       "gzip");
    curl_setopt($ch, CURLOPT_MAXREDIRS,      5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $r = curl_exec($ch);
    $curl_errno = curl_errno($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_errno > 0) {
        throw new Exception("CURL error: " . $curl_errno . ' ' . $curl_error);
    }
    return $r;
}

function get_open_graph($url){
    $r = get_page($url);

    libxml_use_internal_errors(true);
    $doc = new DomDocument();
    $doc->loadHTML($r);
    $xpath = new DOMXPath($doc);
    $query = '//*/meta[starts-with(@property, \'og:\')]';
    $metas = $xpath->query($query);
    $rmetas = array();
    foreach ($metas as $meta) {
        $property = $meta->getAttribute('property');
        $content = $meta->getAttribute('content');
        $rmetas[substr($property, 3)] = $content;
    }
    return $rmetas;
}

function get_content_nocache($url, $discussion_url){
    try{
        $og = get_open_graph($url);
        $og_dis = get_open_graph($discussion_url);

        $res = "";
        if(isset($og['title'])){
            $res .= '<h2>' . $og['title'] . '</h2>';
        }
        if(isset($og['description'])){
            $res .= '<p>' . $og['description'] . '</p>';
        }
        if(isset($og['image'])){
            $res .= '<img src="' . $og['image'] . '"/>';
        }
        if(isset($og_dis['image'])){
            $res .= '<img style="max-width: 500px;" src="' . $og_dis['image'] . '"/>';
        }
        return $res;
    }catch(Exception $e){
        return "Can't get content: " . $e;
    }
}

function get_content($url, $discussion_url){
    global $cache;
    $content = $cache->get("content_$url");
    if(!$content){
        $content = get_content_nocache($url, $discussion_url);
        $cache->save($content, "content_$url");
    }
    return $content;
}

function render_comments($list){
    $res =  "";
    foreach($list as $item){
        if(!isset($item['body'])) continue;

        $user = $item['user'];

        $res .= "<blockquote>";
        $res .= "<a href='{$user['profile_url']}'><img src='{$user['image_url']['32px']}' alt='{$user['name']}' title='{$user['headline']}'/>{$user['name']}</a> ({$user['username']}):";
        $res .= $item['body'];

        if(isset($item["child_comments"])) {
            $res .= render_comments($item["child_comments"]);
        }
        $res .= "</blockquote>";
    }
    return $res;
}

function get_comments($id){
    global $com_cache;
    $coms = $com_cache->get($id);
    if($coms){
        return $coms;
    }

    $jd = ph_api("posts/$id/comments");
    $coms = render_comments($jd["comments"]);

    $com_cache->save($coms, $id);
    return $coms;
}

function get_real_url($url) {
    global $cache;
    $cachekey = "realurl_$url";
    $content = $cache->get($cachekey);
    if($content){
        return $content;
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $a = curl_exec($ch);
    if (preg_match('#Location: (.*)#', $a, $r)) {
        $res = trim($r[1]);
        $cache->save($cachekey, $res);
        return $res;
    } else {
        return null;
    }
}

function edit_common($data) {
    extract($data);
    $trans = array(
        "<" => "&lt;",
        ">" => "&gt;",
        "&" => "&amp;",
    );
    try {
        $url = get_real_url($redirect_url);
        $content = get_content($url, $discussion_url);
    } catch(Exception $e) {
        $content = "Exception $e When getting content for url $url";
    }
    $content .= "<hr/>
        Score: $votes_count<br/>
        Author: <a href='{$user['profile_url']}'><img src='{$user['image_url']['32px']}' alt='{$user['name']}'/>{$user['name']}</a> ({$user['username']}), {$user['headline']}<br/>
        <a href='$url'>Link</a> - <a href='$discussion_url'>Comments</a> ($comments_count)";
    try {
        $content .= '<hr/>' . get_comments($id);
    } catch(Exception $e){
        $content .= '<hr/>Can\'t get comments: ' . $e;
    }

    $data["description"] = strtr($content, $trans);
    $data["url"] = get($url, $discussion_url);
    $data["tagline"] = htmlspecialchars($tagline);

    return $data;
}

$minscore = isset($_GET["ups"]) ? intval($_GET['ups']) : 0;

foreach(get_ph_frontpage() as $i=>$data){
    if($data["votes_count"] > $minscore){
        $items[] = edit_common($data);
    }
}

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<rss version="2.0" xmlns:geo="http://www.w3.org/2003/01/geo/wgs84_pos#" xmlns:content="http://purl.org/rss/1.0/modules/content/">
    <channel>
    <title>Product Hunt Frontpage</title>
        <description>Product Hunt Feed</description>
        <link>https://producthunt.com</link>
<?php if($items): foreach($items as $k=>$v): ?>
        <item>
            <title><?php echo $v["tagline"]; ?></title>
            <link><?php echo htmlspecialchars($v["url"]); ?></link>
            <description><?php echo $v["description"]; ?></description>
            <author><?php echo "{$v['user']['username']}@producthunt.com ({$v['user']['name']})"?></author>
            <guid isPermaLink="false">ph-<?php echo htmlspecialchars($v["id"]); ?></guid>
        </item>
<?php endforeach; endif; ?>
    </channel>
</rss>

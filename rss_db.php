<?php
/////////////////////////////////////////////////////////
//
//  Notes:
//    php-mysqli extention required
//
//    TVRage class written by Ryan Doherty <ryan@ryandoherty.com>
//
/////////////////////////////////////////////////////////
// CONFIG

class config {

  // Change me
  var $sql_s = "127.0.0.1"; // Server address
  var $sql_u = ""; // Username
  var $sql_p = ""; // Password
  var $sql_d = ""; // Database

  var $admin = "your@email.com"; // For display in XML <webMaster> tag
  var $style = true; // Style for the rss feed
  var $limit = 100; // Overwritten if $_GET['limit'] is set

  // Do not edit
  var $api = "test"; // TODO: Unique api key input
  var $ep = 0;
  var $rid = 0;
  var $season = 0;
  var $query = false;
  var $net = array();

  // Loads arguments
  function set($v){
    if(!is_array($v))
      $v = array($v);
    foreach($v as $i)
      if(isset($_GET[$i])){
        $this->$i = $_GET[$i];
        if(isset($this->$i) && !is_numeric($this->$i))
          $this->$i = preg_replace("/\D/","", $this->$i);
      }
  }
}

// END CONFIG
/////////////////////////////////////////////////////////

// Print rss item, format provided by rss_gen.php
function xml_item($title,$file,$category,$date,$size,$url) {
  echo "<item>\n\t";
  echo '<title>'.$title.'</title>'."\n\t";
  echo '<guid isPermaLink="true">'.$url.'</guid>'."\n\t";
  echo '<link>'.$url.'</link>'."\n\t";
  echo '<pubDate>'.$date.'</pubDate>'."\n\t";
  echo '<category>'.$category.'</category>'."\n\t";
  echo '<description>'.$file.'</description>'."\n\t";
  echo '<enclosure url="http://'.$url.'" length="'.$size.'" type="application/x-nzb" />'."\n\t";
  // ^^^ Possible issue with length= (not sure if precision is critical)
  // $size is reversed from formatted text, there is truncated data (ie. 1.5G > 1.5*1024^3 = 1610612736)
  echo '<newznab:attr name="category" value="5000" />'."\n\t";
  echo '<newznab:attr name="category" value="5040" />'."\n\t";
  echo '<newznab:attr name="size" value="'.$size.'" />'."\n\t";
  echo '<newznab:attr name="guid" value="'.md5($file).'" />'."\n";
  echo "</item>\n";
}

// Converts formatted size to back to bytes
function correct_size($size){
  if(!preg_match("/^([\d\.]+) ?([\w])/",$size,$m))
    return 0;
  if(!isset($m[1])||!isset($m[2])) return 0;
  $p = 1;
  switch($m[2]) {
    case 'K': $p = 0x400; break; // 1024^1
    case 'M': $p = 0x100000; break; // 1024^2
    case 'G': $p = 0x40000000; break; // 1024^3
    case 'T': $p = 0x10000000000; break; // 1024^4
  }
  return $m[1]*$p;
}

// Finds the diffrence in time and formats output
function compare_date($now,$then){
  $t = $now-$then;
  if($t > 60){ $t /= 60;
    if($t > 60){ $t /= 60;
      if($t > 24){ $t /= 24;
        if($t > 365){ $t /= 365;
          return round($t,1).' years ago';
        } else return round($t,1).' days ago';
      } else return round($t,1).' hours ago';
    } else return round($t,1).' minutes ago';
  } else return round($t,1).' seconds ago';
}

// Prepare all possible arguments
$cfg = new config;
$cfg->set(array('rid','limit','ep','season')); // TODO: Search query limit offset argument (pagination)

// Basic xml style, note use of Content: for view from browser only
if(isset($_GET['style']) && $cfg->style){
  header ("Content-Type: text/css");
echo <<<EOT
* { display:block; }
:root { margin: 30px 50px; color: #000 }
channel > title { font-size: 25px; text-align: center; background-color: #cfcfcf; }
channel > title:after { display: block; padding: 5px; font-size: 16px; content: "Usage: ?rid=<RageTV ShowID> & ep=<Episode> (optional) & season=<Season> (optional)"; background-color: #dfdfdf; }
channel > webMaster { background-color: #efefef; text-align: center; padding: 5px; }
channel > webMaster:before { content: "Webmaster: "; }
item { padding: 5px 0 20px 10px; background-color: #f9f9f9; }
item > title { font-size: 18px; }
item > link { font-size: 13px; padding: 2px 0; }
item > pubDate { font-size: 12px; }
channel>link,description,language,item>guid,item>category,item>enclosure { display:none; }
EOT;
  exit;
}

// Final output will be in XML format for RSS feed readers
header ("Content-Type: text/xml");

// Enable output buffering
if(!@ob_get_contents()) {
  ob_start();
  ob_implicit_flush(0);
}

// Common data // TODO: Maybe move to config class
$time = time();
$data = array();
$blacklist = array();

// Init MySQLi
$sql = new mysqli;
$sql->connect($cfg->sql_s,$cfg->sql_u,$cfg->sql_p,$cfg->sql_d);

// Ask MySQL if everything is okay
if($sql->connect_errno)
  die("Connection to MySQL has failed: ".$sql->connect_error); // TODO: xml friendly
if(!$sql->ping())
  die("Connection to MySQL has failed: ".$sql->error); // TODO: xml friendly

// Pull show data or lookup and cache
if($cfg->rid){
  // Searching by id
  $s = $sql->stmt_init();
  if($s->prepare("SELECT * FROM `shows` WHERE `rid` = ?;")){
    $s->bind_param("i", $cfg->rid);
    if($s->execute()){
      $f = $s->get_result();
      $s->close();
      if($f->num_rows > 0){
        // Found rid, set show name
        $g = $f->fetch_array(MYSQLI_ASSOC);
        $cfg->query = $g['name'];
      } else {
        // No 'rid' found locally, lookup with tvrage script
        require("TVRage.php");
        $show = TV_Shows::findById($cfg->rid);
        if(is_object($show)){
          if($show->name!=""){
            // Cache the show data locally
            $genres = implode(",",$show->genres);
            $name = str_ireplace("_"," ",preg_replace("/\W/","",str_ireplace(" ","_",$show->name))); // Removing non-word characters

            $in = $sql->stmt_init();
            $in->prepare("INSERT INTO `shows` VALUES (?,?,?,?,?,?,?,?,?,?,?,?);");
            $in->bind_param("issiiississi",$cfg->rid,$show->name,$show->country,$show->started,$show->ended,
              $show->seasons,$show->classification,$show->network,$show->runtime,$genres,$show->airDay,$time);
            $in->execute();
            $in->close();
            $cfg->query = $show->name;
          }
        }
      }
    } else {
      die($s->error); // TODO: Log errors to db to prevent breaking xml
    }
  } else {
    die($s->error); // TODO: Log errors to db to prevent breaking xml
  }
}

// Continue if query != false
if($cfg->query){
  // Get networks
  $n = $sql->query("SELECT * FROM `networks` WHERE `active`='1';");
  if(!$n) die("No networks??"); // TODO: User friendly exit
  while($fn = $n->fetch_assoc())
    $cfg->net[$fn['id']] = $fn;
  if(!count($cfg->net)) die("No Networks??"); // TODO: User friendly exit

  // Get blacklist
  $q = $sql->stmt_init();
  if($q->prepare("SELECT * FROM `blacklist` WHERE `api`=?;")){
    $q->bind_param("s",$cfg->api);
    if($q->execute()){
      $f = $q->get_result();
      $q->close();
      if($f->num_rows > 0){
        while($g = $f->fetch_array(MYSQLI_ASSOC))
          $blacklist[$g['network'].$g['bot'].$g['pack']] = true; // Store information to be accessed by reference (avoid looping)
      }
    } else {
      die($q->error); // TODO: Log errors to db to prevent breaking xml
    }
  } else {
    die($q->error); // TODO: Log errors to db to prevent breaking xml
  }

  // Select the table for each network
  $dbs = "";
  foreach($cfg->net as $nw)
    $dbs .= "`".$nw['table']."_tv`,";
  $dbs = substr($dbs,0,strlen($dbs)-1);
  // Select all rows with %showname%
  $data = array();
  $q = $sql->stmt_init();
  if($q->prepare("SELECT * FROM $dbs WHERE `title` LIKE ? ORDER BY `published` DESC LIMIT ?;")){
  // TODO: ^^^^ Optional limit offset (pagination)
    $name = "%".$cfg->query."%"; // % is SQL wildcard
    $q->bind_param("si",$name,$cfg->limit);
    if($q->execute()){
      $f = $q->get_result();
      $q->close();
      if($f->num_rows > 0){
        while($g = $f->fetch_array(MYSQLI_ASSOC)){
          // All results are stored for further processing.
          $data[] = $g;
        }
      } else {
        // TODO: No results xml message
      }
    } else {
      die($q->error); // TODO: Log errors to db to prevent breaking xml
    }
  } else {
    die($q->error); // TODO: Log errors to db to prevent breaking xml
  }
}

$host = $_SERVER['HTTP_HOST'];
$url = $host.urlencode($_SERVER['REQUEST_URI']);
?>
<?xml version="1.0" encoding="utf-8" ?> 
<?xml-stylesheet type="text/css" href="<?php echo $_SERVER['PHP_SELF']."?style=1"; ?>" ?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:newznab="http://www.newznab.com/DTD/2010/feeds/attributes/">
<channel>
<atom:link href="http://<?php echo $url; ?>" rel="self" type="application/rss+xml" />
<title>Search API</title>
<description>Search Engine API</description>
<link>https://<?php echo $host; ?>/xdcc</link>
<language>en-us</language>
<webMaster><?php echo $cfg->admin; ?></webMaster>
<category></category>
<?php

// Store and empty the current output buffer, to avoid multiple loops updating result count for refined searches
$ob_tmp = ob_get_contents();
ob_clean();

$count = count($data);
foreach ($data as $d) {
  // Refine search if specific eppisode/season is selected
  if(isset($blacklist[$d['network'].$d['bot'].$d['pack']])){
    // Blacklisted result
    $count--;
    continue;
  }
  if($cfg->ep && !preg_match("/E0?{$cfg->ep}/",$d['title'])){
    // Filtered by episode
    $count--;
    continue;
  }
  if($cfg->season && !preg_match("/S0?{$cfg->season}/",$d['title'])){
    // Filtered by season
    $count--;
    continue;
  }
  if(!isset($cfg->net[$d['network']])){
    // Inactive Network
    $count--;
    continue;
  }
  // Format results not filtered
  $net = $cfg->net[$d['network']];
  $size = correct_size($d['size']);
  $date = compare_date($time,$d['published']);
  $url = $net['address']."/".$net['port']."/".$d['channel']."/".$d['bot']."/".$d['pack']."/".$d['file'];
  xml_item($d['title'],$d['file'],$d['category'],$date,$size,$url);
}

// Restore previous output buffer
$ob_res = ob_get_contents();
ob_clean();
echo $ob_tmp;

?>
<newznab:response offset="0" total="<?php echo $count; ?>" />
<?php echo $ob_res; /* Print our results */ ?>
</channel>
</rss>
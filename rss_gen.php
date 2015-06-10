<?php
header ("Content-Type:text/xml");
?>
<?xml version="1.0" encoding="utf-8" ?> 
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:newznab="http://www.newznab.com/DTD/2010/feeds/attributes/">
<channel>
<?php

function clean($string) {
   $string = str_replace(' ', '_', $string); // Replaces all spaces with underscore (accepted "word" replacement)
   return preg_replace('/\W/', '', $string); // Removes special chars. (non-word characters)
}

//Banning bad files so sickbeard doesn't find them
//Needs to be automated some how.
//Should also be stored in a database
$bans = array( 
	array("server"=>"irc.abjects.net","bot"=>"[MG]-HDTV|AS|S|D","pid"=>"385"), 
	array("server"=>"irc.abandoned-irc.net","bot"=>"Zombie-WF-MayFaiR","pid"=>"509"),
	array("server"=>"irc.abandoned-irc.net","bot"=>"Zombie-WF-CrinKleyS","pid"=>"1125")
	);

if(empty($_GET['rid']))
{
  $search = false;

}else{

     // TVRage data should be stored in a database IDs don't change so we should only need to get them once, this should reduce time to search from sickbeard.
     include('TVRage.php');
     $search = true;
     $rid = $_GET['rid']; // TV Rage ID
     $show = array(); // create array for the show data
     $show = TV_Shows::findById($rid); // get the show data
   
     $ep = sprintf("%02d",$_GET['ep']); // episode number - hack to make it store as 01 if digit isn't greater than 9
     $season = sprintf("%02d",$_GET['season']); // tv show season - hack to make it store as 01 if digit isn't greater than 9
     $limit = $_GET['limit']; //100 - not used
     $t = $_GET['t']; // tvsearch - not used
}


     //Function to build and print the rss items
function list_item($title,$date,$size,$url)
{
	$title = str_replace("[TV]-","",$title);
	echo "<item>\n\t";
		echo '<title>'.$title.'</title>'."\n\t";
		echo '<guid isPermaLink="true">'.$url.'</guid>'."\n\t";
		echo '<link>'.$url.'</link>'."\n\t";
		echo '<pubDate>'.$date.'</pubDate>'."\n\t";
		echo '<category>TV &gt; HD</category>'."\n\t";
		echo '<description>'.$title.'</description>'."\n\t";
		echo '<enclosure url="http://'.$url.'" length="'.$size.'" type="application/x-nzb" />'."\n\t";
		echo '<newznab:attr name="category" value="5000" />'."\n\t";
		echo '<newznab:attr name="category" value="5040" />'."\n\t";
		echo '<newznab:attr name="size" value="'.$size.'" />'."\n\t";
 	      	echo '<newznab:attr name="guid" value="'.md5($title).'" />'."\n\n\n";
	echo "</item>\n";
     }

?>
<?php
$url = "http://".$_SERVER[HTTP_HOST].urlencode($_SERVER[REQUEST_URI]);
echo '<atom:link href="'.$url.'" rel="self" type="application/rss+xml" />'."\n";
?>
<title>XDCC Listing RSS Hax</title>
<description>XDCC listing rss hax</description>
<link>Static Link Replacement</link>
<language>en-gb</language>
<webMaster>tim@thedefaced.org (Timothy Lawrence)</webMaster>
<category></category>

<?php
$items = array();

if($search)
{

   $show_name = $show->name; // get the show's name from the returned TVRage object.
   $show_name = str_replace("_"," ",clean($show_name)); // Some shows like american dad include symbols in them which aren't indexed.
   
   if($ep !=0 && !empty($ep)) // Check if we're looking for an episode or season
   	 $search_string = urlencode($show_name."."."S".$season."E".$ep); // if we're looking for an episode we want to add the S and E identifiers, we were only passed an TVRage ID
   else
	   $search_string = urlencode($show_name."."."S".$season); // Same concept as above but without E because we're looking for seasons.
   
   echo $search_string;
   $network_data = file_get_contents("http://ixirc.com/api/?q=".$search_string); // Request the built search string on ixirc's API
   $output = json_decode($network_data); // decode the json return and store it.
   $results = $output->results; // grab the results object from the returned data we don't care about the rest of it yet.

}else{
   //If we weren't supplied a TVRage ID then it's trying to get all new stuff under the category of TV

   $search_terms = array("HDTV","LOL","DIMENSION","IMMERSE","ROVERS","C4TV","CROOKS","AFG","FiHTV","BATV","KILLERS","NTb"); // hack for no categories, we build a tv search by finding file names which have tags related to tv downloads.

   //We'll need to perform searches for each one of the above tags.
   foreach($search_terms as $search)
   {
	$network_data = file_get_contents("http://ixirc.com/api?q=".$search);
	$output = json_decode($network_data);
	$results = $output->results;

	foreach($results as $item)
	{
		array_push($items,$item); // we store all of the results in the items array.
  	}
  }


}

//If we're dealing with a single show search...
if($search)
{

   foreach($results as $item)
   {
	//Make sure we're geting what we asked for
	if( stristr($item->name,$search_string) || stristr( $item->name, str_replace("+",".",$search_string) ) )
	{
	   //hack to ban tar files until auto extraction is in XG
	   if( stristr($item->name,".tar") )
	   {
	   }
	   else
	   {
		array_push($items,$item);
	   }
	}
   }
}

   $num_gets = count($items);
   echo '<newznab:response offset="0" total="'.$num_gets.'" />'."\n";

   //Process items array and list items in RSS format
   foreach($items as $item)
   {
      	$server = $item->naddr;
	$bot = $item->uname;
	$pid = $item->n;

    //Ban specific servers/bots/packetids...
    	$banned = false;
	foreach( $bans as $ban )
    	{
  		if($server == $ban["server"] && $bot == $ban["bot"] && $pid == $ban["pid"] || $server == "irc.abandoned-irc.net")
      			$banned = true;
    	}
	if($banned == false)
	{
		$item_url = $item->naddr."/".$item->nport."/".$item->cname."/".$item->uname."/".$item->n."/".$item->name;
		list_item($item->name,$item->agef,$item->sz,$item_url);
  	} 
  }

?>

</channel>
</rss>

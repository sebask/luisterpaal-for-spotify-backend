<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the Closure to execute when that URI is requested.
|
*/


/*
|--------------------------------------------------------------------------
| Three endpoints to control the awesomeness
|--------------------------------------------------------------------------
*/


Route::get('/', function() // Serve the cache if it exists, otherwise get the albums the slowpoke way!
{
  if (Cache::has('albums'))
  {
    return Response::json(Cache::get('albums'));
  }
  else
  {
    return Response::json(get_luisterpaal_albums());
  }
});

Route::get('/get_fresh_albums', function() // Update the cache behind the scenes, ideal for cronjobs ;)
{
  return 'Refreshed the cache with ' . count(get_luisterpaal_albums()) . ' albums!';
});

Route::get('/clear_cache', function() // Clear the cache manually :)
{
  Cache::forget('albums');
  Cache::forget('luisterpaal_id');
  return 'Cache quite cleared!';
});


/*
|--------------------------------------------------------------------------
| This is where the magic happens
|--------------------------------------------------------------------------
*/

function get_luisterpaal_albums()
{
  $luisterpaal_xml_url = "http://3voor12.vpro.nl/mobiel/luisterpaal/iphone/";
  $albums = array();

  // Make the luisterpaal_xml accessible
  $luisterpaal = qp($luisterpaal_xml_url);

  // Stop the script if the luisterpaal_id is still the same since last time
  if(Cache::has('luisterpaal_id') && $luisterpaal->find('luisterpaal')->attr('id') === Cache::get('luisterpaal_id'))
  {
    App::abort(200, 'Same id I processed last time!');
  }

  // Get all the spotify albums for the luisterpaal albums, forget the ones not available on spotify
  foreach($luisterpaal->xpath('/luisterpaal/albums/album') as $qp_album)
  {

    $album = array();
    $album['title'] = $qp_album->find('title')->first()->text();
    $album['artist'] = $qp_album->find('artist')->first()->text();
    $album['cover'] = $qp_album->find('image')->first()->text();
    $album['tracks'] = array();

    foreach($qp_album->find('tracks > track > title') as $track)
    {
      $album['tracks'][] = $track->text();
    }

    $spotify_result = get_spotify_results_for_album($album['title'], $album['artist']);
    if($spotify_result != false && is_array($spotify_result))
    {
      $album = array_merge($album, $spotify_result);
    } else {
      $album['href'] = null;
      $album['artist_href'] = null;
    }

    $albums[] = $album;
  }

  // Check if albums array has anything inside
  if(count($albums) == 0)
  {
    App::abort(400, 'No albums found');
  }

  // Cache albums forever
  Cache::forever('albums', $albums);

  // Remember the luisterpaal_id for at least a day
  Cache::put('luisterpaal_id', $luisterpaal->find('luisterpaal')->attr('id'), 1440);

  return $albums;
}

function get_spotify_results_for_album($album_title = null, $artist_name = null)
{
  $album_match_percentage = 90.0;
  $artist_match_percentage = 90.0;

  if(empty($album_title))
  {
    Log::warning('No album title provided for get_spotify_results_for_album()');
    return false;
  }

  $spotify_query = trim(urlencode($album_title . ' ' . $artist_name));

  // Make the request to spotify
  $spotify_response = file_get_contents('http://ws.spotify.com/search/1/album.json?q='.$spotify_query);
  if($spotify_response == false)
  {
    Log::warning('Could not connect to Spotify API for album: ' . $album_title);
    return false;
  }

  // Json decode the results
  $spotify_response_decoded = json_decode($spotify_response);
  if( ! $spotify_response_decoded OR ! isset($spotify_response_decoded->albums) OR count($spotify_response_decoded->albums) == 0)
  {
    Log::notice('Spotify API did not return any results for album: ' . $album_title);
    return false;
  }

  $matched_album = null;

  // Find an album name with an at least 90% match of the one we're looking for
  foreach($spotify_response_decoded->albums as $returned_album) {
    similar_text($album_title, $returned_album->name, $match_in_percentage);
    if($match_in_percentage > $album_match_percentage){
      $matched_album = $returned_album;
      break;
    }
    continue;
  }

  if(is_null($matched_album)) return false;

  $spotify_album = array();
  $spotify_album['href'] = $matched_album->href;
  $spotify_album['artist_href'] = null;

  // Find a matching artist's href
  if(count($matched_album->artists) > 0)
  {
    foreach($matched_album->artists as $artist) {
      similar_text($artist_name, $artist->name, $match_in_percentage);
      if($match_in_percentage > $artist_match_percentage && isset($artist->href)) {
        $spotify_album['artist_href'] = $artist->href;
        break;
      }
      continue;
    }
  }

  return $spotify_album;
}
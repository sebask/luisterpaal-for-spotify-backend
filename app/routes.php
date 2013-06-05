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
    return Cache::get('albums');
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
  foreach ($luisterpaal->xpath('/luisterpaal/albums/album') as $album) {
    $spotify_result = get_spotify_results_for_album($album->find('title')->first()->text());

    if($spotify_result != false)
    {
      $albums[] = $spotify_result;
    }
  }

  // Check if albums array has anything inside
  if(count($albums) == 0)
  {
    App::abort(400, 'No albums found');
  }

  // Cache albums forever
  Cache::forever('albums', json_encode($albums));

  // Remember the luisterpaal_id for at least a day
  Cache::put('luisterpaal_id', $luisterpaal->find('luisterpaal')->attr('id'), 1440);

  return $albums;
}

function get_spotify_results_for_album($album_title = null)
{
  if(empty($album_title))
  {
    Log::warning('No album title provided for get_spotify_results_for_album()');
    return false;
  }

  // Make the request to spotify
  $spotify_query = file_get_contents('http://ws.spotify.com/search/1/album.json?q='.urlencode($album_title));
  if($spotify_query == false)
  {
    Log::warning('Could not connect to Spotify API for album: ' . $album_title);
    return false;
  }

  // Json decode the results
  $spotify_result = json_decode($spotify_query);
  if( ! $spotify_result OR ! isset($spotify_result->albums) OR count($spotify_result->albums) == 0)
  {
    Log::notice('Spotify API did not return any results for album: ' . $album_title);
    return false;
  }

  $first_album = $spotify_result->albums[0];

  $spotify_album = new stdClass();
  $spotify_album->name = $first_album->name;
  $spotify_album->href = $first_album->href;

  // Return the album early if it doesn't even have an artist.
  if(count($first_album->artists) < 1)
  {
    return $spotify_album;
  }

  if( isset($first_album->artists[0]->name) )
  {
    $spotify_album->artist_name = $first_album->artists[0]->name;
  }

  if( isset($first_album->artists[0]->href) )
  {
    $spotify_album->artist_href = $first_album->artists[0]->href;
  }

  return $spotify_album;
}
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

Route::get('/', function()
{
  // Serve the cache if it exists;
  if (Cache::has('albums')) return Response::json(Cache::get('albums'));

  $url = "http://3voor12.vpro.nl/mobiel/luisterpaal/iphone/";
  $albums = array();

  $qp = qp($url);
  foreach ($qp->xpath('/luisterpaal/albums/album') as $album) {
    $spotify_result = get_spotify_results_for_album($album->find('title')->first()->text());

    if($spotify_result != false) {
      $albums[] = $spotify_result;
    }
  }

  // Check if albums array has anything inside
  if(count($albums) == 0) App::abort(400, 'No albums');

  Cache::put('albums', $albums, 10);

  return Response::json($albums);
});

Route::get('/clear_cache', function()
{
  Cache::forget('albums');
  return "done!";
});

function get_spotify_results_for_album($album_title = null) {

  if(empty($album_title)) {
    return false;
  }

  // Make the request to spotify
  $spotify_query = file_get_contents('http://ws.spotify.com/search/1/album.json?q='.urlencode($album_title));
  if($spotify_query == false) {
    return false;
    // App::abort(400, 'Could not connect to Spotify API');
  }

  // Json decode the results
  $spotify_result = json_decode($spotify_query);
  if( ! $spotify_result OR ! isset($spotify_result->albums) OR count($spotify_result->albums) == 0) {
    return false;
    // App::abort(404, 'Spotify API did not return any results');
  }

  $first_album = $spotify_result->albums[0];

  $spotify_album = new stdClass();
  $spotify_album->name = $first_album->name;
  $spotify_album->href = $first_album->href;

  if(count($first_album->artists) < 1) return $spotify_album;

  if( isset($first_album->artists[0]->name) ){
    $spotify_album->artist_name = $first_album->artists[0]->name;
  }

  if( isset($first_album->artists[0]->href) ){
    $spotify_album->artist_href = $first_album->artists[0]->href;
  }

  return $spotify_album;
}
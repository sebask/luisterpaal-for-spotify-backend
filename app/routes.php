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
  $url = "http://3voor12.vpro.nl/mobiel/luisterpaal/iphone/";
  // $gp = qp($url, 'albums title');

  $albums = array();

  $qp = qp($url);
  foreach ($qp->xpath('/luisterpaal/albums/album') as $album) {
      $a_album = new stdClass();
      $a_album->title   = $album->find('title')->first()->text();
      $a_album->artist  = $album->find('artist')->text();
      $a_album->label   = $album->find('label')->text();
      $a_album->info    = $album->find('info')->text();
      $albums[] = $a_album;
  }

  echo json_encode($albums);

});
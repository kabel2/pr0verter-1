@extends('layouts.app')
@section('content')
    <div class="container text-center">
        <div class="jumbotron">
            <div class="row">
                <h1>FAQ</h1>
            </div>
        </div>
    </div>
    <div class="container col-md-5 col-md-offset-4 row">
        <div class="panel panel-default">
            <div class="panel-heading">
                1.) Welche Formate werden Supportet?
            </div>
            <div class="panel-body">
                Zurzeit werden die Formate: .webm, .mp4, .mkv, .mov, .avi, .wmv, .flv, .3gp Supportet!
            </div>
        </div>
        <div class="panel panel-default">
            <div class="panel-heading">
                2.) Weshalb ist mein Video viel kleiner als die angegebene Größe?
            </div>
            <div class="panel-body">
                Das Video wird bei einer zu hohen Bitrate vom pr0gramm nochmals konvertiert.
            </div>
        </div>
    </div>
@endsection
<?php

namespace App\Http\Controllers;


use App\Jobs\ConvertVideo;
use Ixudra\Curl\Facades\Curl;
use App\Http\Requests\CanDelete;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Input;
use App\Http\Requests\AskForDuration;
use Illuminate\Support\Facades\Request;
use App\Http\Requests\UploadFileToConvert;
use App\helpers\VideoStream;



class ConverterController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('throttle:200,10');
    }

    /**
     * Upload Handling Method - Redirects to Front or Progress Page
     *
     * @param UploadFileToConvert $request
     * @return $this|string
     */
    public function upload(UploadFileToConvert $request)
    {
        $saveLocation             = storage_path().'/app';
        $rndName                  = str_random(64);
        $requestSound             = $request->input('sound', 0);
        $requestAutoResolution    = $request->input('autoResolution', 'off');
        $requestLimit             = $request->input('limit', 6);
        $requestURL               = $request->input('url');
        $requestFile              = $request->file('file');

        if($requestLimit > 30)
            $requestLimit = 30;
        if($requestLimit < 1)
            $requestLimit = 1;

        if($requestSound > 3)
            $requestSound = 3;
        if($requestSound < 0)
            $requestSound = 0;

        if($requestAutoResolution === 'on')
            $requestAutoResolution = true;
        else
            $requestAutoResolution = false;

        if($requestFile) {
            $extension = '.'.Input::file('file')->getClientOriginalExtension();
            Input::file('file')->move($saveLocation, $rndName);
            $this->saveToDB($rndName, $extension);
            dispatch((new ConvertVideo($saveLocation, $rndName, $requestSound, $requestAutoResolution, $requestLimit))->onQueue('convert'));
            $data = ['sucess' => true, 'guid' => $rndName];
            echo json_encode($data);

        }
        elseif ($requestURL) {
            $extension = $this->getExtension($requestURL);
            Curl::to($requestURL)->download($saveLocation.'/'.$rndName);
            $this->saveToDB($rndName, $extension);
            dispatch((new ConvertVideo($saveLocation, $rndName, $requestSound, $requestAutoResolution, $requestLimit))->onQueue('convert'));
            echo $rndName;
        }
        else
            return back()->withInput();


    }

    /**
     * @param $guid
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function progress($guid)
    {
        if(DB::table('data')->where([['guid', '=', $guid], ['deleted', '=', 0]])->value('guid') == $guid)
            return view('converter.progress', ['guid' => $guid]);
        else
            return view('error.404');
    }

    /**
     * @param $guid
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function show($guid)
    {
        if(DB::table('data')->where([['guid', '=', $guid], ['deleted', '=', 0]])->value('guid') == $guid)
            return view('converter.show', ['view' => url('view').'/'.$guid, 'download' => url('download').'/'.$guid]);
        else
            return view('error.404');
    }

    /**
     * @param $guid
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function view($guid)
    {
        if(DB::table('data')->where([['guid', '=', $guid], ['deleted', '=', 0]])->value('guid') == $guid)
        {
            $video_path = storage_path().'/app/public/'.$guid.'.mp4';
            $stream = new VideoStream($video_path);
            $stream->start();
        }
        else
            return view('error.404');
    }

    /**
     * @param $guid
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function download($guid)
    {
        if(DB::table('data')->where([['guid', '=', $guid], ['deleted', '=', 0]])->value('guid') == $guid)
        {
            echo header("Content-Type: video/mp4");
            echo header("Content-Length: ".filesize(storage_path().'/app/public/'.$guid.'.mp4'));
            echo header("Content-Disposition:attachment;filename='$guid.mp4'");
            echo readfile(storage_path().'/app/public/'.$guid.'.mp4');
        }
        else
            return view('error.404');
    }

    /**
     * @param AskForDuration $request
     * @return string
     */
    public function duration(AskForDuration $request)
    {
        $guid = $request->input('file_name');
        if(DB::table('data')->where([['guid', '=', $guid], ['deleted', '=', 0]])->value('guid') == $guid)
        {
            return DB::table('data')->where('guid', $guid)->value('progress');
        }
        else
            return 'error';
    }

    /**
     * @param AskForDuration $request
     * @return string
     */
    public function delete(CanDelete $request)
    {
        $guid = $request->input('guid');
        if(DB::table('data')->where('guid', '=', $guid)->value('user_id') == Auth::id() || DB::table('users')->where('id', '=', Auth::id())->value('flag') == 1)
        if(DB::table('data')->where([['guid', '=', $guid], ['deleted', '=', 0]])->value('guid') == $guid)
        {
            DB::table('data')->where('guid', '=', $guid)->update(['deleted' => 1]);
            return redirect()->back();
        }
        else
            return redirect()->back();
    }

    /**
     * Return the extension of a given remote file
     *
     * @return string
     */
    private function getExtension($url)
    {
        $name = explode(".", $url);
        $elementCount = count($name);
        return '.'.$name[$elementCount - 1];
    }

    /**
     * Save validated data to DB
     *
     * @param $name
     * @param $ext
     *
     * @return void
     */
    private function saveToDB($name, $ext)
    {
        Auth::guest() ? $userID = 0 : $userID = Auth::id();
        DB::table('data')->insert([[
            'guid' => $name,
            'user_id' => $userID,
            'uploader_ip' => Request::ip(),
            'origEnding' => $ext,
            'created_at' => date("Y-m-d H:i:s"),
            'updated_at' => date("Y-m-d H:i:s")
        ]]);
    }

}
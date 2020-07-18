<?php

namespace App\Jobs;

use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Format\Video\X264;
use Illuminate\Bus\Queueable;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Coordinate\Dimension;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ConvertVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var array
     */
    private $filters;
    /**
     * @var int
     */
    private $maxDuration;

    /**
     * @var float
     */
    private $duration;
    /**
     * @var int
     */
    private $status;

    /**
     * @var array
     */
    private $params;

    /**
     * @var string
     */
    private $loc;

    /**
     * @var string
     */
    private $name;

    /**
     * @var bool
     */
    private $sound;

    /**
     * @var bool
     */
    private $res;

    /**
     * @var bool
     */
    private $limit;

    /**
     * @var float
     */
    private $px;

    /**
     * @var float
     */
    private $py;

    /**
     * @var
     */
    private $start;

    /**
     * @var
     */
    private $end;

    private $maxBitrate;

    /**
     * Create a new job instance.
     *
     * @param $loc
     * @param $name
     * @param $sound
     * @param $res
     * @param $limit
     * @return void
     */
    public function __construct($loc, $name, $sound, $res, $limit, $start, $end)
    {
        $this->loc = $loc;
        $this->name = $name;
        $this->sound = $sound;
        $this->res = $res;
        $this->limit = $limit;
        $this->start = $start;
        $this->end = $end;

        $this->maxDuration = env('VIDEO_MAX_DURATION_IN_SECONDS', 179);

        $this->params = [
            'ffmpeg.binaries' => env('FFMPEG_BIN', '/usr/local/bin/ffmpeg'),
            'ffmpeg.threads' => env('FFMPEG_THREADS', 4),
            'ffprobe.binaries' => env('FFMPEG_PROBE_BIN', '/usr/local/bin/ffprobe'),
            'timeout' => env('FFMPEG_TIMEOUT', 3600),];

        $this->filters = [
            '-profile:v', 'main',
            '-level', '4.0',
            '-preset', 'fast',
            '-fs', $this->limit * 8192 . 'k',
            '-movflags', '+faststart',
        ];
    }

    /**
     * Execute the job.
     *
     * converts video
     * short description of parameters
     * -t: set max video length
     * -profile:v baseline -level 4.0: pr0gramm only supports main lv 4.0
     * -preset: sets conversion speed
     * -fs: ffmpeg cuts on this size
     *
     * @return void
     */
    public function handle($guessMaxBitrate = false)
    {
        $ffprobe = FFProbe::create($this->params);
        $ffmpeg = FFMpeg::create($this->params);

        if ($this->isGif()) {
            $this->duration = $this->getGIFDuration();
            $this->sound = 0;

            $this->filters[] = '-pix_fmt';
            $this->filters[] = 'yuv420p';
        } else {
            $this->duration = (float)$ffprobe->format($this->loc . '/' . $this->name)->get('duration');
        }

        $this->px = $ffprobe->streams($this->loc . '/' . $this->name)->videos()->first()->getDimensions()->getWidth();
        $this->py = $ffprobe->streams($this->loc . '/' . $this->name)->videos()->first()->getDimensions()->getHeight();

        if ($this->duration == 0 || $this->px == 0) {
            failed();
            return;
        }

        $video = $ffmpeg->open($this->loc . '/' . $this->name);

        if (!$this->res) {
            $this->getAutoResolution();
            $video->filters()->resize(new Dimension($this->px, $this->py));
        }

        if ($this->start > $this->duration) {
            $this->start = 0;
        }

        if ($this->end > $this->duration) {
            $this->end = $this->duration;
        }

        if (($this->end - $this->start) > $this->maxDuration) {
            $this->end = $this->start + $this->maxDuration;
        }

        if ($this->start || $this->end) {
            $this->duration = $this->end - $this->start;
        }

        if (!$this->start && !$this->end) {
            $video->filters()->clip(TimeCode::fromSeconds($this->start), TimeCode::fromSeconds($this->maxDuration));
        }

        if ($this->start || $this->end) {
            $video->filters()->clip(TimeCode::fromSeconds($this->start), TimeCode::fromSeconds($this->duration));
        }

        $format = new X264();
        $format->setAudioCodec('aac');
        switch ($this->sound) {
            case 0:
                $this->filters[] = '-an';
                break;
            case 1:
                $format->setAudioKiloBitrate(70); // test value
                break;
            case 2:
                $format->setAudioKiloBitrate(120);
                break;
            case 3:
                $format->setAudioKiloBitrate(190); // test value
                break;
        }

        if (!$guessMaxBitrate) {
            $this->setMaxBitrate();
        }

        $addParams = $this->filters;
        if ($guessMaxBitrate) {
            array_push($addParams, '-crf', '27');
            $format->setAudioKiloBitrate(96); // test value
        }
        $format->setAdditionalParameters($addParams);

        if (!$guessMaxBitrate) {
            $format->setPasses(2);
            $taa = $this->getBitrate($format->getAudioKiloBitrate(), $this->maxBitrate);
            $format->setKiloBitrate($taa);
        }

        if ($guessMaxBitrate) {
            $format->on('progress', function ($video, $format, $percentage) {
                DB::table('data')->where('guid', $this->name)->update(['progress' => $percentage * 0.5]);
            });
        } else {
            $format->on('progress', function ($video, $format, $percentage) {
                DB::table('data')->where('guid', $this->name)->update(['progress' => ($percentage * 0.5) + 50]);
            });
        }

        $basePath = $this->loc . '/public/' . $this->name;

        if ($guessMaxBitrate) {
            $basePath = $basePath . '.prerun';
        }
        $status = false;
        try {
            $status = $video->save($format, $basePath . '.mp4');
            if (!$guessMaxBitrate) {
                DB::table('data')->where('guid', $this->name)->update(['progress' => 100]);
            }
        } catch (\Exception $e) {
            error_log($e);
            $this->failed();
            return;
        }

        if ($status && $guessMaxBitrate) {
            $this->maxBitrate = $ffprobe->streams($this->loc . '/public/' . $this->name . '.prerun' . '.mp4')->videos()->first()->get('bit_rate'); // there are videos which have no bit_rate information
            if($this->maxBitrate != 0) {
                $this->maxBitrate /= 1024;
            }
            if ($this->maxBitrate == 0) {

                $commands = array($this->loc . '/' . $this->name, '-select_streams', 'v', '-show_entries', 'packet=size:stream=duration', '-of', 'compact=p=0:nk=1'); // this is not how to do this...

                $b = explode("\n", $ffprobe->getFFProbeDriver()->command($commands));
                array_pop($b);
                $popped2 = array_pop($b);

                $a = array_sum($b);

                $this->maxBitrate = $a / (float)$popped2;
            }

        }
        if (!$status) {
            error_log("failed");
            $this->failed();
        }

    }

    // pr0gramm converts video with crf 28, preset medium
    // we should be under this bitrate to prevent pr0gramm converting it again (at crf 28)
    private function setMaxBitrate()
    {
        $this->handle(true);
    }

    private function getBitrate($audioBitrate, $maxBitrate)
    {
        $this->duration = min($this->duration, $this->maxDuration);

        $bitrate = ($this->limit * 8000) / (float)$this->duration;

        if ($bitrate > $maxBitrate) {
            return $maxBitrate;
        }

        !$this->sound ?: $bitrate -= $audioBitrate;

        return $bitrate;
    }

    /*
     * 0-50 seconds max 1024p
     * 50-120 seconds max 720p
     * 120-300 seconds max 480p
    */
    private function getAutoResolution()
    {
        $longest_side = max($this->px, $this->py);
        $duration = $this->duration;
        $ratio = $this->px / $this->py;

        $new_size = $longest_side;

        if ($longest_side > 1052) {
            $new_size = 1052;
        }

        if ($duration > 50 && $duration < 150 && $longest_side > 720) {
            $new_size = 720;
        }

        if ($duration > 150 && $longest_side > 480) {
            $new_size = 480;
        }

        $new_size = round($new_size);

        if ($this->px > $this->py) {
            $this->px = $new_size;
            $this->py = $new_size / $ratio;
        } else {
            $this->py = $new_size;
            $this->px = $new_size * $ratio;
        }

        // resolution has to be even
        if ($this->px % 2 != 0) {
            $this->px++;
        }
        if ($this->py % 2 != 0) {
            $this->py++;
        }
    }

    private function getGIFDuration()
    {
        $gif_graphic_control_extension = '/21f904[0-9a-f]{2}([0-9a-f]{4})[0-9a-f]{2}00/';
        $file = file_get_contents($this->loc . '/' . $this->name);
        $file = bin2hex($file);

        $total_delay = 0;
        preg_match_all($gif_graphic_control_extension, $file, $matches);
        foreach ($matches[1] as $match) {
            $delay = hexdec(substr($match, -2) . substr($match, 0, 2));
            if ($delay == 0) {
                $delay = 1;
            }
            $total_delay += $delay;
        }

        $total_delay /= 100;

        return $total_delay;
    }

    private function isGif()
    {
        if (strtolower(DB::table('data')->where('guid', $this->name)->value('origEnding')) === '.gif') {
            return true;
        } else {
            return false;
        }
    }

    public function failed()
    {
        DB::table('data')->where('guid', $this->name)->update(['progress' => 420]);
    }
}

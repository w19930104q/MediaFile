<?php
namespace wapmorgan\MediaFile;

use wapmorgan\MediaFile\FormatDetector\DetectionReport;
use wapmorgan\MediaFile\FormatDetector\FormatDetector;
use wapmorgan\MediaFile\FormatDetector\UnknownFormatException;
use wapmorgan\MediaFile\Adapters\AudioAdapter;
use wapmorgan\MediaFile\Adapters\VideoAdapter;
use wapmorgan\MediaFile\Exceptions\FileAccessException;

class MediaFile {
    const AUDIO = 'audio';
    const VIDEO = 'video';

    static protected $formatHandlers = [
        FormatDetector::WAV => Adapters\Audio\WavAdapter::class,
        FormatDetector::MP3 => Adapters\Audio\Mp3Adapter::class,
        FormatDetector::FLAC => Adapters\Audio\FlacAdapter::class,
        FormatDetector::AAC => Adapters\Audio\AacAdapter::class,
        FormatDetector::OGG => Adapters\Audio\OggAdapter::class,
        FormatDetector::AMR => Adapters\Audio\AmrAdapter::class,
        FormatDetector::WMA => Adapters\Audio\WmaAdapter::class,
        FormatDetector::AVI => Adapters\Video\AviAdapter::class,
        FormatDetector::ASF => Adapters\Containers\AsfAdapter::class,
        FormatDetector::WMV => Adapters\Video\WmvAdapter::class,
        FormatDetector::MP4 => Adapters\Video\Mp4Adapter::class,
        FormatDetector::MKV => Adapters\Video\MkvAdapter::class,
    ];

    /** @var DetectionReport|null Explainable result of the last detection. */
    static protected $lastReport;

    /** @var string */
    protected $filename;
    protected $type;

    /** @var string */
    protected $format;

    /** @var AudioAdapter|VideoAdapter */
    public $adapter;

    /**
     * Opens a media file.
     *
     * Detection goes through the single FormatDetector pipeline (scored
     * content probes on a 32/64-byte head, then the extension fallback), so
     * probe scores, cache keys and the fallback path all live in one place.
     *
     * @param string $filename
     * @return MediaFile
     * @throws FileAccessException
     * @throws UnknownFormatException Carries an explainable DetectionReport.
     */
    static public function open($filename)
    {
        if (!file_exists($filename) || !is_readable($filename))
            throw new FileAccessException('File "'.$filename.'" is not available for reading!');

        $report = FormatDetector::detectFile($filename);
        self::$lastReport = $report;

        if (!$report->hasWinner())
            throw new UnknownFormatException($report);

        $format = $report->getFormat();
        if (!isset(self::$formatHandlers[$format]))
            throw new UnknownFormatException($report);

        return new self($filename, $format);
    }

    /**
     * Runs the unified detector without instantiating an adapter. Useful for
     * tooling that only needs the format, confidence, score and timing.
     *
     * @param string $filename
     * @return DetectionReport
     */
    static public function inspect($filename)
    {
        $report = FormatDetector::detectFile($filename);
        self::$lastReport = $report;
        return $report;
    }

    /** @return DetectionReport|null Report from the last open()/inspect(). */
    static public function getLastDetectionReport()
    {
        return self::$lastReport;
    }

    /**
     * MediaFile constructor.
     *
     * @param string $filename
     * @param string $format
     * @throws FileAccessException
     */
    public function __construct($filename, $format) {
        if (!file_exists($filename) || !is_readable($filename)) throw new Exceptions\FileAccessException('File "'.$filename.'" is not available for reading!');

        if (!isset(self::$formatHandlers[$format]))
            throw new FileAccessException('Format "'.$format.'" does not have a handler!');

        $adapter_class = self::$formatHandlers[$format];
        $this->adapter = new $adapter_class($filename);

        $this->filename = $filename;
        $this->format = $format;
    }

    /**
     * @return bool
     */
    public function isAudio() {
        return $this->adapter instanceof AudioAdapter;
    }

    /**
     * @return bool
     */
    public function isVideo() {
        return $this->adapter instanceof VideoAdapter;
    }

    /**
     * @return bool
     */
    public function isContainer() {
        return $this->adapter instanceof Adapters\ContainerAdapter;
    }

    /**
     * @return string
     */
    public function getFormat() {
        return $this->format;
    }

    /**
     * @return AudioAdapter
     */
    public function getAudio() {
        return $this->adapter;
    }

    /**
     * @return VideoAdapter
     * @throws FileAccessException
     */
    public function getVideo() {
        return $this->adapter;
    }
}

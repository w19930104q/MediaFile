<?php
namespace wapmorgan\MediaFile\FormatDetector;

/**
 * One probe hit: how well a fixed-size file head matches one format/adapter.
 *
 * The score is a single 0-100 number shared by every probe (see FormatDetector),
 * so candidates produced by different signatures can be ranked against each
 * other and so the cache key / fallback path can rely on one scale.
 */
class ProbeResult
{
    /** Format identifier, one of FormatDetector format constants. */
    public $format;

    /** Adapter class that would handle the format. */
    public $adapter;

    /** One of FormatDetector::AUDIO / FormatDetector::VIDEO. */
    public $type;

    /** 0-100. Below FormatDetector::THRESHOLD the hit is not trusted. */
    public $score;

    /** Human-readable description of the signature that matched. */
    public $signature;

    /** Byte offset inside the head where the signature matched. */
    public $offset;

    /**
     * Additional, human-readable facts gathered while probing (e.g. an ID3v2
     * tag size, an ftyp major brand). Used to explain a report.
     *
     * @var string[]
     */
    public $notes = array();

    public function __construct($format, $adapter, $type, $score, $signature, $offset = 0, array $notes = array())
    {
        $this->format = $format;
        $this->adapter = $adapter;
        $this->type = $type;
        $this->score = $score;
        $this->signature = $signature;
        $this->offset = $offset;
        $this->notes = $notes;
    }

    /** Verbal confidence bucket derived from the unified score. */
    public function getConfidence()
    {
        if ($this->score >= 90)
            return FormatDetector::CONFIDENCE_HIGH;
        if ($this->score >= 50)
            return FormatDetector::CONFIDENCE_MEDIUM;
        if ($this->score >= FormatDetector::THRESHOLD)
            return FormatDetector::CONFIDENCE_LOW;
        return FormatDetector::CONFIDENCE_NONE;
    }
}

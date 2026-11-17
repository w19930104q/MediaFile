<?php
namespace wapmorgan\MediaFile\FormatDetector;

/**
 * Explainable outcome of a detection run.
 *
 * A report is produced for every input, including unknown ones. It carries:
 *  - the winning probe (if any),
 *  - every candidate probe with its unified score,
 *  - which stage of the fallback path produced the winner (content / extension),
 *  - timing and cache information,
 *  - a human-readable reason when no format could be determined.
 */
class DetectionReport
{
    /** @var ProbeResult|null */
    protected $winner;

    /** @var ProbeResult[] All probe hits, best score first. */
    protected $candidates = array();

    /** How much of the file head was actually available, in bytes. */
    protected $headLength;

    /** Total file size in bytes (false when only a head buffer is given). */
    protected $fileSize;

    /** Unified cache key used for the lookup. */
    protected $cacheKey;

    /** Whether the report was served from the detector cache. */
    protected $fromCache = false;

    /** One of FormatDetector::STAGE_*. */
    protected $stage;

    /** Detection time in milliseconds. */
    protected $timeMs;

    /** Human-readable explanation, always populated for unknown formats. */
    protected $reason;

    public function __construct(array $candidates = array(), $headLength = 0, $fileSize = false,
                                $cacheKey = null, $stage = null, $timeMs = 0.0, $reason = null)
    {
        $this->candidates = $candidates;
        $this->headLength = $headLength;
        $this->fileSize = $fileSize;
        $this->cacheKey = $cacheKey;
        $this->stage = $stage;
        $this->timeMs = $timeMs;
        $this->reason = $reason;
        // Content candidates only win above the threshold; an extension-stage
        // candidate is the deliberate fallback and wins at its low score.
        // Probes without an adapter (e.g. an unsupported ftyp brand) are kept
        // as explainable candidates but never become winners.
        if (!empty($candidates) && $candidates[0]->format !== null
            && ($this->stage === FormatDetector::STAGE_EXTENSION
                || $candidates[0]->score >= FormatDetector::THRESHOLD))
            $this->winner = $candidates[0];
    }

    public function markAsCached($timeMs = null)
    {
        $this->fromCache = true;
        $this->stage = FormatDetector::STAGE_CACHE;
        if ($timeMs !== null)
            $this->timeMs = $timeMs;
        return $this;
    }

    /** @return ProbeResult|null */
    public function getWinner()
    {
        return $this->winner;
    }

    public function hasWinner()
    {
        return $this->winner !== null;
    }

    /** @return ProbeResult[] */
    public function getCandidates()
    {
        return $this->candidates;
    }

    public function getFormat()
    {
        return $this->winner !== null ? $this->winner->format : null;
    }

    public function getAdapter()
    {
        return $this->winner !== null ? $this->winner->adapter : null;
    }

    public function getType()
    {
        return $this->winner !== null ? $this->winner->type : null;
    }

    public function getScore()
    {
        return $this->winner !== null ? $this->winner->score : 0;
    }

    public function getConfidence()
    {
        return $this->winner !== null
            ? $this->winner->getConfidence()
            : FormatDetector::CONFIDENCE_NONE;
    }

    public function getHeadLength()
    {
        return $this->headLength;
    }

    public function getFileSize()
    {
        return $this->fileSize;
    }

    public function getCacheKey()
    {
        return $this->cacheKey;
    }

    public function isFromCache()
    {
        return $this->fromCache;
    }

    public function getStage()
    {
        return $this->stage;
    }

    public function getTimeMs()
    {
        return $this->timeMs;
    }

    public function getReason()
    {
        return $this->reason;
    }

    /**
     * One-line, human-readable summary. Used by UnknownFormatException and by
     * the head-matrix CLI so unknown results stay explainable.
     */
    public function explain()
    {
        if ($this->winner !== null) {
            return sprintf('detected "%s" via %s (score %d, %s confidence, %.3f ms)',
                $this->winner->format,
                $this->stage,
                $this->winner->score,
                $this->winner->getConfidence(),
                $this->timeMs);
        }

        $details = array();
        if ($this->headLength < FormatDetector::HEAD_FULL)
            $details[] = 'only '.$this->headLength.' byte(s) of head available';
        if (!empty($this->candidates)) {
            $weak = array();
            foreach ($this->candidates as $candidate)
                $weak[] = ($candidate->format !== null ? $candidate->format : 'unmapped')
                    .'='.$candidate->score;
            $details[] = 'all probes below threshold ('.implode(', ', $weak).')';
            if ($this->candidates[0]->format === null)
                $details[] = $this->candidates[0]->signature;
        } else {
            $details[] = 'no known content signature matched';
        }

        return 'unknown format: '.implode('; ', $details)
            .($this->reason !== null ? ' — '.$this->reason : '');
    }
}

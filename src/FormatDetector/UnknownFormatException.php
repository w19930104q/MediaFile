<?php
namespace wapmorgan\MediaFile\FormatDetector;

use wapmorgan\MediaFile\Exceptions\FileAccessException;

/**
 * Thrown when neither the content probes nor the extension fallback can
 * determine a supported format.
 *
 * Extends FileAccessException so existing callers that catch
 * FileAccessException keep working, while getReport() exposes the full,
 * explainable DetectionReport (all candidates, scores and the reason).
 */
class UnknownFormatException extends FileAccessException
{
    /** @var DetectionReport */
    protected $report;

    public function __construct(DetectionReport $report)
    {
        $this->report = $report;
        parent::__construct($report->explain());
    }

    /** @return DetectionReport */
    public function getReport()
    {
        return $this->report;
    }
}

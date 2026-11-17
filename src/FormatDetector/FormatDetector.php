<?php
namespace wapmorgan\MediaFile\FormatDetector;

use wapmorgan\MediaFile\Adapters\Audio\AacAdapter;
use wapmorgan\MediaFile\Adapters\Audio\AmrAdapter;
use wapmorgan\MediaFile\Adapters\Audio\FlacAdapter;
use wapmorgan\MediaFile\Adapters\Audio\Mp3Adapter;
use wapmorgan\MediaFile\Adapters\Audio\OggAdapter;
use wapmorgan\MediaFile\Adapters\Audio\WavAdapter;
use wapmorgan\MediaFile\Adapters\Audio\WmaAdapter;
use wapmorgan\MediaFile\Adapters\Containers\AsfAdapter;
use wapmorgan\MediaFile\Adapters\Video\AviAdapter;
use wapmorgan\MediaFile\Adapters\Video\MkvAdapter;
use wapmorgan\MediaFile\Adapters\Video\Mp4Adapter;
use wapmorgan\MediaFile\Adapters\Video\WmvAdapter;

/**
 * Scores a fixed-size file head (32 or 64 bytes) against every supported
 * container / audio coding signature and picks one adapter.
 *
 * Design goals (see repository task "unify probe scores, cache key and
 * fallback path"):
 *
 *  - Every probe reports on the SAME 0-100 scale, so the winner is simply the
 *    highest-scoring candidate instead of a first-match-wins signature walk.
 *  - Structured prefixes are understood, not treated as opaque bytes: ID3v2
 *    tags are skipped to reach the MPEG frame, and ISO BMFF free/skip boxes
 *    are walked to reach a late ftyp box.
 *  - One cache with one key scheme is shared by file paths and raw heads.
 *  - There is exactly one fallback path: content probes first, filename
 *    extension second (low score, labelled confidence).
 *  - Unknown inputs still produce a DetectionReport with all candidates and a
 *    human-readable reason; nothing fails with a generic message.
 *
 * The class is self-contained (no external detector dependency) and only
 * reads the first HEAD_FULL bytes of a file.
 */
class FormatDetector
{
    /** Head sizes used by the detection matrix. */
    const HEAD_SHORT = 32;
    const HEAD_FULL = 64;

    /** Minimum score for a content probe to be trusted. */
    const THRESHOLD = 25;

    const AUDIO = 'audio';
    const VIDEO = 'video';

    const STAGE_CACHE = 'cache';
    const STAGE_CONTENT = 'content';
    const STAGE_EXTENSION = 'extension';

    const CONFIDENCE_HIGH = 'high';
    const CONFIDENCE_MEDIUM = 'medium';
    const CONFIDENCE_LOW = 'low';
    const CONFIDENCE_NONE = 'none';

    // Supported format identifiers (also returned by getFormat()).
    const WAV = 'wav';
    const MP3 = 'mp3';
    const FLAC = 'flac';
    const AAC = 'aac';
    const OGG = 'ogg';
    const AMR = 'amr';
    const WMA = 'wma';
    const AVI = 'avi';
    const ASF = 'asf';
    const WMV = 'wmv';
    const MP4 = 'mp4';
    const MKV = 'mkv';

    /** Format => [adapter class, media type]. */
    protected static $adapters = array(
        self::WAV => array(WavAdapter::class, self::AUDIO),
        self::MP3 => array(Mp3Adapter::class, self::AUDIO),
        self::FLAC => array(FlacAdapter::class, self::AUDIO),
        self::AAC => array(AacAdapter::class, self::AUDIO),
        self::OGG => array(OggAdapter::class, self::AUDIO),
        self::AMR => array(AmrAdapter::class, self::AUDIO),
        self::WMA => array(WmaAdapter::class, self::AUDIO),
        self::AVI => array(AviAdapter::class, self::VIDEO),
        self::ASF => array(AsfAdapter::class, self::VIDEO),
        self::WMV => array(WmvAdapter::class, self::VIDEO),
        self::MP4 => array(Mp4Adapter::class, self::VIDEO),
        self::MKV => array(MkvAdapter::class, self::VIDEO),
    );

    /**
     * Extension fallback. Extensions only win when no content probe reaches
     * THRESHOLD, and their score is intentionally low.
     */
    protected static $extensions = array(
        'wav' => self::WAV,
        'mp3' => self::MP3,
        'flac' => self::FLAC,
        'aac' => self::AAC,
        'm4a' => self::AAC,
        'ogg' => self::OGG,
        'oga' => self::OGG,
        'amr' => self::AMR,
        'wma' => self::WMA,
        'avi' => self::AVI,
        'asf' => self::ASF,
        'wmv' => self::WMV,
        'mp4' => self::MP4,
        'm4v' => self::MP4,
        'mov' => self::MP4,
        'mkv' => self::MKV,
        'webm' => self::MKV,
    );

    /** Recognised ISO BMFF major/compatible brands. */
    protected static $mp4Brands = array(
        'isom', 'iso2', 'iso3', 'iso4', 'iso5', 'mp41', 'mp42', 'mp4v',
        'avc1', 'dash', 'msnv', 'M4V ', 'qt  ',
    );
    protected static $aacBrands = array('M4A ', 'M4B ');
    protected static $threeGpBrands = array(
        '3gp4', '3gp5', '3ge4', '3ge5', '3g2a', '3g2b', '3g2c',
    );

    /** ASF header GUID (binary), shared by WMA and WMV containers. */
    const ASF_GUID = "\x30\x26\xb2\x75\x8e\x66\x11\xcf\xa6\xd9\x00\xaa\x00\x62\xce\x6c";

    /** @var DetectionReport[] */
    protected static $cache = array();

    /**
     * Detects a file on disk. Reads only the first HEAD_FULL bytes.
     *
     * @param string $filename
     * @return DetectionReport
     */
    public static function detectFile($filename)
    {
        $key = self::cacheKeyForFile($filename);
        if (isset(self::$cache[$key]))
            return clone self::$cache[$key]->markAsCached();

        $size = filesize($filename);
        $fp = fopen($filename, 'rb');
        $head = fread($fp, self::HEAD_FULL);
        fclose($fp);

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $report = self::buildReport($head, $size, $extension === '' ? null : $extension, $key);
        self::$cache[$key] = $report;
        return $report;
    }

    /**
     * Detects a raw head buffer (used by the 32/64-byte matrix).
     *
     * @param string $head Binary head, at most HEAD_FULL bytes are needed.
     * @param string|null $extension Optional extension for the fallback path.
     * @return DetectionReport
     */
    public static function detectHead($head, $extension = null)
    {
        $key = self::cacheKeyForHead($head, $extension);
        if (isset(self::$cache[$key]))
            return clone self::$cache[$key]->markAsCached();

        $report = self::buildReport($head, strlen($head), $extension, $key);
        self::$cache[$key] = $report;
        return $report;
    }

    /**
     * Convenience alias for detectFile(). Use detectHead() for raw binary
     * buffers, where the file/buffer distinction is unambiguous.
     *
     * @param string $filename
     * @return DetectionReport
     */
    public static function detect($filename)
    {
        return self::detectFile($filename);
    }

    public static function clearCache()
    {
        self::$cache = array();
    }

    /** Unified cache key for a file: identity path plus size/mtime. */
    public static function cacheKeyForFile($filename)
    {
        $real = realpath($filename);
        if ($real === false)
            $real = $filename;
        return 'file:'.md5($real.'|'.filesize($filename).'|'.filemtime($filename));
    }

    /** Unified cache key for a raw head: content hash plus extension. */
    public static function cacheKeyForHead($head, $extension = null)
    {
        return 'head:'.md5($head.'|'.(string)$extension);
    }

    /**
     * Runs every probe, sorts candidates by the unified score, applies the
     * single extension fallback and measures wall-clock time.
     */
    protected static function buildReport($head, $fileSize, $extension, $cacheKey)
    {
        $start = microtime(true);

        $probes = self::runProbes($head);

        // Stable sort: score desc, original probe order wins on ties.
        $indexed = array();
        foreach ($probes as $order => $probe)
            $indexed[] = array($order, $probe);
        usort($indexed, function ($a, $b) {
            if ($a[1]->score === $b[1]->score)
                return $a[0] < $b[0] ? -1 : 1;
            return $a[1]->score < $b[1]->score ? 1 : -1;
        });
        $probes = array();
        foreach ($indexed as $entry)
            $probes[] = $entry[1];

        $stage = self::STAGE_CONTENT;
        $reason = null;
        $winner = null;

        if (!empty($probes) && $probes[0]->score >= self::THRESHOLD) {
            $winner = $probes[0];
            // ASF carries both WMA and WMV: a matching extension disambiguates.
            if ($winner->format === self::ASF && $extension !== null
                && isset(self::$extensions[$extension])
                && in_array(self::$extensions[$extension], array(self::WMA, self::WMV), true)) {
                $remapped = self::makeProbe(self::$extensions[$extension], $winner->score,
                    $winner->signature, $winner->offset, $winner->notes);
                $probes[0] = $remapped;
                $winner = $remapped;
            }
        } else {
            // Sub-threshold probes stay in the candidate list so the unknown
            // report can explain what almost matched; they simply cannot win.
            // Single fallback path: filename extension, clearly labelled.
            if ($extension !== null && isset(self::$extensions[$extension])) {
                $winner = self::makeProbe(self::$extensions[$extension], self::THRESHOLD,
                    'filename extension .'.$extension, 0,
                    array('content probes below threshold; extension fallback used'));
                array_unshift($probes, $winner);
                $stage = self::STAGE_EXTENSION;
            } else {
                $reason = self::describeUnknown($head, $probes, $extension);
            }
        }

        $timeMs = (microtime(true) - $start) * 1000;
        return new DetectionReport($probes, strlen($head), $fileSize, $cacheKey,
            $stage, $timeMs, $reason);
    }

    /** Explains why an input could not be identified. */
    protected static function describeUnknown($head, array $probes, $extension)
    {
        if (strlen($head) === 0)
            return 'empty head (0 bytes)';
        if ($extension !== null && !isset(self::$extensions[$extension]))
            return 'extension .'.$extension.' has no registered adapter';
        if (!empty($probes))
            return 'container fragment matched but no supported adapter';
        return null;
    }
    protected static function makeProbe($format, $score, $signature, $offset = 0, array $notes = array())
    {
        list($adapter, $type) = self::$adapters[$format];
        return new ProbeResult($format, $adapter, $type, $score, $signature, $offset, $notes);
    }

    /*
     * Probe registry. Every probe returns a ProbeResult (even sub-threshold
     * ones, so the report can explain near-misses) or null.
     */

    protected static function runProbes($head)
    {
        $probes = array();
        foreach (array('probeWav', 'probeAvi', 'probeFlac', 'probeOgg', 'probeAmr',
                       'probeAsf', 'probeMatroska', 'probeIsoBmff', 'probeMp3', 'probeAac') as $method) {
            $result = self::$method($head);
            if ($result !== null)
                $probes[] = $result;
        }
        return $probes;
    }

    protected static function bytesAt($head, $offset, $length)
    {
        if ($offset < 0 || strlen($head) < $offset + $length)
            return null;
        return substr($head, $offset, $length);
    }

    protected static function probeWav($head)
    {
        if (self::bytesAt($head, 0, 4) !== 'RIFF')
            return null;
        if (self::bytesAt($head, 8, 4) === 'WAVE')
            return self::makeProbe(self::WAV, 100, 'RIFF....WAVE container magic', 0);
        // RIFF alone is a weak hint, kept explainable but below threshold.
        return new ProbeResult(self::WAV, WavAdapter::class, self::AUDIO, 15,
            'RIFF magic without WAVE form type', 0);
    }

    protected static function probeAvi($head)
    {
        if (self::bytesAt($head, 0, 4) !== 'RIFF')
            return null;
        if (self::bytesAt($head, 8, 8) === 'AVI LIST')
            return self::makeProbe(self::AVI, 100, 'RIFF....AVI LIST container magic', 0);
        return null;
    }

    protected static function probeFlac($head)
    {
        if (self::bytesAt($head, 0, 4) !== 'fLaC')
            return null;
        // Byte 4 is the first metadata block header (STREAMINFO is type 0).
        if (strlen($head) >= 5 && (ord($head[4]) & 0x7F) === 0)
            return self::makeProbe(self::FLAC, 100, 'fLaC magic + STREAMINFO block', 0);
        return self::makeProbe(self::FLAC, 90, 'fLaC magic', 0);
    }

    protected static function probeOgg($head)
    {
        if (self::bytesAt($head, 0, 4) !== 'OggS')
            return null;
        if (strlen($head) >= 5 && ord($head[4]) === 0)
            return self::makeProbe(self::OGG, 100, 'OggS capture pattern, version 0', 0);
        return self::makeProbe(self::OGG, 90, 'OggS capture pattern', 0);
    }

    protected static function probeAmr($head)
    {
        if (self::bytesAt($head, 0, 5) === '#!AMR')
            return self::makeProbe(self::AMR, 100, 'AMR storage magic (#!AMR)', 0);
        return null;
    }

    protected static function probeAsf($head)
    {
        if (self::bytesAt($head, 0, 16) === self::ASF_GUID)
            return self::makeProbe(self::ASF, 100, 'ASF Header Object GUID', 0);
        return null;
    }

    protected static function probeMatroska($head)
    {
        if (self::bytesAt($head, 0, 4) !== "\x1a\x45\xdf\xa3")
            return null;

        // The full 16-byte Matroska signature is the strongest possible hit.
        if (self::bytesAt($head, 0, 16) === "\x1a\x45\xdf\xa3\x93\x42\x82\x88matroska")
            return self::makeProbe(self::MKV, 100, 'EBML head + matroska doctype signature', 0);

        $notes = array();
        $score = 85;
        // EBML heads are small; the DocType element usually fits in 64 bytes.
        if (strpos($head, 'matroska') !== false) {
            $score = 100;
            $notes[] = 'DocType=matroska';
        } elseif (strpos($head, 'webm') !== false) {
            $score = 80;
            $notes[] = 'DocType=webm (handled by Matroska adapter)';
        }
        return self::makeProbe(self::MKV, $score, 'EBML element head (0x1A45DFA3)', 0, $notes);
    }

    /**
     * ISO BMFF / QuickTime: walks leading free/skip boxes so a late ftyp is
     * found instead of mis-detecting the file.
     */
    protected static function probeIsoBmff($head)
    {
        $offset = 0;
        $length = strlen($head);

        for ($hop = 0; $hop < 4; $hop++) {
            if ($length < $offset + 8)
                return null; // truncated before ftyp: explainable no-hit
            $boxSize = self::readUInt32($head, $offset);
            $boxType = substr($head, $offset + 4, 4);

            if ($boxType === 'ftyp')
                return self::probeFtyp($head, $offset, $hop);

            if ($boxType === 'free' || $boxType === 'skip') {
                if ($boxSize < 8)
                    return null;
                $offset += $boxSize;
                continue;
            }

            // Legacy QuickTime files start straight with moov/mdat.
            if ($hop === 0 && ($boxType === 'moov' || $boxType === 'mdat'))
                return self::makeProbe(self::MP4, 70, 'legacy QuickTime "'.$boxType.'" first box', 0);

            return null;
        }
        return null;
    }

    protected static function probeFtyp($head, $offset, $hops)
    {
        $brand = self::bytesAt($head, $offset + 8, 4);
        if ($brand === null)
            return null;
        $notes = array('major brand "'.$brand.'"');
        if ($hops > 0)
            $notes[] = 'ftyp reached after '.$hops.' free/skip box(es)';

        if (in_array($brand, self::$mp4Brands, true))
            return self::makeProbe(self::MP4, 100, 'ftyp box, brand '.$brand, $offset, $notes);
        if (in_array($brand, self::$aacBrands, true))
            return self::makeProbe(self::AAC, 100, 'ftyp box, audio-only brand '.$brand, $offset, $notes);
        if (in_array($brand, self::$threeGpBrands, true)) {
            // 3GPP is ISO BMFF but has no adapter in this library.
            return new ProbeResult(null, null, null, 15,
                'ftyp box, unsupported 3GPP brand '.$brand, $offset, $notes);
        }

        // Unknown brand: keep the fragment visible in the report, below threshold.
        return new ProbeResult(null, null, null, 15,
            'ftyp box with unregistered brand '.$brand, $offset, $notes);
    }

    /**
     * MP3: understands an ID3v2 prefix (skips it to reach the frame) and a
     * bare MPEG audio sync frame.
     */
    protected static function probeMp3($head)
    {
        // ID3v2: "ID3", 2 version bytes, 1 flags byte, 4 synchsafe size bytes.
        if (self::bytesAt($head, 0, 3) === 'ID3' && strlen($head) >= 10) {
            $tagSize = 10
                + (ord($head[6]) << 21) + (ord($head[7]) << 14)
                + (ord($head[8]) << 7) + ord($head[9]);
            $notes = array('ID3v2 tag, '.$tagSize.' byte(s) skipped');

            if (self::isMpegFrame($head, $tagSize))
                return self::makeProbe(self::MP3, 100, 'ID3v2 prefix + valid MPEG frame', 0, $notes);

            // Tag alone is strong evidence; truncated head lowers confidence.
            $score = strlen($head) > $tagSize + 3 ? 60 : 65;
            return self::makeProbe(self::MP3, $score, 'ID3v2 prefix (frame not fully visible)', 0, $notes);
        }

        if (self::isMpegFrame($head, 0)) {
            $short = strlen($head) < 4;
            return self::makeProbe(self::MP3, $short ? 70 : 95,
                'MPEG audio sync frame (layer I/II/III)', 0,
                $short ? array('short head: frame header truncated') : array());
        }
        return null;
    }

    /**
     * Validates an MPEG audio frame header at $offset.
     * Rejects ADTS (layer bits 00) and reserved bitrate/sampling values.
     */
    protected static function isMpegFrame($head, $offset)
    {
        if (strlen($head) < $offset + 4)
            return false;
        $b0 = ord($head[$offset]);
        $b1 = ord($head[$offset + 1]);
        $b2 = ord($head[$offset + 2]);

        if ($b0 !== 0xFF || ($b1 & 0xE0) !== 0xE0)
            return false;

        $layerBits = ($b1 >> 1) & 0x03;
        if ($layerBits === 0) // reserved / ADTS uses layer 00
            return false;

        $bitrateIndex = ($b2 >> 4) & 0x0F;
        if ($bitrateIndex === 0x0 || $bitrateIndex === 0xF)
            return false;

        $samplingIndex = ($b2 >> 2) & 0x03;
        if ($samplingIndex === 0x3) // reserved
            return false;

        return true;
    }

    /**
     * Raw ADTS AAC (.aac): 12 sync bits, ID bit, layer always 00.
     */
    protected static function probeAac($head)
    {
        if (strlen($head) < 2 || ord($head[0]) !== 0xFF)
            return null;
        $b1 = ord($head[1]);
        if (($b1 & 0xF6) !== 0xF0) // 1111 1111 1111 I 00 P
            return null;

        if (strlen($head) < 4)
            return self::makeProbe(self::AAC, 55, 'ADTS sync word (short head)', 0,
                array('short head: only '.strlen($head).' byte(s) available'));

        $samplingIndex = (ord($head[2]) >> 2) & 0x0F;
        if ($samplingIndex >= 13 && $samplingIndex !== 15)
            return self::makeProbe(self::AAC, 60, 'ADTS sync word, reserved sampling index', 0);

        return self::makeProbe(self::AAC, 95, 'ADTS frame header (MPEG-'.((($b1 >> 3) & 1) ? 2 : 4).')', 0);
    }

    protected static function readUInt32($head, $offset)
    {
        return (ord($head[$offset]) << 24) | (ord($head[$offset + 1]) << 16)
            | (ord($head[$offset + 2]) << 8) | ord($head[$offset + 3]);
    }
}

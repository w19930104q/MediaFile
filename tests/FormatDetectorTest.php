<?php
/**
 * Self-contained assertions for the unified FormatDetector.
 *
 * Runs without composer / phpunit (detection does not load any adapter):
 *   php tests/FormatDetectorTest.php
 *
 * Exit code is non-zero on the first failed assertion.
 */

require __DIR__.'/../src/Exceptions/Exception.php';
require __DIR__.'/../src/Exceptions/FileAccessException.php';
require __DIR__.'/../src/MediaFile.php';
require __DIR__.'/../src/FormatDetector/FormatDetector.php';
require __DIR__.'/../src/FormatDetector/ProbeResult.php';
require __DIR__.'/../src/FormatDetector/DetectionReport.php';
require __DIR__.'/../src/FormatDetector/UnknownFormatException.php';

use wapmorgan\MediaFile\FormatDetector\FormatDetector;
use wapmorgan\MediaFile\FormatDetector\UnknownFormatException;

$checks = 0;
$failures = 0;

function expect($condition, $message)
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo '  ok   '.$message.PHP_EOL;
    } else {
        $failures++;
        echo '  FAIL '.$message.PHP_EOL;
    }
}

function head($bytes, $length)
{
    return strlen($bytes) >= $length ? substr($bytes, 0, $length)
        : $bytes.str_repeat("\x00", $length - strlen($bytes));
}

function synchsafe($size)
{
    return chr(($size >> 21) & 0x7f).chr(($size >> 14) & 0x7f)
        .chr(($size >> 7) & 0x7f).chr($size & 0x7f);
}

echo '== Common formats: winner and adapter stay unchanged =='.PHP_EOL;

$cases = array(
    array('wav', "RIFF\x00\x00\x00\x00WAVEfmt ", 'WavAdapter', 'wav'),
    array('flac', "fLaC\x00\x00\x00\x22", 'FlacAdapter', 'flac'),
    array('ogg', "OggS\x00", 'OggAdapter', 'ogg'),
    array('amr', '#!AMR', 'AmrAdapter', 'amr'),
    array('mp3', "\xff\xfb\x90\x00", 'Mp3Adapter', 'mp3'),
    array('aac', "\xff\xf1\x50\x80", 'AacAdapter', 'aac'),
    array('avi', "RIFF\x00\x00\x00\x00AVI LIST", 'AviAdapter', 'avi'),
    array('mkv', "\x1a\x45\xdf\xa3\x93\x42\x82\x88matroska", 'MkvAdapter', 'mkv'),
    array('mp4', "\x00\x00\x00\x18ftypisom", 'Mp4Adapter', 'mp4'),
    array('wma', "\x30\x26\xb2\x75\x8e\x66\x11\xcf\xa6\xd9\x00\xaa\x00\x62\xce\x6c", 'WmaAdapter', 'wma'),
);

foreach ($cases as $case) {
    list($format, $magic, $adapterShort, $extension) = $case;
    foreach (array(32, 64) as $size) {
        // ASF is shared by WMA/WMV: only the extension disambiguates it.
        $report = FormatDetector::detectHead(head($magic, $size), $extension);
        expect($report->getFormat() === $format,
            sprintf('%s detected from %d-byte head (got %s)', $format, $size, var_export($report->getFormat(), true)));
        expect($report->getScore() >= FormatDetector::THRESHOLD && $report->getConfidence() !== FormatDetector::CONFIDENCE_NONE,
            sprintf('%s passes threshold with %s confidence (%d)', $format, $report->getConfidence(), $report->getScore()));
        expect(strpos($report->getAdapter(), $adapterShort) !== false,
            sprintf('%s maps to %s', $format, $adapterShort));
    }
}

echo '== ID3 prefix: tag skipped, frame reached =='.PHP_EOL;

$id3ThenFrame = "ID3\x04\x00\x00".synchsafe(20).str_repeat("\x12", 20)."\xff\xfb\x90\x00";
$report32 = FormatDetector::detectHead(head($id3ThenFrame, 32)); // frame still hidden
$report64 = FormatDetector::detectHead(head($id3ThenFrame, 64)); // frame visible
expect($report32->getFormat() === 'mp3' && $report32->getScore() < 90,
    '32B head inside ID3 tag still resolves mp3 at reduced confidence ('.$report32->getScore().')');
expect($report64->getFormat() === 'mp3' && $report64->getScore() === 100,
    '64B head reaches MPEG frame after ID3 tag and scores 100');

// The old detector could only match the literal "ID3" bytes and nothing else.
$bareFrame = FormatDetector::detectHead(head("\xff\xfb\x90\x00", 64));
expect($bareFrame->getFormat() === 'mp3', 'bare MP3 without ID3 prefix is detected');

echo '== Late MP4 ftyp: free/skip boxes walked =='.PHP_EOL;

$lateFtyp = "\x00\x00\x00\x10free".str_repeat("\x00", 8)
    ."\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isom";
$late = FormatDetector::detectHead(head($lateFtyp, 64));
expect($late->getFormat() === 'mp4' && $late->getScore() === 100,
    'ftyp behind a free box is found (score '.$late->getScore().')');

$twoBoxes = "\x00\x00\x00\x0cskip\x00\x00\x00\x00"
    ."\x00\x00\x00\x0cfree\x00\x00\x00\x00"
    ."\x00\x00\x00\x18ftypmp42";
$two32 = FormatDetector::detectHead(head($twoBoxes, 32)); // ftyp not yet reached
$two64 = FormatDetector::detectHead(head($twoBoxes, 64));
expect($two32->getFormat() !== 'mp4' || $two32->getStage() !== FormatDetector::STAGE_CONTENT,
    '32B head does not fabricate an mp4 before ftyp is reachable');
expect($two64->getFormat() === 'mp4' && $two64->getScore() === 100,
    'ftyp behind two boxes is found at 64B');

$m4a = FormatDetector::detectHead(head("\x00\x00\x00\x1cftypM4A ", 64), 'm4a');
expect($m4a->getFormat() === 'aac' && strpos($m4a->getAdapter(), 'AacAdapter') !== false,
    'M4A audio brand keeps the historical AacAdapter mapping');

echo '== Short samples: reported at reduced confidence instead of wrong adapter =='.PHP_EOL;

$shortAac = FormatDetector::detectHead("\xff\xf1");
expect($shortAac->getFormat() === 'aac' && $shortAac->getConfidence() === FormatDetector::CONFIDENCE_MEDIUM,
    '2-byte ADTS head detected at medium confidence');
$shortMp3 = FormatDetector::detectHead("\xff\xfb\x90");
expect(!$shortMp3->hasWinner(), '3-byte MPEG head does not pick an adapter on its own');
expect($shortMp3->getStage() !== FormatDetector::STAGE_CONTENT
    || $shortMp3->getScore() < FormatDetector::THRESHOLD,
    '3-byte head is never trusted as a content winner');
$shortMp3WithExt = FormatDetector::detectHead("\xff\xfb\x90", 'mp3');
expect($shortMp3WithExt->getFormat() === 'mp3'
    && $shortMp3WithExt->getStage() === FormatDetector::STAGE_EXTENSION,
    '3-byte head with .mp3 extension resolves via the labelled extension fallback');

// ADTS (layer 00) must never be reported as MP3, and MPEG frames never as AAC.
expect(FormatDetector::detectHead(head("\xff\xf1\x50\x80", 64))->getFormat() === 'aac',
    'ADTS FFF1 not confused with MP3');
expect(FormatDetector::detectHead(head("\xff\xf9\x50\x80", 64))->getFormat() === 'aac',
    'ADTS FFF9 not confused with MP3');
expect(FormatDetector::detectHead(head("\xff\xf3\x50\x00", 64))->getFormat() === 'mp3',
    'MPEG-2 layer III FFF3 not confused with AAC');

echo '== Unknown / unsupported: explainable, no opaque failure =='.PHP_EOL;

$random = FormatDetector::detectHead(head("\x12\x34\x56\x78", 64));
expect(!$random->hasWinner() && strpos($random->explain(), 'unknown format') === 0,
    'random bytes produce an explainable unknown report');
expect($random->getConfidence() === FormatDetector::CONFIDENCE_NONE, 'unknown confidence is "none"');

$threeGp = FormatDetector::detectHead(head("\x00\x00\x00\x18ftyp3gp4", 64), '3gp');
expect(!$threeGp->hasWinner(), '3GPP brand is not forced onto an unsupported adapter');
expect(strpos($threeGp->explain(), '3GPP') !== false, 'report names the unsupported 3GPP brand');

$badBrand = FormatDetector::detectHead(head("\x00\x00\x00\x18ftypZZZZ", 64), 'bin');
expect(!$badBrand->hasWinner() && strpos($badBrand->explain(), 'ZZZZ') !== false,
    'unknown ftyp brand stays explainable');

echo '== Single extension fallback path =='.PHP_EOL;

$byExt = FormatDetector::detectHead(head("\x12\x34\x56\x78\x9a\xbc\xde\xf0", 64), 'wav');
expect($byExt->getFormat() === 'wav' && $byExt->getStage() === FormatDetector::STAGE_EXTENSION
    && $byExt->getScore() === FormatDetector::THRESHOLD && $byExt->getConfidence() === FormatDetector::CONFIDENCE_LOW,
    'extension fallback wins only at low score with labelled stage');

$asfWmv = FormatDetector::detectHead(
    head("\x30\x26\xb2\x75\x8e\x66\x11\xcf\xa6\xd9\x00\xaa\x00\x62\xce\x6c", 64), 'wmv');
expect($asfWmv->getFormat() === 'wmv' && strpos($asfWmv->getAdapter(), 'WmvAdapter') !== false,
    'ASF container + .wmv resolves to WmvAdapter');
$asfWma = FormatDetector::detectHead(
    head("\x30\x26\xb2\x75\x8e\x66\x11\xcf\xa6\xd9\x00\xaa\x00\x62\xce\x6c", 64), 'wma');
expect($asfWma->getFormat() === 'wma', 'ASF container + .wma resolves to WmaAdapter');

echo '== Unified cache: one key scheme, hit served from cache =='.PHP_EOL;

FormatDetector::clearCache();
$first = FormatDetector::detectHead(head("\xff\xfb\x90\x00", 64));
expect(!$first->isFromCache(), 'first lookup is uncached');
$second = FormatDetector::detectHead(head("\xff\xfb\x90\x00", 64));
expect($second->isFromCache() && $second->getCacheKey() === $first->getCacheKey(),
    'identical head reuses the same cache key ('.$second->getCacheKey().')');
expect(strpos($second->getCacheKey(), 'head:') === 0, 'raw-head keys use the head: scheme');

$tmp = tempnam(sys_get_temp_dir(), 'mf_');
file_put_contents($tmp, head("\xff\xfb\x90\x00", 64));
$fileReport = FormatDetector::detectFile($tmp);
expect(strpos($fileReport->getCacheKey(), 'file:') === 0, 'file keys use the file: scheme');
FormatDetector::clearCache();
expect(FormatDetector::detectFile($tmp)->isFromCache() === false, 'clearCache() empties both key schemes');
unlink($tmp);

echo '== UnknownFormatException carries the report =='.PHP_EOL;

try {
    FormatDetector::clearCache();
    $tmp = tempnam(sys_get_temp_dir(), 'mf_');
    file_put_contents($tmp, head('plain text, no media here at all', 64));
    $thrown = null;
    try {
        \wapmorgan\MediaFile\MediaFile::open($tmp);
    } catch (UnknownFormatException $e) {
        $thrown = $e;
    }
    expect($thrown !== null && $thrown->getReport() instanceof \wapmorgan\MediaFile\FormatDetector\DetectionReport,
        'MediaFile::open throws UnknownFormatException with attached report');
    expect($thrown instanceof \wapmorgan\MediaFile\Exceptions\FileAccessException,
        'UnknownFormatException stays catchable as FileAccessException (backward compatible)');
    expect(strlen($thrown->getMessage()) > 0, 'exception message is explanatory');
    unlink($tmp);
} catch (Exception $e) {
    expect(false, 'MediaFile integration ('.get_class($e).': '.$e->getMessage().')');
}

echo PHP_EOL.sprintf('%d checks, %d failure(s).', $checks, $failures).PHP_EOL;
exit($failures === 0 ? 0 : 1);

<?php
/**
 * Head-matrix probe runner for FormatDetector.
 *
 * Builds a matrix of fixed-size file heads (32 and 64 bytes) covering common
 * formats plus the three failure-prone cases (short samples, ID3 prefixes,
 * late MP4 ftyp), runs the unified detector and prints, per cell:
 *
 *   hit adapter | format | confidence | score | stage | time (ms)
 *
 * It can also probe real files (their first 64 bytes only):
 *   php bin/head-matrix.php file1.mp3 dir/ ...
 *
 * Flags:
 *   --json      emit one JSON object per row
 *   --repeats=N average timing over N uncached runs (default 20)
 *   --cache     second run is expected to be served from the detector cache
 *
 * The script uses a minimal PSR-4 autoloader, so it works without
 * `composer install` and never loads adapter dependencies (detection only).
 */

require __DIR__.'/../src/Exceptions/Exception.php';
require __DIR__.'/../src/Exceptions/FileAccessException.php';
require __DIR__.'/../src/FormatDetector/FormatDetector.php';
require __DIR__.'/../src/FormatDetector/ProbeResult.php';
require __DIR__.'/../src/FormatDetector/DetectionReport.php';
require __DIR__.'/../src/FormatDetector/UnknownFormatException.php';

// Adapter classes are referenced as class-name strings in FormatDetector, so
// they never need to be loaded for pure detection. Provide lightweight base
// interfaces in case anything is resolved.
spl_autoload_register(function ($class) {
    $prefix = 'wapmorgan\\MediaFile\\';
    if (strpos($class, $prefix) !== 0)
        return;
    $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($path))
        require $path;
});

use wapmorgan\MediaFile\FormatDetector\FormatDetector;

$argv0 = array_shift($argv);
$json = in_array('--json', $argv, true);
$repeats = 20;
$showCache = in_array('--cache', $argv, true);
$paths = array();
foreach ($argv as $arg) {
    if ($arg === '--json' || $arg === '--cache')
        continue;
    if (strpos($arg, '--repeats=') === 0)
        $repeats = max(1, (int)substr($arg, 10));
    else
        $paths[] = $arg;
}

/**
 * Synthetic heads. Each entry is [label, extension|null, binary head].
 * Heads are padded with deterministic bytes and later truncated to exactly
 * 32 and 64 bytes by the runner.
 */
function matrixHeads()
{
    $rows = array();

    // --- Audio -----------------------------------------------------------
    $rows[] = array('wav (RIFF/WAVE)', 'wav', "RIFF\x00\x00\x00\x00WAVEfmt \x10\x00\x00\x00");
    $rows[] = array('flac (fLaC+STREAMINFO)', 'flac', "fLaC\x00\x00\x00\x22".str_repeat("\x00", 24));
    $rows[] = array('ogg (OggS)', 'ogg', "OggS\x00\x02".str_repeat("\x00", 26));
    $rows[] = array('amr (#!AMR)', 'amr', '#!AMR'.str_repeat("\x00", 27));
    $rows[] = array('mp3 bare MPEG1 L3 frame', 'mp3', "\xff\xfb\x90\x00".str_repeat("\x55", 28));
    $rows[] = array('mp3 bare MPEG2 L3 frame', 'mp3', "\xff\xf3\x50\x00".str_repeat("\x55", 28));
    $rows[] = array('aac ADTS MPEG-4', 'aac', "\xff\xf1\x50\x80".str_repeat("\xaa", 28));
    $rows[] = array('aac ADTS MPEG-2 (FFF9)', 'aac', "\xff\xf9\x50\x80".str_repeat("\xaa", 28));
    $rows[] = array('asf/wma (ASF GUID)', 'wma',
        "\x30\x26\xb2\x75\x8e\x66\x11\xcf\xa6\xd9\x00\xaa\x00\x62\xce\x6c".str_repeat("\x01", 16));

    // ID3v2 prefix: 10-byte header declaring a 20-byte tag, then an MP3 frame.
    $id3Size = packSynchsafe(20);
    $rows[] = array('mp3 ID3v2 prefix + frame', 'mp3',
        "ID3\x04\x00\x00".$id3Size.str_repeat("\x12", 20)."\xff\xfb\x90\x00".str_repeat("\x55", 20));
    // Same content but truncated: 32-byte head ends inside the tag (frame hidden).
    $rows[] = array('mp3 long ID3v2, frame past 64B', 'mp3',
        "ID3\x04\x00\x00".packSynchsafe(128).str_repeat("\x12", 54));

    // --- Video / containers ---------------------------------------------
    $rows[] = array('avi (RIFF/AVI LIST)', 'avi', "RIFF\x00\x00\x00\x00AVI LIST".str_repeat("\x00", 22));
    $rows[] = array('mkv (EBML matroska 16B sig)', 'mkv',
        "\x1a\x45\xdf\xa3\x93\x42\x82\x88matroska".str_repeat("\x00", 18));
    $rows[] = array('webm (EBML, DocType webm)', 'webm',
        "\x1a\x45\xdf\xa3\x01\x00\x00\x00\x00\x00\x00\x1f\x42\x82\x88webm\x00".str_repeat("\x00", 13));
    $rows[] = array('mp4 ftyp isom at offset 0', 'mp4',
        "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isom".str_repeat("\x00", 14));
    $rows[] = array('m4a ftyp M4A (audio-only)', 'm4a',
        "\x00\x00\x00\x1cftypM4A \x00\x00\x00\x00M4A mp42".str_repeat("\x00", 12));
    $rows[] = array('mp4 ftyp after free box', 'mp4',
        "\x00\x00\x00\x10free".str_repeat("\x00", 8).
        "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isom".str_repeat("\x00", 10));
    $rows[] = array('mp4 ftyp after two skip/free', 'mp4',
        "\x00\x00\x00\x0cskip\x00\x00\x00\x00".
        "\x00\x00\x00\x0cfree\x00\x00\x00\x00".
        "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42".str_repeat("\x00", 6));
    $rows[] = array('legacy mov (moov first)', 'mov',
        "\x00\x00\x10\x00moov".str_repeat("\x00", 27));
    $rows[] = array('3gp ftyp 3gp4 (no adapter)', '3gp',
        "\x00\x00\x00\x18ftyp3gp4\x00\x00\x00\x003gp4".str_repeat("\x00", 14));
    $rows[] = array('mp4 unknown ftyp brand', 'bin',
        "\x00\x00\x00\x18ftypZZZZ\x00\x00\x00\x00ZZZZ".str_repeat("\x00", 14));

    // --- Short / unknown -------------------------------------------------
    // Fourth element marks deliberately truncated samples: they are never
    // padded, so both matrix columns see the same genuinely short head.
    $rows[] = array('aac ADTS truncated to 2B', 'aac', "\xff\xf1", true);
    $rows[] = array('mp3 frame truncated to 3B', 'mp3', "\xff\xfb\x90", true);
    $rows[] = array('unknown random bytes', null,
        "\x12\x34\x56\x78\x9a\xbc\xde\xf0".str_repeat("\xab", 24));
    $rows[] = array('plain text head', 'txt', 'This is definitely not media. '.str_repeat('x', 10));

    // Pad every regular head so the runner really truncates to a fixed
    // 32/64 bytes; deliberately short samples (4th element true) stay short.
    foreach ($rows as $i => $row) {
        if (!empty($row[3]) || strlen($row[2]) >= FormatDetector::HEAD_FULL)
            continue;
        $rows[$i][2] = $row[2].str_repeat("\x00", FormatDetector::HEAD_FULL - strlen($row[2]));
    }

    return $rows;
}

function packSynchsafe($size)
{
    return chr(($size >> 21) & 0x7f).chr(($size >> 14) & 0x7f)
        .chr(($size >> 7) & 0x7f).chr($size & 0x7f);
}

/** Re-runs detection with a fresh cache and returns the average ms. */
function timeDetection($head, $extension, $repeats)
{
    $total = 0.0;
    $report = null;
    for ($i = 0; $i < $repeats; $i++) {
        FormatDetector::clearCache();
        $start = microtime(true);
        $report = FormatDetector::detectHead($head, $extension);
        $total += (microtime(true) - $start) * 1000;
    }
    return array($report, $total / $repeats);
}

$rows = matrixHeads();

// Append real files when paths are given: only their first 64 bytes are read.
foreach ($paths as $path) {
    $files = is_dir($path)
        ? glob(rtrim($path, '/').'/*')
        : array($path);
    foreach ($files as $file) {
        if (!is_file($file))
            continue;
        $fp = fopen($file, 'rb');
        $head = fread($fp, FormatDetector::HEAD_FULL);
        fclose($fp);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $rows[] = array('file: '.basename($file), $ext === '' ? null : $ext, $head);
    }
}

if (!$json) {
    echo str_repeat('-', 108).PHP_EOL;
    echo sprintf('%-34s %4s %-6s %-28s %-10s %6s %-9s %9s',
        'Sample', 'Head', 'Ext', 'Hit adapter', 'Format', 'Score', 'Conf.', 'ms (avg)').PHP_EOL;
    echo str_repeat('-', 108).PHP_EOL;
}

foreach ($rows as $row) {
    list($label, $extension, $rawHead) = $row;

    foreach (array(FormatDetector::HEAD_SHORT, FormatDetector::HEAD_FULL) as $headSize) {
        // Fix the head at exactly 32 / 64 bytes when the sample is long
        // enough; deliberately short samples stay shorter.
        $head = strlen($rawHead) > $headSize ? substr($rawHead, 0, $headSize) : $rawHead;
        list($report, $avgMs) = timeDetection($head, $extension, $repeats);

        $cached = false;
        if ($showCache) {
            $cachedReport = FormatDetector::detectHead($head, $extension);
            $cached = $cachedReport->isFromCache()
                && $cachedReport->getCacheKey() === $report->getCacheKey();
        }

        $candidates = $report->getCandidates();
        $bestScore = !empty($candidates) ? $candidates[0]->score : 0;

        if ($json) {
            echo json_encode(array(
                'sample' => $label,
                'head_bytes' => strlen($head),
                'extension' => $extension,
                'hit' => $report->hasWinner(),
                'adapter' => $report->getAdapter(),
                'format' => $report->getFormat(),
                'score' => $report->hasWinner() ? $report->getScore() : $bestScore,
                'confidence' => $report->getConfidence(),
                'stage' => $report->getStage(),
                'time_ms_avg' => round($avgMs, 4),
                'cache_key' => $report->getCacheKey(),
                'cache_hit' => $cached,
                'candidates' => array_map(function ($c) {
                    return array('format' => $c->format, 'score' => $c->score,
                        'confidence' => $c->getConfidence(), 'signature' => $c->signature);
                }, $candidates),
                'reason' => $report->hasWinner() ? null : $report->explain(),
            )).PHP_EOL;
            continue;
        }

        $adapterName = $report->getAdapter();
        $adapter = $adapterName !== null
            ? substr(strrchr($adapterName, '\\'), 1)
            : '-';
        echo sprintf("%-34s %4d %-6s %-28s %-10s %6d %-9s %9.4f%s\n",
            substr($label, 0, 34),
            strlen($head),
            $extension === null ? '-' : $extension,
            $adapter,
            $report->getFormat() === null ? 'unknown' : $report->getFormat(),
            $report->hasWinner() ? $report->getScore() : $bestScore,
            $report->getConfidence(),
            $avgMs,
            $showCache ? ($cached ? '  [cache ok]' : '  [CACHE MISS!]') : '');

        if (!$report->hasWinner())
            echo '    ↳ '.$report->explain().PHP_EOL;
    }
}

if (!$json)
    echo str_repeat('-', 108).PHP_EOL;

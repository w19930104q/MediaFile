# MediaFile

Allows you easily get meta information about any media file with unified interface.
The library has no requirements of external libs or system unitilies.

[![Latest Stable Version](https://poser.pugx.org/wapmorgan/media-file/v/stable)](https://packagist.org/packages/wapmorgan/media-file)
[![License](https://poser.pugx.org/wapmorgan/media-file/license)](https://packagist.org/packages/wapmorgan/media-file)
[![Latest Unstable Version](https://poser.pugx.org/wapmorgan/media-file/v/unstable)](https://packagist.org/packages/wapmorgan/media-file)
[![Tests](https://travis-ci.org/wapmorgan/MediaFile.svg?branch=master)](https://travis-ci.org/wapmorgan/MediaFile)

## Supported formats

| Audio | Video |
|-------|-------|
| aac, amr, flac, mp3, ogg, wav, wma | avi, mkv, mp4, wmv |
| - length | - length |
| - bitRate | - width |
| - sampleRate | - height |
| - channels | - frameRate |

**Table of contents:**
1. Usage
2. API
3. Why not using getID3?
4. Technical details

## Usage

```php
use wapmorgan\MediaFile\MediaFile;

try {
  $media = MediaFile::open('123.mp3');
  // for audio
  if ($media->isAudio()) {
    $audio = $media->getAudio();
    echo 'Duration: '.$audio->getLength().PHP_EOL;
    echo 'Bit rate: '.$audio->getBitRate().PHP_EOL;
    echo 'Sample rate: '.$audio->getSampleRate().PHP_EOL;
    echo 'Channels: '.$audio->getChannels().PHP_EOL;
  }
  // for video
  else {
    $video = $media->getVideo();
    // calls to VideoAdapter interface
    echo 'Duration: '.$video->getLength().PHP_EOL;
    echo 'Dimensions: '.$video->getWidth().'x'.$video->getHeight().PHP_EOL;
    echo 'Framerate: '.$video->getFramerate().PHP_EOL;
  }
} catch (wapmorgan\MediaFile\Exceptions\FileAccessException $e) {
  // FileAccessException throws when file is not a detected media
} catch (wapmorgan\MediaFile\Exceptions\ParsingException $e) {
   echo 'File is propably corrupted: '.$e->getMessage().PHP_EOL;
}
```

## API
### MediaFile

`wapmorgan\wapmorgan\MediaFile`

| Method                                   | Description                                                                       | Notes                                                                                                   |
|------------------------------------------|-----------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------|
| `static open($filename): MediaFile`      | Detects file type and format and calls constructor with these parameters.         | Throws an `UnknownFormatException` (a `FileAccessException`) with an explainable report if the format is not recognised. |
| `static inspect($filename): DetectionReport` | Runs detection without constructing an adapter.                               | Returns the winner, all scored candidates, confidence, stage and timing.                               |
| `static getLastDetectionReport(): DetectionReport` | Report produced by the last `open()`/`inspect()` call.                    | Null before the first detection.                                                                       |
| `isAudio(): boolean`                     | Returns true if media is just audio.                                              |                                                                                                         |
| `isVideo(): boolean`                     | Returns true if media is a video with audio.                                      |                                                                                                         |
| `isContainer(): boolean`                 | Returns true if media is also a container (can store multiple audios and videos). |                                                                                                         |
| `getFormat(): string`                    | Returns media file format.                                                        |                                                                                                         |
| `getAudio(): AudioAdapter`               | Returns an `AudioAdapter` interface for audio.                                    |                                                                                                         |
| `getVideo(): VideoAdapter`               | Returns an `VideoAdapter` interface for video.                                    |                                                                                                         |

## Format detection

Detection lives in one self-contained component,
`wapmorgan\MediaFile\FormatDetector\FormatDetector`, which reads only the first
64 bytes of a file. It replaces the previous first-match signature walk with a
single, unified pipeline:

1. **Scored content probes.** Every probe (WAV/RIFF, FLAC, Ogg, AMR, ASF GUID,
   EBML/Matroska, ISO BMFF, MPEG audio, ADTS) reports on the same 0–100 scale.
   The highest-scoring probe above the threshold (`THRESHOLD = 25`) wins, so
   candidates from different signatures are ranked instead of picked by
   iteration order.
2. **Structured prefixes.** An ID3v2 tag is parsed and skipped so the MPEG
   frame behind it is reached, and leading `free`/`skip` ISO BMFF boxes are
   walked to find a `ftyp` box that does not sit at offset 4.
3. **One cache, one key scheme.** Raw heads use `head:<hash>` keys, files use
   `file:<hash of realpath, size and mtime>`; `clearCache()` resets both.
4. **One fallback path.** Only when no content probe reaches the threshold is
   the filename extension used, at a low score and explicitly labelled
   `extension` in the report.
5. **Explainable unknowns.** Unrecognised input still returns a
   `DetectionReport` (and `open()` throws an `UnknownFormatException` carrying
   it) with every near-miss candidate and a human-readable reason.

`DetectionReport` exposes `getFormat()`, `getAdapter()`, `getScore()`,
`getConfidence()` (`high` / `medium` / `low` / `none`), `getStage()`,
`getCandidates()`, `getCacheKey()`, `isFromCache()`, `getTimeMs()` and
`explain()`.

### Head matrix

A fixed-head probe matrix (synthetic 32- and 64-byte headers covering common
formats, short samples, ID3 prefixes and late `ftyp` boxes) prints the hit
adapter, confidence, score and average detection time:

```sh
php bin/head-matrix.php            # text table
php bin/head-matrix.php --json     # one JSON object per cell
php bin/head-matrix.php --cache    # verify the second lookup is cached
php bin/head-matrix.php path/...   # also probe real files (first 64 bytes)
```

The detector itself has no third-party dependencies; its assertions run with
`php tests/FormatDetectorTest.php` without `composer install`.

### AudioAdapter

`wapmorgan\MediaFile\AudioAdapter`

| Method                         | Description                                                       |
|--------------------------------|-------------------------------------------------------------------|
| `getLength(): float`           | Returns audio length in seconds and microseconds as _float_.      |
| `getBitRate(): int`            | Returns audio bit rate as _int_.                                  |
| `getSampleRate(): int`         | Returns audio sampling rate as _int_.                             |
| `getChannels(): int`           | Returns number of channels used in audio as _int_.                |
| `isVariableBitRate(): boolean` | Returns whether format support VBR and file has VBR as _boolean_. |
| `isLossless(): boolean`        | Returns whether format has compression lossless as _boolean_.     |

### VideoAdapter

`wapmorgan\MediaFile\VideoAdapter`

| Method                | Description                                                  |
|-----------------------|--------------------------------------------------------------|
| `getLength(): int`    | Returns video length in seconds and microseconds as _float_. |
| `getWidth(): int`     | Returns width of video as _int_.                             |
| `getHeight(): int`    | Returns height of video as _int_.                            |
| `getFramerate(): int` | Returns video frame rate of video as _int_.                  |

### ContainerAdapter

`wapmorgan\MediaFile\ContainerAdapter`

| Method                     | Description                                      |
|----------------------------|--------------------------------------------------|
| `countStreams(): int`      | Returns number of streams in container as _int_. |
| `countVideoStreams(): int` | Returns number of video streams as _int_.        |
| `countAudioStreams(): int` | Returns number of audio streams as _int_.        |
| `getStreams(): array`      | Returns streams information as _array_.          |

## Why not using getID3?

getID3 library is very popular and has a lot of features, but it's old and too slow.

Following table shows comparation of analyzing speed of fixtures, distributed with first release of MediaFile:

| File       | getID3 | MediaFile | Speed gain |
|------------|--------|-----------|------------|
| video.avi  | 0.215  | 0.126     | 1.71x      |
| video.mp4  | 3.055  | 0.429     | 7.12x      |
| video.wmv  | 0.354  | 0.372     | 0.95x      |
| audio.aac  | 0.560  | 0.262     | 2.13x      |
| audio.amr  | 8.241  | 12.248    | 0.67x      |
| audio.flac | 1.880  | 0.071     | 26.41x     |
| audio.m4a  | 13.372 | 0.169     | 79.14x     |
| audio.mp3  | 10.931 | 0.077     | 141.54x    |
| audio.ogg  | 0.170  | 0.096     | 1.78x      |
| audio.wav  | 0.114  | 0.070     | 1.64x      |
| audio.wma  | 0.195  | 0.158     | 1.23x      |

## Technical information

| Format | Full format name                                             | Specifications                                                                                                                                                                                                     | Notes                                 |
|--------|--------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------------------------------------|
| aac    | MPEG 4 Part 12 container with audio only                     | http://l.web.umkc.edu/lizhu/teaching/2016sp.video-communication/ref/mp4.pdf                                                                                                                                        | Does not provide support of MPEG2-AAC |
| amr    | AMR-NB                                                       | http://hackipedia.org/File%20formats/Containers/AMR,%20Adaptive%20MultiRate/AMR%20format.pdf                                                                                                                       | Does not provide support of AMR-WB    |
| avi    | -                                                            | http://www.alexander-noe.com/video/documentation/avi.pdf                                                                                                                                                           |                                       |
| flac   | -                                                            | -                                                                                                                                                                                                                  | Support based on third-party library  |
| mkv    | Matroska container                                           | https://www.matroska.org/technical/specs/index.html                                                                                                                                                                |                                       |
| mp3    | MPEG 1/2 Layer 1/2/3                                         | https://github.com/wapmorgan/mp3info#technical-information                                                                                                                                                         |                                       |
| mp4    | MPEG 4 Part 12/14 container with few audio and video streams | Part 12 specification: http://l.web.umkc.edu/lizhu/teaching/2016sp.video-communication/ref/mp4.pdf Part 14 extension: https://www.cmlab.csie.ntu.edu.tw/~cathyp/eBooks/14496_MPEG4/ISO_IEC_14496-14_2003-11-15.pdf |                                       |
| ogg    | Ogg container with Vorbis audio                              | https://xiph.org/vorbis/doc/Vorbis_I_spec.html                                                                                                                                                                     |                                       |
| wav    | -                                                            | -                                                                                                                                                                                                                  | Support based on third-party library  |
| wma    | ASF container with only one audio stream                     | http://go.microsoft.com/fwlink/p/?linkid=31334                                                                                                                                                                     |                                       |
| wmv    | ASF container with few audio and video streams               | http://go.microsoft.com/fwlink/p/?linkid=31334                                                                                                                                                                     |                                       |

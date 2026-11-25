<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Observation;

use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\TaskClaimMapper;
use Survos\AiWorkflowBundle\Task\TaskInterface;
use Survos\AiWorkflowBundle\Task\TaskResult;
use Survos\ClaimsBundle\Entity\Claim;
use Survos\ClaimsBundle\Service\RunMeta;
use Survos\DataContracts\Workflow\AudioSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Local speech-to-text through VoiceStudio's OpenAI-compatible /v1/audio/transcriptions.
 * No cloud service, no per-minute cost, but also NO speaker labels: VoiceStudio has no
 * diarization parameter, so segments carry timestamps only. Use {@see AssemblyAiTranscribeTask}
 * (`transcribe_audio`) when speakers matter.
 *
 * VoiceStudio abandons a request after 300 s (OMNIVOICE_ASR_TRANSCRIBE_TIMEOUT_S) and runs at
 * about 1x real time, so the audio is cut with ffmpeg into CHUNK-second pieces, sent one at a
 * time (it has a single GPU worker), and re-stitched with absolute timestamps. Needs ffmpeg and
 * ffprobe on the host, and a VoiceStudio reachable from it: this task runs where VoiceStudio is
 * (the developer's machine), not on a remote mediary.
 *
 * Flat text goes into ai:ocrText (the predicate AssemblyAI uses, so folio display needs no change);
 * the segments with timestamps ride in RunMeta::$response for a RAG chunker to window later.
 *
 * Prototyped in ~/sites/oral-history (Transcriber) against two LBJ telephone calls: 167 s in
 * 144 s (1 chunk), 356 s in 251 s (3 chunks).
 */
#[AsTask(
    'Local VoiceStudio Whisper speech-to-text, chunked to fit its 300 s cap — timestamps, no speaker labels.',
    self::class,
    produces: ['ai:ocrText'],
)]
final class VoiceStudioTranscribeTask implements TaskInterface
{
    public const string TASK = 'transcribe_audio_local';

    /** Seconds of audio per request; stays well under the 300 s server timeout at ~1x real time. */
    private const int CHUNK = 150;
    /** Seconds repeated between chunks so words at a cut are not lost. */
    private const int OVERLAP = 3;
    private const int REQUEST_TIMEOUT = 295;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly TaskClaimMapper $claimMapper,
        #[Autowire('%env(default::VOICESTUDIO_URL)%')]
        private readonly ?string $voiceStudioUrl = null,
        #[Autowire('%env(default::VOICESTUDIO_LANGUAGE)%')]
        private readonly ?string $language = null,
    ) {
    }

    public function getTask(): string
    {
        return self::TASK;
    }

    public function supports(WorkflowSubjectInterface $subject): bool
    {
        return $subject instanceof AudioSubjectInterface && null !== $subject->getWorkflowAudioUrl();
    }

    public function getMeta(): array
    {
        return [
            'agent' => 'voicestudio-whisper',
            'platform' => 'voicestudio',
            'model' => 'whisper-1',
            'diarization' => false,
        ];
    }

    public function run(WorkflowSubjectInterface $subject): TaskResult
    {
        if (!$subject instanceof AudioSubjectInterface || ($audioUrl = $subject->getWorkflowAudioUrl()) === null) {
            throw new \RuntimeException(self::class.' requires an AudioSubjectInterface with an audio URL.');
        }

        $dir = sys_get_temp_dir().'/voicestudio-'.bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            $source = $dir.'/source'.$this->extension($audioUrl);
            file_put_contents($source, $this->httpClient->request('GET', $audioUrl, ['timeout' => 120])->getContent());

            $duration = (float) trim((new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $source]))->mustRun()->getOutput());
            $segments = $this->transcribeChunks($source, $dir, $duration);
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }

        $text = trim(implode(' ', array_column($segments, 'text')));
        if ('' === $text) {
            throw new \RuntimeException('VoiceStudio returned no text for '.$audioUrl);
        }

        return new TaskResult(
            claims: $this->claimMapper->map(['text' => $text], Claim::PRED_OCR_TEXT),
            meta: new RunMeta(
                model: 'voicestudio-whisper-1',
                prompt: json_encode(['audio_url' => $audioUrl, 'chunk_seconds' => self::CHUNK], JSON_THROW_ON_ERROR),
                response: ['text' => $text, 'duration' => $duration, 'segments' => $segments],
            ),
        );
    }

    /** @return list<array{start: float, end: float, text: string, speaker: null}> */
    private function transcribeChunks(string $source, string $dir, float $duration): array
    {
        $whole = $duration <= self::CHUNK + 30;
        $segments = [];
        $n = 0;
        for ($start = 0.0; $start < $duration; $start += self::CHUNK - self::OVERLAP, ++$n) {
            $file = $source;
            if (!$whole) {
                $file = sprintf('%s/chunk%03d.mp3', $dir, $n);
                (new Process(['ffmpeg', '-v', 'error', '-y', '-ss', (string) $start, '-t', (string) self::CHUNK, '-i', $source, '-vn', '-c:a', 'libmp3lame', '-q:a', '4', $file]))->mustRun();
            }
            foreach ($this->call($file)['segments'] ?? [] as $s) {
                $absStart = $start + (float) $s['start'];
                // Skip what the previous chunk already covered in the overlap.
                if ($segments && $start > 0 && $absStart < end($segments)['end'] - 0.01) {
                    continue;
                }
                $segments[] = [
                    'start' => round($absStart, 2),
                    'end' => round($start + (float) $s['end'], 2),
                    'text' => trim((string) $s['text']),
                    'speaker' => null,
                ];
            }
            if ($whole) {
                break;
            }
        }

        return $segments;
    }

    /** @return array<string, mixed> */
    private function call(string $file): array
    {
        $fields = [
            'model' => 'whisper-1',
            'response_format' => 'verbose_json',
            'timestamp_granularities[]' => 'segment',
            'file' => DataPart::fromPath($file),
        ];
        if (null !== $this->language && '' !== $this->language) {
            $fields['language'] = $this->language;
        }
        $form = new FormDataPart($fields);

        return $this->httpClient->request('POST', rtrim($this->voiceStudioUrl ?: 'http://localhost:3900', '/').'/v1/audio/transcriptions', [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToIterable(),
            'timeout' => self::REQUEST_TIMEOUT,
        ])->toArray();
    }

    private function extension(string $url): string
    {
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return '' !== $ext && preg_match('/^[a-z0-9]{2,5}$/', $ext) ? '.'.$ext : '.mp3';
    }
}

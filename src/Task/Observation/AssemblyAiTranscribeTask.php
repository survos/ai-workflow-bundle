<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Observation;

use Aws\S3\S3Client;
use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\TaskClaimMapper;
use Survos\AiWorkflowBundle\Task\TaskInterface;
use Survos\AiWorkflowBundle\Task\TaskResult;
use Survos\ClaimsBundle\Entity\Claim;
use Survos\ClaimsBundle\Service\RunMeta;
use Survos\DataContracts\Workflow\AudioSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Speech-to-text with speaker diarization via AssemblyAI — for audio/video interviews
 * (survos-sites/musdig#24: oral-history collections; survos-sites/ai-tools#1: the
 * YouTube audio-extraction step this consumes). Chosen over Deepgram/Supadata because
 * diarization is a first-class, heavily-tested Universal-model feature there, not an
 * afterthought — Supadata explicitly doesn't do it at all.
 *
 * The audio itself never touches this task directly: the subject's URL is presigned
 * (see {@see presignIfS3()}) and handed to AssemblyAI as `audio_url` — AssemblyAI fetches
 * it, we never re-upload the bytes ourselves. AssemblyAI's transcription API is
 * submit-then-poll, not a single synchronous call, so this implements TaskInterface
 * directly rather than extending AbstractCapabilityTask (whose contract assumes one
 * $platform->invoke() call).
 *
 * Produces a flat, speaker-labeled ai:ocrText claim (see {@see formatSpeakerText()}) —
 * deliberately the SAME predicate captions/OCR already use, not the separate
 * ai:transcription predicate TaskClaimMapper also defines, so this flows through the
 * existing folio-bundle narrative/document display (BaseItemDto::$ocrText) with zero
 * further changes. The full per-utterance speaker+timestamp JSON is kept too — as
 * RunMeta::$response, which AssetAiExecutor writes to the S3 sidecar automatically — for
 * whatever a future Story/segment model (musdig#24) ends up needing; nothing here builds
 * that model, it just doesn't throw the data away.
 *
 * NOTE: written without a provisioned ASSEMBLYAI_API_KEY to test against — the HTTP
 * shape (endpoint paths, `authorization` header, `speaker_labels`/`utterances` field
 * names) matches AssemblyAI's documented v2 API as of this writing, but verify against
 * a real transcript once a key is added; this has been lint/wiring-checked, not
 * live-called.
 */
#[AsTask(
    'AssemblyAI speech-to-text with speaker diarization — for audio/video interviews.',
    self::class,
    produces: ['ai:ocrText'],
)]
final class AssemblyAiTranscribeTask implements TaskInterface
{
    public const string TASK = 'transcribe_audio';

    /** AssemblyAI batch transcription of a long interview can take minutes; poll, don't give up early. */
    private const int POLL_INTERVAL_SECONDS = 3;
    private const int MAX_WAIT_SECONDS = 1800;

    /** How long the presigned GET URL we hand to AssemblyAI stays valid — must outlive their fetch + queue wait. */
    private const string PRESIGN_TTL = '+60 minutes';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly TaskClaimMapper $claimMapper,
        #[Autowire('%env(ASSEMBLYAI_API_KEY)%')]
        private readonly string $apiKey,
        // Optional: only needed to presign s3:// subject URLs. Null when the S3 client isn't
        // configured (mirrors ?ImgproxyUrlBuilder-style optional-dependency patterns elsewhere) —
        // an http(s):// subject URL still works fine without it.
        private readonly ?S3Client $s3 = null,
    ) {
    }

    public function getTask(): string
    {
        return self::TASK;
    }

    public function supports(WorkflowSubjectInterface $subject): bool
    {
        return $subject instanceof AudioSubjectInterface && $subject->getWorkflowAudioUrl() !== null;
    }

    public function getMeta(): array
    {
        return [
            'agent' => 'assemblyai-universal',
            'platform' => 'assemblyai',
            'model' => 'universal',
            'diarization' => true,
        ];
    }

    public function run(WorkflowSubjectInterface $subject): TaskResult
    {
        if (!$subject instanceof AudioSubjectInterface || ($audioUrl = $subject->getWorkflowAudioUrl()) === null) {
            throw new \RuntimeException(self::class . ' requires an AudioSubjectInterface with an audio URL.');
        }

        $fetchableUrl = $this->presignIfS3($audioUrl);

        $submitted = $this->httpClient->request('POST', 'https://api.assemblyai.com/v2/transcript', [
            'headers' => [
                'authorization' => $this->apiKey,
                'content-type' => 'application/json',
            ],
            'json' => [
                'audio_url' => $fetchableUrl,
                'speaker_labels' => true,
            ],
        ])->toArray(false);

        $transcriptId = $submitted['id'] ?? null;
        if (!is_string($transcriptId) || $transcriptId === '') {
            throw new \RuntimeException('AssemblyAI did not return a transcript id: ' . json_encode($submitted, JSON_THROW_ON_ERROR));
        }

        $result = $this->pollUntilDone($transcriptId);

        if (($result['status'] ?? null) !== 'completed') {
            throw new \RuntimeException(sprintf(
                'AssemblyAI transcription %s failed (status=%s): %s',
                $transcriptId,
                (string) ($result['status'] ?? 'unknown'),
                (string) ($result['error'] ?? 'no error detail'),
            ));
        }

        $formattedText = $this->formatSpeakerText($result);
        $data = ['text' => $formattedText];

        // AssemblyAI's own raw payload already has a top-level `text` key — its non-diarized flat
        // transcript, NOT our speaker-labeled version. Any HTTP caller reading the sidecar/response's
        // `result.text` (not just the claim, which is already correct via $data above) would silently
        // get the wrong one if we returned $result as-is. Override it here so `result.text` in the
        // sidecar/HTTP response IS the speaker-labeled version; the original survives under `raw_text`
        // for anything that specifically wants AssemblyAI's un-diarized text.
        $response = $result;
        $response['raw_text'] = $response['text'] ?? null;
        $response['text'] = $formattedText;

        return new TaskResult(
            claims: $this->claimMapper->map($data, Claim::PRED_OCR_TEXT),
            meta: new RunMeta(
                model: 'assemblyai-universal',
                prompt: json_encode(['audio_url' => $fetchableUrl, 'speaker_labels' => true], JSON_THROW_ON_ERROR),
                response: $response,
            ),
        );
    }

    /**
     * AssemblyAI fetches the audio itself — it needs a URL it can reach, not our S3
     * credentials. Our bucket isn't public, so an s3://bucket/key subject URL (e.g. the
     * m4a ai-tools' /youtube/audio endpoint produces) gets turned into a time-limited
     * presigned GET URL; an already-fetchable http(s):// URL passes through untouched.
     */
    private function presignIfS3(string $url): string
    {
        $parsed = parse_url($url);
        if (($parsed['scheme'] ?? null) !== 's3') {
            return $url;
        }

        if ($this->s3 === null) {
            throw new \RuntimeException(sprintf('%s: subject URL "%s" is an s3:// URI but no S3 client is configured to presign it.', self::class, $url));
        }

        $bucket = (string) ($parsed['host'] ?? '');
        $key = ltrim((string) ($parsed['path'] ?? ''), '/');
        if ($bucket === '' || $key === '') {
            throw new \RuntimeException(sprintf('%s: malformed s3:// URL "%s".', self::class, $url));
        }

        $command = $this->s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key]);
        $request = $this->s3->createPresignedRequest($command, self::PRESIGN_TTL);

        return (string) $request->getUri();
    }

    /** @return array<string, mixed> the final transcript resource (status completed|error) */
    private function pollUntilDone(string $transcriptId): array
    {
        $deadline = time() + self::MAX_WAIT_SECONDS;
        do {
            $data = $this->httpClient->request('GET', "https://api.assemblyai.com/v2/transcript/{$transcriptId}", [
                'headers' => ['authorization' => $this->apiKey],
            ])->toArray(false);

            if (in_array($data['status'] ?? null, ['completed', 'error'], true)) {
                return $data;
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        } while (time() < $deadline);

        throw new \RuntimeException(sprintf(
            'AssemblyAI transcript %s did not finish within %d seconds.',
            $transcriptId,
            self::MAX_WAIT_SECONDS,
        ));
    }

    /**
     * Flatten AssemblyAI's per-utterance speaker turns into readable "Speaker A: ..." text —
     * grouping consecutive utterances from the same speaker so a long answer isn't split into
     * one paragraph per sentence. Falls back to the plain `text` field if diarization produced
     * no utterances (e.g. a single-speaker recording, or speaker_labels not actually honored).
     */
    private function formatSpeakerText(array $result): string
    {
        $utterances = $result['utterances'] ?? null;
        if (!is_array($utterances) || $utterances === []) {
            return trim((string) ($result['text'] ?? ''));
        }

        $lines = [];
        $lastSpeaker = null;
        foreach ($utterances as $utterance) {
            $text = trim((string) ($utterance['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $speaker = (string) ($utterance['speaker'] ?? '?');
            if ($speaker !== $lastSpeaker) {
                $lines[] = '';
                $lines[] = "Speaker {$speaker}:";
                $lastSpeaker = $speaker;
            }
            $lines[] = $text;
        }

        return trim(implode("\n", $lines));
    }
}

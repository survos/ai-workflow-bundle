# M6 audio services

Operational reference for applications using VoiceStudio on M6. Checked against
the installed **VoiceStudio 0.5.7** API on **2026-11-25**. Discover the running
contract at `/openapi.json` before relying on newer upstream documentation.
This document does not register a Symfony AI provider or change application defaults.

## Connection and authentication

M6 is reachable through the existing `m6` SSH alias (`m6-mini` at the private
Tailscale address `100.120.218.55`). VoiceStudio binds only to M6 loopback:
`http://127.0.0.1:3900`. It is not a public endpoint and the Tailscale IP does
not listen on port 3900.

From the calling host, keep an authenticated SSH tunnel running:

```bash
ssh -N -L 127.0.0.1:13900:127.0.0.1:3900 \
  -o ExitOnForwardFailure=yes -o ServerAliveInterval=30 m6
```

Use `http://127.0.0.1:13900` as the base URI on that host. The web UI is at `/`.
For OpenAI-compatible audio clients, the API base is
`http://127.0.0.1:13900/v1`. This deployment relies on SSH authentication;
do not invent an API key or expose its unauthenticated backend publicly.
Container `localhost` is the container, so arrange a private reachable tunnel
endpoint for containerized consumers. Keep SSH credentials outside repository files.

## Endpoint inventory

| Operation | Method and path | Contract |
| --- | --- | --- |
| Health and version | `GET /health` | Status and compute device |
| API schema | `GET /openapi.json` | Installed API, including native extensions |
| Voice discovery | `GET /v1/audio/voices` | Profiles, aliases, available engines |
| Speech generation | `POST /v1/audio/speech` | OpenAI-compatible JSON; binary audio response |
| Transcription | `POST /v1/audio/transcriptions` | OpenAI-compatible multipart; no diarization option in 0.5.7 |
| Model inventory / transfer status | `GET /models`, `GET /models/install/status` | Local readiness; catalog entries are not proof of installation |
| Diarization status | `GET /engines/diarisation` | Selected backend and installation readiness |
| Diarization selection | `POST /engines/diarisation/select` | Native JSON `{"engine_id":"audiocpp-sortformer"}` |
| Upload a diarization job | `POST /dub/upload` | Native multipart: `video=@file.wav`, `input_type=audio`, `source_lang=en` |
| Upload preparation progress | `GET /tasks/stream/{task_id}` | SSE; wait for preparation to finish before transcription |
| Speaker-labeled transcription | `GET /dub/transcribe-stream/{job_id}` | Native SSE with progress, warnings, final segments, completion |
| Synchronous alternative | `POST /dub/transcribe/{job_id}` | Native JSON segments with speaker labels; inspect logs for fallback |
| Saved jobs | `GET /dub/history`, `GET /dub/history/{history_id}` | Persisted job state |
| Agent tools | `/mcp` | HTTP MCP; discover tools at runtime |

The audio compatibility endpoints do not imply support for OpenAI chat,
Responses, or OpenAI's diarization-specific model/response formats.

```bash
curl --fail-with-body http://127.0.0.1:13900/health

curl --fail-with-body http://127.0.0.1:13900/v1/audio/speech \
  -H 'Content-Type: application/json' \
  -d '{"model":"mlx-audio","voice":"default","input":"The archive is ready.","response_format":"wav"}' \
  -o speech.wav

curl --fail-with-body http://127.0.0.1:13900/v1/audio/transcriptions \
  -F file=@speech.wav -F model=whisper-1 -F response_format=verbose_json

curl --fail-with-body http://127.0.0.1:13900/dub/upload \
  -F video=@conversation.wav -F input_type=audio -F source_lang=en
# Read job_id and task_id from the response; follow preparation SSE first.
curl --no-buffer --fail-with-body \
  http://127.0.0.1:13900/tasks/stream/TASK_ID
curl --no-buffer --fail-with-body \
  http://127.0.0.1:13900/dub/transcribe-stream/JOB_ID
```

Speaker IDs are anonymous clusters, not recognized identities. Do not attach
names without separate evidence. Inspect SSE `warning` and `error` events:
HTTP 200 or a terminal event alone does not prove acoustic diarization succeeded.
VoiceStudio can fall back to silence-gap labels when a model fails. The
synchronous route can also fall back, with diagnostics in the backend log.

## Installed models and limits

- TTS: OmniVoice, KittenTTS, MLX Kokoro. Explicit `model` selects the engine;
  OpenAI aliases such as `tts-1` use the current default, not an OpenAI model.
- ASR: MLX Whisper large-v3 and Turbo, MLX Parakeet TDT v3, sherpa Whisper Tiny.
- Diarization: **Sortformer v1 Q8_0** via the app-managed **audio.cpp v0.7.4**
  macOS arm64 runtime. The installed catalog bundles the Sortformer weights
  with Breeze-TTS-2 weights; that additional TTS engine is not selected.
- The global performance preference is Max, but the app may adjust individual
  families for hardware and installed models. Apple MPS/MLX acceleration was
  verified for speech and transcription. Keep one GPU request active at a time
  on this 24 GB unified-memory host.

**Sortformer limits in this release: at most four speakers and 120 seconds per
recording. Do not supply `num_speakers`: this adapter cannot enforce an exact
count.** Independently diarizing chunks does not preserve speaker identities
across chunk boundaries. For longer interviews, configure and verify pyannote
instead; it is not installed on M6. Its gated model downloads require a Hugging
Face account with repository access, in addition to local license acceptance.

The installed Sortformer model is **CC-BY-NC-4.0**; the bundled Breeze weights
have their own research/noncommercial terms. The user authorized acceptance,
and VoiceStudio recorded it. Acceptance does not remove those restrictions.
Review the exact model terms before commercial deployment. References:
[upstream Sortformer](https://huggingface.co/nvidia/diar_sortformer_4spk-v1),
[VoiceStudio audio.cpp guide](https://github.com/debpalash/VoiceStudio/blob/main/docs/engines/audio-cpp.md).

## Operations and storage

The API is a native Python service managed by the user LaunchAgent
`org.survos.voicestudio-api`, not Docker. It starts at user login and restarts
after a crash. The Electron desktop window is not required; its first launch
stalled on this machine, so the packaged backend is managed independently.

On M6 as `m6-mini` (user ID 501):

```bash
# Status
launchctl print gui/501/org.survos.voicestudio-api
curl --fail-with-body http://127.0.0.1:3900/health

# Stop and keep stopped, after checking for active audio jobs
launchctl disable gui/501/org.survos.voicestudio-api
launchctl bootout gui/501/org.survos.voicestudio-api

# Start again when stopped
launchctl enable gui/501/org.survos.voicestudio-api
launchctl bootstrap gui/501 \
  /Users/m6-mini/Library/LaunchAgents/org.survos.voicestudio-api.plist
```

Paths on M6:

- App: `/Applications/VoiceStudio.app`.
- Runtime: `/Users/m6-mini/Library/Application Support/VoiceStudio/runtime/project`.
- Durable app data and job audio: `/Users/m6-mini/Library/Application Support/OmniVoice`.
- Hugging Face cache: `/Users/m6-mini/.cache/huggingface/hub`.
- Logs: `/Users/m6-mini/Library/Logs/voicestudio-api.log` and
  `/Users/m6-mini/Library/Logs/voicestudio-api-error.log`.
- Verification fixture/results:
  `/Users/m6-mini/Library/Application Support/VoiceStudio/verification/diarization-2026-11-25`.

### Version 0.5.7 cache-path workaround

The Sortformer adapter resolves the Hugging Face snapshot symlink to its
extensionless blob, then requires the resolved filename to end in `.gguf`.
This rejects otherwise valid installed weights. A byte-verified copy is stored
at `/Users/m6-mini/Library/Application Support/VoiceStudio/models/sortformer-diar-4spk-v1-q8_0.gguf`;
the LaunchAgent sets `OMNIVOICE_DIARIZATION_MODEL` to that regular file. No
upstream source was patched. Keep the recorded license acceptance: a custom
model path does not itself provide registry provenance or license enforcement.
Recheck this workaround on upgrades before removing the override.

## Verification on 2026-11-25

The 55.18-second fixture uses two KittenTTS preset voices in four alternating
turns (A, B, A, B), with one-second gaps. The native Sortformer adapter detected
exactly two speaker identities and assigned the same identity to both turns of
each voice, without a speaker-count hint. The measured diarization-only pass
took **1.84 seconds on Metal / Apple M6** and returned 16 speech intervals.
This is a clean synthetic smoke test, not an accuracy benchmark for noisy,
overlapping, or long recordings.

The complete native API workflow was also exercised: audio upload, preparation,
MLX Whisper transcription, Sortformer speaker labeling, and a terminal SSE
`final` plus `done`. Job `8c1ae85d` returned `Speaker 1` / `Speaker 2` consistently
across the four turns, with no warning/error events. The direct adapter test
independently rules out a silence-gap fallback masquerading as model success.

`expected.json`, `two-speakers.wav`, `events.sse`, `verification.json`, and
`verify.py` are retained in the verification directory above. Re-run the
diarization-only check on M6:

```bash
cd '/Users/m6-mini/Library/Application Support/VoiceStudio/runtime/project/backend'
OMNIVOICE_DIARIZATION_MODEL='/Users/m6-mini/Library/Application Support/VoiceStudio/models/sortformer-diar-4spk-v1-q8_0.gguf' \
  ../.venv/bin/python '/Users/m6-mini/Library/Application Support/VoiceStudio/verification/diarization-2026-11-25/verify.py'
```

## Workflow integration

Treat transcription and speaker turns as source observations. Applications
should persist input hashes, engine/model/version, language, segment timestamps,
speaker IDs, warnings, and verification status alongside claims/runs. TaskRunner
does not automatically map these native responses into claims. Implement that
mapping explicitly in a future application task/client; this documentation adds
no runtime integration.

Use bounded audio jobs through Messenger. Avoid changing the shared engine
selection per request across concurrent callers. Existing crop jobs were not
stopped for installation. `bin/console mess:stop crops` drains only crop
consumers, but launchd KeepAlive restarts them: a sustained pause needs supervisor
coordination as well. Do not kill active crop jobs to prioritize audio.

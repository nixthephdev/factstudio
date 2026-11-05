# Local motion previews

The office GTX 1660 Ti has 6 GB VRAM. The motion study uses AnimateLCM with the existing SD 1.5 checkpoint and AnimateDiff Evolved. It generates a temporal sequence, rather than panning over one still. This is a small quality experiment, not a promise of Higgsfield-level results or camera control.

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/install-animation.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/start-animation.ps1
C:\xampp\php\php.exe bin/animate.php "A waterfall flowing over dark rocks in a green forest, splashing water, steady camera"
```

The installer downloads about 1.95 GB of official motion weights and verifies pinned SHA-256 checksums. It reuses the existing checkpoint and Python environment. Model files and the extension stay under ignored `tools/`. The separate ComfyUI instance listens only on `127.0.0.1:8189`; the original image service remains on 8188. Both use the same GPU, so run one generation at a time. Classic model loading and GPU VAE decoding are enabled for this preset. Restart the motion server after Windows restarts.

Start FACT Studio and choose **Animate a shot** on Overview or Library to enter another prompt. The tested preset uses the 100-step distilled LCM schedule, adapter strength 1.0, guidance 1.5, and all 16 frames in one context. It generates 16 frames at 512 × 512, 8 sampling steps, and 8 fps: a silent, two-second square H.264 MP4 at its actual generated resolution. There is no frame interpolation, repeated loop, subtitle burn, headline overlay, or narration in this path. Generated imagery can contain artifacts and is illustrative, not documentary evidence.

Jobs use the non-publishing `preview` mode and appear in the existing library. Paid services, live publishing and schedules are not part of motion previews. Saving a ComfyUI request ID lets a retry retrieve the same generation. An unconfirmed submission stops for inspection rather than silently submitting again. Resume a blocked motion test with:

```powershell
C:\xampp\php\php.exe bin/animate.php --resume JOB_ID
```

Logs are in `storage/logs/animation.err.log`. Per-job frames, the exact workflow, seed and MP4 are retained under `storage/jobs/JOB_ID/`. Larger resolutions, longer clips and higher frame rates require another measured hardware test; the current UI deliberately starts small.

New standard illustrated renders, sample drawings and editorial renders also omit text overlays. Existing MP4 files retain their original appearance. Credits for the NASA editorial study remain in the job's `credits.json` and library source links.

Sources: [AnimateLCM model and usage](https://huggingface.co/wangfuyun/AnimateLCM), [AnimateDiff Evolved](https://github.com/Kosinkadink/ComfyUI-AnimateDiff-Evolved).

Initial eight-frame context tests and the 50-step distilled schedule produced severe artifacts on this setup; those jobs were marked as rejected diagnostics. A waterfall test with the preset above produced recognizable water and changing spray. These settings were selected by visual inspection; successful encoding alone does not establish visual quality.

Office benchmark (November 5, 2026): the tested 512-square, 8-step, guidance-1.5 waterfall took about three minutes for two seconds of generated footage on the GTX 1660 Ti. A smaller waterfall was usable; small solar tests were rejected, and a larger solar test had poor prompt adherence. This prototype does not provide dependable Higgsfield-style effects or arbitrary camera controls. Review every result.

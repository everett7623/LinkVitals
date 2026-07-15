# WordPress Missing Image Variants

## Symptom

Link audits reported many internal image 404 responses for filenames ending in
WordPress-style dimensions, such as `Alginate-1-300x300.jpg` and
`surgical-blade-1-300x300.jpg`.

## Captured Evidence

- Each sampled `-300x300` URL returned HTTP 404 with an HTML response.
- Removing only the `-300x300` suffix produced HTTP 200 with an `image/jpeg`
  response.
- The stored source occurrences were regular post-content `src` or `srcset`
  URLs, which the existing guarded replacement workflow can edit and roll back.

## Root Cause

The original uploads still existed, but older generated size files had been
deleted. A generic “restore or replace” suggestion did not tell an administrator
which replacement was valid and was impractical for many distinct images.

## Repair Decision

- Recognize only an image dimension suffix immediately before a supported file
  extension.
- Restrict automatic repair to an internal image link already verified as 404.
- Derive candidates on the server and require a 2xx `image/*` response.
- Reuse the existing URL replacement, edit-capability checks, logging, repair
  snapshots, and rollback.
- Process a selected bulk repair as one AJAX request per link to keep each
  request bounded and expose per-row failures.

## Safety Boundary

External images, non-404 errors, unknown filename patterns, redirects, and
non-image responses are never guessed. Thumbnail regeneration and media
metadata changes remain separate operations.

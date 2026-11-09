# Docker build experiment

This branch tests two changes: cache dependency installation separately from
application source, and build AMD64 only on normal `dev` pushes. Composer's
autoload generation and package discovery still run after source is copied.

The `Benchmark Docker build changes` workflow builds the baseline and this
branch on separate AMD64 runners, without publishing images. Each job reports a
cold web build and a rebuild after the same source-only change; it also starts
the web image's Laravel CLI and checks the CLI image. Compare both job summaries
and logs. These are individual measurements, not guaranteed speedups.

Normal `dev` builds on this branch publish AMD64-only tags. For a multi-platform
image, run `Build and publish Docker images` manually on `dev`, selecting
`amd64-and-arm64`. A later normal `dev` push replaces the moving `dev` and
`latest` tags with AMD64-only images again. Immutable SHA tags keep the platforms
from their own build. An ARM Mac can also build locally with:

```sh
docker buildx build --platform linux/arm64 --target web --load -t data-importer:local-arm64 .
```

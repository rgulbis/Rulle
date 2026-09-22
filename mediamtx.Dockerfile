# MediaMTX itself only supports overriding whole top-level config keys via
# MTX_-prefixed env vars, not ${VAR}-style interpolation inside an arbitrary
# value like a path's `source` — and the final image has no shell to do
# that substitution at runtime anyway. So it happens here at build time
# instead, in a throwaway stage that has a shell, before the real minimal
# image ever sees the file.
#
# CAMERA_RTSP_URL (camera credentials included) ends up baked into this
# image's layers — no worse than it already being in plaintext in .env on
# this same server, since this image is only ever built locally here and
# never pushed to a registry.
FROM alpine:3 AS config
ARG CAMERA_RTSP_URL
COPY docker/mediamtx.yml /mediamtx.yml.template
RUN sed "s|\${CAMERA_RTSP_URL}|${CAMERA_RTSP_URL}|" /mediamtx.yml.template > /mediamtx.yml

FROM bluenviron/mediamtx:latest
COPY --from=config /mediamtx.yml /mediamtx.yml

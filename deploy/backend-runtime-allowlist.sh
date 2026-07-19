#!/usr/bin/env bash

MOST_COMPOSE_WRITER_SERVICES=(
  api websockets horizon geometry-worker geometry-recovery-worker worker-heavy worker-ifc scheduler
)

MOST_SYSTEMD_WRITER_UNITS=(
  prohelper-octane.service
  prohelper-queue.service
  reverb.service
)

MOST_SUPERVISOR_WRITER_PROGRAM_PATTERN='^(most|prohelper|laravel-worker|horizon|scheduler|queue|artisan)([-_:].*)?$'

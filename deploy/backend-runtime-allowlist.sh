#!/usr/bin/env bash

MOST_COMPOSE_WRITER_SERVICES=(
  api websockets horizon geometry-worker geometry-recovery-worker worker-heavy worker-ifc scheduler
)

MOST_SYSTEMD_WRITER_UNITS=(
  most-backend.service
  most-horizon.service
  most-queue.service
  most-scheduler.service
  prohelper.service
  prohelper-horizon.service
  prohelper-worker.service
  prohelper-scheduler.service
)

MOST_SUPERVISOR_WRITER_PROGRAM_PATTERN='^(most|prohelper|laravel-worker|horizon|scheduler|queue|artisan)([-_:].*)?$'

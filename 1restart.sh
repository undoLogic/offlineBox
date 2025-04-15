#!/usr/bin/env bash
docker volume prune -f
docker-compose down
sleep 3
docker-compose up -d
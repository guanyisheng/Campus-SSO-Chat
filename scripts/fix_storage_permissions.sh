#!/bin/bash
# 宝塔 / LNMP：把 storage 目录交给 PHP 运行用户（常见为 www）
# 用法：sudo bash scripts/fix_storage_permissions.sh

set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STORAGE="${ROOT}/storage"
WEB_USER="${WEB_USER:-www}"

mkdir -p "${STORAGE}/conversations"
chown -R "${WEB_USER}:${WEB_USER}" "${STORAGE}"
chmod -R 755 "${STORAGE}"

echo "OK: ${STORAGE}"
echo "  owner: ${WEB_USER}"
echo "  mode:  755"
echo "  对话 JSON: conversations/{用户ID}/{对话ID}.json"
echo "  上传附件: conversations/{用户ID}/upload/"
ls -la "${STORAGE}"

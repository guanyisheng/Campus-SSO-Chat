#!/bin/bash
# 直接清空各用户 upload 目录下的全部附件（对话 JSON 不动）
#
# 宝塔 → 计划任务 → Shell：
#   /bin/bash /www/wwwroot/192.168.1.33_18481/scripts/cleanup_uploads.sh
#
# 例：每 10 天执行一次，在宝塔里把执行周期设为「每 10 天」即可。

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONV_DIR="${ROOT}/storage/conversations"

if [[ ! -d "${CONV_DIR}" ]]; then
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] skip: ${CONV_DIR} not found"
  exit 0
fi

count=0
while IFS= read -r -d '' f; do
  rm -f "$f"
  count=$((count + 1))
done < <(find "${CONV_DIR}" -path '*/upload/*' -type f -print0 2>/dev/null || true)

echo "[$(date '+%Y-%m-%d %H:%M:%S')] cleared ${count} file(s) under */upload/"

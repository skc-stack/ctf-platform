#!/bin/bash
# verifier/check.sh — 自動驗證腳本
# 比較使用者提交的 flag 與 .current_flag 中的動態 flag
# 用法: ./check.sh <submitted_flag>

set -e

if [ -z "$1" ]; then
    echo "Usage: $0 <submitted_flag>"
    exit 1
fi

SUBMITTED="$1"
FLAG_FILE="$(dirname "$0")/../.current_flag"

if [ ! -f "$FLAG_FILE" ]; then
    echo "Flag file not found. Challenge may not be started."
    exit 1
fi

EXPECTED=$(cat "$FLAG_FILE" | tr -d '\n')

if [ "$SUBMITTED" = "$EXPECTED" ]; then
    echo "CORRECT"
    exit 0
else
    echo "INCORRECT"
    exit 1
fi

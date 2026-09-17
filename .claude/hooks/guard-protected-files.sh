#!/usr/bin/env bash
# PreToolUse hook: blocks Edit/Write on files that should never be
# machine-edited (live secrets, lockfiles) — asks the user to change them by hand.
input=$(cat)
file_path=$(echo "$input" | jq -r '.tool_input.file_path // empty')

case "$(basename -- "$file_path")" in
  .env|.env.backup|.env.production|composer.lock)
    echo "Blocked: editing $(basename -- "$file_path") directly is not allowed — ask the user to change it by hand." >&2
    exit 2
    ;;
esac

exit 0

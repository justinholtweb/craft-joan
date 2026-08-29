#!/bin/bash
#
# Fetches every one of Joan's control panel screens as a logged-in admin and reports the
# status code, the response size, and whether a Craft error page came back wearing a 200.
#
#   HOST=mysite.ddev.site CP_USER=admin CP_PASS=secret ./cp-smoke.sh
#
# A rendering error in a Twig template is invisible to the PHP suites — the templates are
# where half of Joan lives, and this is the cheapest way to know they all still render.
set -u

BASE="${BASE:-http://localhost}"
HOST="${HOST:-plugin-testing.ddev.site}"
# Deliberately not USER: the shell already exports that, and it is not the CP account.
USERNAME="${CP_USER:-admin}"
PASSWORD="${CP_PASS:-}"
JAR=$(mktemp)

if [ -z "$PASSWORD" ]; then
  echo "Set CP_PASS to the admin password." >&2
  exit 1
fi

csrf() {
  curl -s -b "$JAR" -c "$JAR" -H "Host: $HOST" "$BASE/admin/login" \
    | grep -o 'name="CRAFT_CSRF_TOKEN" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'
}

curl -s -o /dev/null -b "$JAR" -c "$JAR" -H "Host: $HOST" -H "Accept: application/json" \
  -H "X-Requested-With: XMLHttpRequest" \
  -d "CRAFT_CSRF_TOKEN=$(csrf)" -d "loginName=$USERNAME" -d "password=$PASSWORD" \
  -d "action=users/login" "$BASE/"

failed=0

fetch() {
  local path="$1"
  local out code body err=""
  out=$(curl -s -w '\n%{http_code}' -b "$JAR" -c "$JAR" -H "Host: $HOST" "$BASE/$path")
  code=$(echo "$out" | tail -1)
  body=$(echo "$out" | sed '$d')

  if [ "$code" != "200" ]; then
    err=" << $code"
    failed=$((failed + 1))
  elif echo "$body" | grep -qiE "exception|Whoops|Template not found|Unknown method|Fatal error"; then
    err=" << error page"
    failed=$((failed + 1))
  fi

  printf "  %-46s %7s bytes%s\n" "$path" "$(echo -n "$body" | wc -c | tr -d ' ')" "$err"
}

for path in joan joan/fields joan/entry-types joan/nested joan/layouts joan/cleanup joan/settings; do
  fetch "admin/$path"
done

for query in "verdict=unused" "verdict=empty" "type=craft%5Cfields%5CPlainText" "search=body"; do
  fetch "admin/joan/fields?$query"
done

fetch "admin/joan/layouts?kind=other"
fetch "admin/joan/layouts?kind=unattributed"

# Detail screens, using whichever field and entry type the site happens to have first.
for uid in $(curl -s -b "$JAR" -c "$JAR" -H "Host: $HOST" "$BASE/admin/joan/fields" \
  | grep -o 'joan/fields/[0-9a-f-]\{36\}' | sed 's|joan/fields/||' | sort -u | head -5); do
  fetch "admin/joan/fields/$uid"
done

for uid in $(curl -s -b "$JAR" -c "$JAR" -H "Host: $HOST" "$BASE/admin/joan/entry-types" \
  | grep -o 'joan/entry-types/[0-9a-f-]\{36\}' | sed 's|joan/entry-types/||' | sort -u | head -5); do
  fetch "admin/joan/entry-types/$uid"
done

rm -f "$JAR"

if [ "$failed" -gt 0 ]; then
  echo "$failed screen(s) failed"
  exit 1
fi

echo "every screen rendered"

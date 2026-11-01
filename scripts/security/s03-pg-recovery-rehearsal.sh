#!/bin/sh
# Rehearsal CI only. Three fresh, disposable PostgreSQL instances; no client files.
set -eu
test "$GITHUB_ACTIONS" = true || { echo "STOP: GitHub Actions only"; exit 1; }
test -n "$RUNNER_TEMP" || { echo "STOP: private runner directory missing"; exit 1; }
private="$RUNNER_TEMP/s03-pg-private"
evidence="$RUNNER_TEMP/s03-pg-evidence"
dump="$private/fixture.dump"
test -s "$dump" || { echo "STOP: missing fictitious backup"; exit 1; }
test -s "$private/postgres-image-id.txt" || exit 1
mkdir -p "$evidence"
docker exec -i s03-pg-debian pg_restore --list < "$dump" > "$private/archive-list.txt"
grep -q s03_passages_fictifs "$private/archive-list.txt" || {
  echo "STOP: relational tables absent from backup"; exit 1;
}
sha256sum "$dump" > "$private/fixture-sha256.txt"

snapshot() {
    db="$1"
    output="$2"
    # Separate deterministic, data-only statements avoid OS-dependent dump formatting.
    docker exec "$db" psql -X -v ON_ERROR_STOP=1 -U postgres -d ikoma_fixture -At \
      -c "SELECT 'visitor|' || id || '|' || full_name || '|' || event_code FROM s03_visiteurs_fictifs ORDER BY id" > "$output"
    docker exec "$db" psql -X -v ON_ERROR_STOP=1 -U postgres -d ikoma_fixture -At \
      -c "SELECT 'badge|' || id || '|' || visitor_id || '|' || badge_code || '|' || status FROM s03_badges_fictifs ORDER BY id" >> "$output"
    docker exec "$db" psql -X -v ON_ERROR_STOP=1 -U postgres -d ikoma_fixture -At \
      -c "SELECT 'access|' || id || '|' || badge_id || '|' || direction || '|' || extract(epoch from recorded_at)::bigint FROM s03_passages_fictifs ORDER BY id" >> "$output"
    test "$(wc -l < "$output")" -eq 4000 || {
        echo "STOP: incomplete relational snapshot"; exit 1;
    }
}
snapshot s03-pg-debian "$private/source.rows"
snapshot s03-pg-alpine "$private/target.rows"
cmp "$private/source.rows" "$private/target.rows" || {
    echo "STOP: restored data differ on candidate Alpine"; exit 1;
}

# Rebuild candidate indexes after logical restore and ensure no data changed.
docker exec s03-pg-alpine psql -X -v ON_ERROR_STOP=1 -U postgres -d ikoma_fixture \
    -c 'REINDEX DATABASE ikoma_fixture;' > /dev/null
snapshot s03-pg-alpine "$private/target-after-reindex.rows"
cmp "$private/source.rows" "$private/target-after-reindex.rows" || {
    echo "STOP: REINDEX changed application data"; exit 1;
}

# Simulate a failed cutover ONLY on this throwaway Alpine target.
docker exec s03-pg-alpine psql -X -v ON_ERROR_STOP=1 -U postgres -d ikoma_fixture \
    -c "UPDATE s03_badges_fictifs SET status='restitue' WHERE id=1;" > /dev/null
snapshot s03-pg-alpine "$private/drift.rows"
if cmp -s "$private/source.rows" "$private/drift.rows"; then
    echo "STOP: synthetic drift went undetected"; exit 1;
fi

# Rollback rehearsal: a NEW Debian cluster restored from the original backup.
# Never mount an Alpine data directory in Debian (or the reverse).
docker run -d --rm --name s03-pg-rollback --network none \
    --tmpfs /var/lib/postgresql:rw,nosuid,size=1024m \
    -e POSTGRES_PASSWORD=uniquement-fictif -e POSTGRES_DB=ikoma_fixture \
    postgres:18.6-trixie >/dev/null
ready=0
for i in $(seq 1 45); do
    if docker exec s03-pg-rollback pg_isready -U postgres -d ikoma_fixture >/dev/null 2>&1; then
        ready=1; break
    fi
    sleep 2
done
test "$ready" -eq 1 || { echo "STOP: rollback cluster not ready"; exit 1; }
docker exec -i s03-pg-rollback pg_restore -U postgres -d ikoma_fixture \
    --exit-on-error --no-owner --no-privileges < "$dump" >/dev/null
snapshot s03-pg-rollback "$private/rollback.rows"
cmp "$private/source.rows" "$private/rollback.rows" || {
    echo "STOP: rollback restore differs from original snapshot"; exit 1;
}
snapshot s03-pg-debian "$private/source-after.rows"
cmp "$private/source.rows" "$private/source-after.rows" || {
    echo "STOP: original source was modified by rehearsal"; exit 1;
}
# Only sanitized, bounded evidence. No SQL dumps or private rows uploaded.
{
    echo 'fixture_only=TRUE'
    echo 'visitors=1000'
    echo 'badges=1000'
    echo 'access_records=2000'
    echo 'backup_archive_checked=PASS'
    echo 'logical_restore_and_full_row_compare=PASS'
    echo 'reindex_no_data_change=PASS'
    echo 'synthetic_drift_detected=PASS'
    echo 'fresh_debian_rollback_restore=PASS'
    echo 'source_unchanged=PASS'
    echo 'pgdata_shared=FALSE'
    echo 'real_client_data=FALSE'
    echo 'production_deployment=FALSE'
} > "$evidence/recovery-rehearsal.txt"
echo "S0.3-B fictitious recovery, rollback and index rehearsal: PASS"

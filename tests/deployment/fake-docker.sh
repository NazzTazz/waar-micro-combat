#!/usr/bin/env bash
# Stateful fault-injection substitute, used only by test-release-scripts.sh.
set -Eeuo pipefail
: "${FAKE_DOCKER_STATE:?}"
state=$FAKE_DOCKER_STATE
printf '%s\n' "$*" >> "$state/commands"
new_id="sha256:$(printf '%064d' 2)"
old_id="sha256:$(printf '%064d' 1)"
case "$1 $2" in
    'network inspect') exit 0;;
    'volume inspect') printf '%s\n' "$state/volume"; exit 0;;
    'image inspect')
        target=${!#}
        if [[ $target == waar-engine-demo:* ]]; then
            [[ -f $state/built ]] || exit 1
            id=$(cat "$state/built")
        else id=$target; fi
        if [[ $* == *org.opencontainers.image.revision* ]]; then
            cat "$state/sha"
        elif [[ $* == *'{{.Id}}'* ]]; then printf '%s\n' "$id"; fi
        exit 0;;
esac
case "$1" in
    ps) printf 'engine-container\n';;
    build)
        [[ ${FAIL_AT:-} != build ]] || exit 1
        printf '%s\n' "$new_id" > "$state/built"
        printf 'build\n' >> "$state/builds";;
    inspect)
        format=$3
        case "$format" in
            '{{.Image}}') cat "$state/image";;
            '{{.Config.Image}}') cat "$state/tag";;
            '{{.HostConfig.ReadonlyRootfs}}') echo true;;
            '{{json .HostConfig.SecurityOpt}}') echo '["no-new-privileges:true"]';;
            '{{json .HostConfig.PortBindings}}') echo '{}';;
            *State.Health*)
                if [[ ${FAIL_AT:-} == unhealthy && $(cat "$state/image") == "$new_id" ]]; then echo unhealthy; else echo healthy; fi;;
            *'.Mounts'*) echo volume:waar-engine-demo_profile-saves:true;;
            *config_files*) cat "$state/config";;
            *) echo "Unhandled inspect format: $format" >&2; exit 1;;
        esac;;
    compose)
        shift
        configs=()
        while [[ $1 == -f ]]; do configs+=("$2"); shift 2; done
        case "$1" in
            config)
                [[ ${2:-} != --quiet ]] || exit 0
                # Keep the old configuration sentinel and apply image override.
                cat "${configs[0]}"
                if ((${#configs[@]} > 1)); then cat "${configs[1]}"; fi;;
            up)
                file=${configs[0]}
                if [[ $file == */configurations/* ]]; then
                    [[ ${FAIL_AT:-} != rollback ]] || exit 1
                    grep -q 'old-config-sentinel' "$file" || { echo 'Previous config lost' >&2; exit 1; }
                    grep -q "$old_id" "$file" || { echo 'Previous immutable ID lost' >&2; exit 1; }
                    printf '%s\n' "$old_id" > "$state/image"
                    printf '%s\n' "$old_id" > "$state/tag"
                else
                    printf '%s\n' "$new_id" > "$state/image"
                    printf '%s\n' "$DEMO_IMAGE" > "$state/tag"
                    if [[ ${FAIL_AT:-} == corruption ]]; then printf 'corrupted\n' > "$state/volume/profiles.json"; fi
                    if [[ ${FAIL_AT:-} == signal ]]; then kill -TERM "$PPID"; fi
                    [[ ${FAIL_AT:-} != partial-up ]] || exit 1
                fi
                printf '%s\n' "$file" > "$state/config";;
            *) echo "Unhandled compose: $*" >&2; exit 1;;
        esac;;
    exec)
        # Consume input to exercise the historical bash-stdin failure boundary.
        cat >/dev/null
        [[ ${FAIL_AT:-} != runtime && ${FAIL_AT:-} != rollback ]] || exit 1
        echo 'HTTP=ok runtime=rust profiles=1';;
    stop) exit 0;;
    *) echo "Unhandled docker: $*" >&2; exit 1;;
esac

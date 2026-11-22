#!/usr/bin/env bash
set -Eeuo pipefail

curl -fsSL "https://raw.githubusercontent.com/keepraw/clean-link/${CLEAN_LINK_BRANCH:-main}/install.sh" | bash

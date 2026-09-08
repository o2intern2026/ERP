#!/usr/bin/env bash
# From a developer machine: push main and update the trial server (deploy/server/deploy.sh runs there).
#   bash deploy/deploy-trial.sh                # deploy origin/main
#   HOST=root@1.2.3.4 KEY=~/.ssh/other bash deploy/deploy-trial.sh
set -euo pipefail
HOST=${HOST:-root@103.6.171.144}
KEY=${KEY:-$HOME/.ssh/erp-oracle}
BRANCH=${BRANCH:-main}

git push -q origin "$BRANCH"
ssh -i "$KEY" -o BatchMode=yes "$HOST" "BRANCH=$BRANCH bash /var/www/erp/deploy/server/deploy.sh"

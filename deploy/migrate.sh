#!/usr/bin/env bash
#
# Runs an artisan command as a one-off Fargate task, waits for it, and prints
# the container's log output. Defaults to `migrate --force`.
#
#   ./deploy/migrate.sh
#   ./deploy/migrate.sh "migrate:status"
#   ./deploy/migrate.sh "db:seed --force"
#
# Migrations deliberately do not run from the container entrypoint. If they did,
# a rolling deploy of four tasks would race four migrations against each other,
# and a failed migration would become a crash loop instead of a failed step you
# can read. One task, once, before the service rolls, is the version that stays
# boring.
set -euo pipefail

STACK="${STACK_NAME:-LaravelFargate}"
REGION="${AWS_REGION:-ap-southeast-2}"
COMMAND="${1:-migrate --force}"

out() {
  aws cloudformation describe-stacks --stack-name "$STACK" --region "$REGION" \
    --query "Stacks[0].Outputs[?OutputKey=='$1'].OutputValue" --output text
}

CLUSTER=$(out ClusterName)
FAMILY=$(out WebTaskDefinition)
SUBNETS=$(out AppSubnets)
SG=$(out AppSecurityGroup)

# Build the command array as JSON without pulling in jq.
CMD_JSON='"php","artisan"'
for word in $COMMAND; do CMD_JSON="${CMD_JSON},\"${word}\""; done

echo "php artisan ${COMMAND}  ->  cluster ${CLUSTER}"

TASK_ARN=$(aws ecs run-task \
  --cluster "$CLUSTER" \
  --task-definition "$FAMILY" \
  --launch-type FARGATE \
  --region "$REGION" \
  --network-configuration "awsvpcConfiguration={subnets=[${SUBNETS}],securityGroups=[${SG}],assignPublicIp=DISABLED}" \
  --overrides "{\"containerOverrides\":[{\"name\":\"app\",\"command\":[${CMD_JSON}]}]}" \
  --query 'tasks[0].taskArn' --output text)

TASK_ID="${TASK_ARN##*/}"
echo "task ${TASK_ID} started, waiting for it to stop..."

aws ecs wait tasks-stopped --cluster "$CLUSTER" --tasks "$TASK_ARN" --region "$REGION"

EXIT_CODE=$(aws ecs describe-tasks --cluster "$CLUSTER" --tasks "$TASK_ARN" --region "$REGION" \
  --query 'tasks[0].containers[0].exitCode' --output text)

echo "--- container output ---"
aws logs get-log-events \
  --log-group-name "/ecs/${STACK}/web" \
  --log-stream-name "web/app/${TASK_ID}" \
  --region "$REGION" \
  --start-from-head \
  --query 'events[].message' --output text 2>/dev/null || echo "(no log stream yet)"

echo "--- exit code ${EXIT_CODE} ---"
[ "$EXIT_CODE" = "0" ]

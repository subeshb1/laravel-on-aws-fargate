#!/usr/bin/env bash
#
# Tears everything down. The S3 bucket has to be emptied by hand because the
# stack deliberately does not create the auto-delete Lambda that CDK would
# otherwise add: fewer moving parts, and no custom resource that can get stuck
# and block the delete.
set -euo pipefail

STACK="${STACK_NAME:-LaravelFargate}"
REGION="${AWS_REGION:-ap-southeast-2}"
PREFIX="${SECRET_PREFIX:-/laravel-fargate}"

BUCKET=$(aws cloudformation describe-stacks --stack-name "$STACK" --region "$REGION" \
  --query "Stacks[0].Outputs[?OutputKey=='UploadsBucket'].OutputValue" --output text 2>/dev/null || true)

if [ -n "${BUCKET:-}" ] && [ "$BUCKET" != "None" ]; then
  echo "Emptying s3://${BUCKET}"
  aws s3 rm "s3://${BUCKET}" --recursive --region "$REGION" >/dev/null || true
fi

echo "Destroying ${STACK}"
(cd "$(dirname "$0")/../infra" && npx cdk destroy "$STACK" --force)

echo "Deleting SSM parameters"
aws ssm delete-parameters \
  --names "${PREFIX}/app-key" "${PREFIX}/db-password" \
  --region "$REGION" >/dev/null || true

echo
echo "Confirming nothing is left:"
echo "  ECS clusters:  $(aws ecs list-clusters --region "$REGION" --query 'length(clusterArns)' --output text)"
echo "  RDS instances: $(aws rds describe-db-instances --region "$REGION" --query 'length(DBInstances)' --output text)"
echo "  Load balancers:$(aws elbv2 describe-load-balancers --region "$REGION" --query 'length(LoadBalancers)' --output text)"
echo "  NAT gateways:  $(aws ec2 describe-nat-gateways --region "$REGION" --filter Name=state,Values=available --query 'length(NatGateways)' --output text)"

#!/usr/bin/env bash
#
# Writes the two secrets the stack needs into SSM Parameter Store as
# SecureStrings, then never touches them again. Run once, before the first
# `cdk deploy`.
#
# SSM SecureString rather than Secrets Manager on purpose: standard parameters
# are free, Secrets Manager is $0.40 per secret per month, and neither of these
# needs rotation or cross-account sharing. If you later need automatic rotation
# for the database password, that is the moment to move it.
set -euo pipefail

PREFIX="${SECRET_PREFIX:-/laravel-fargate}"
REGION="${AWS_REGION:-ap-southeast-2}"

echo "Writing secrets under ${PREFIX} in ${REGION}"

# 32 random bytes, base64, in the exact shape Laravel expects.
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"

# RDS rejects '/', '@', '"' and spaces in master passwords, so filter them out.
DB_PASSWORD="$(head -c 48 /dev/urandom | base64 | tr -d '/@" =+' | head -c 32)"

aws ssm put-parameter \
  --name "${PREFIX}/app-key" \
  --value "${APP_KEY}" \
  --type SecureString \
  --overwrite \
  --region "${REGION}" >/dev/null

aws ssm put-parameter \
  --name "${PREFIX}/db-password" \
  --value "${DB_PASSWORD}" \
  --type SecureString \
  --overwrite \
  --region "${REGION}" >/dev/null

echo "Wrote ${PREFIX}/app-key and ${PREFIX}/db-password"
echo "Neither value was printed. Read them back with:"
echo "  aws ssm get-parameter --name ${PREFIX}/app-key --with-decryption"

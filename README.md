# Laravel on AWS ECS Fargate

A complete, deployable Laravel 13 application on ECS Fargate: web tier behind an
Application Load Balancer, a separate queue worker, a separate scheduler, RDS
PostgreSQL, S3, and secrets in SSM Parameter Store. Infrastructure is AWS CDK
(TypeScript). One `cdk deploy` builds the image, pushes it, and stands the whole
thing up.

Companion code for the blog post
[Deploying a Laravel application on AWS with ECS Fargate](https://www.subeshbhandari.com/blog/deploying-laravel-on-aws-with-ecs-fargate).

## What is in here

```
app/          Laravel 13 application, Dockerfile, FrankenPHP config
infra/        AWS CDK stack
deploy/       bootstrap-secrets.sh, migrate.sh, destroy.sh
```

The application is small on purpose, but it is not a hello world. Its home page
is a status board that makes one live call against every piece of the
architecture, so a green row is proof that piece is wired up:

| Row | What it proves |
| --- | --- |
| RDS PostgreSQL | the task's security group can reach the database subnet |
| Cache store | the cache is shared across tasks, not per container |
| S3 bucket | the task role can sign S3 requests, with no keys in the container |
| Queue | a different service on a different task is consuming jobs |
| Scheduler | the scheduler service is alive (it writes a heartbeat every minute) |
| Requests this worker | the Octane worker is reusing a booted framework |

## Architecture

```
             internet
                │
        Application Load Balancer          (public subnets, 2 AZ)
                │  :80  ->  :8000
                ▼
    ┌───────────────────────┐
    │  web service          │  Fargate, 0.5 vCPU / 1 GB, 2-6 tasks
    │  FrankenPHP + Octane  │  autoscaled on CPU
    └───────────────────────┘
    ┌───────────────────────┐
    │  worker service       │  Fargate, 0.25 vCPU / 0.5 GB
    │  queue:work           │
    └───────────────────────┘         (private subnets, NAT egress)
    ┌───────────────────────┐
    │  scheduler service    │  Fargate, 0.25 vCPU / 0.5 GB
    │  schedule:work        │  exactly one task, always
    └───────────────────────┘
                │
                ▼
       RDS PostgreSQL 18                   (isolated subnets, no route out)
       S3 bucket                           (uploads and generated reports)
       SSM Parameter Store                 (APP_KEY, database password)
```

Three services share one image and one task role. They differ only in the
command they run.

## Prerequisites

- An AWS account and credentials with permission to create VPC, ECS, RDS, ELB,
  S3, IAM and SSM resources
- Docker (the CDK builds the image locally and pushes it to ECR)
- Node.js 20+
- CDK bootstrapped in the target account and region: `npx cdk bootstrap`

## Deploy

```bash
# 1. Generate APP_KEY and the database password into SSM as SecureStrings.
#    Neither value is printed, stored in git, or written into the template.
export AWS_REGION=ap-southeast-2
./deploy/bootstrap-secrets.sh

# 2. Stand everything up. First run takes 12-15 minutes, most of it RDS.
cd infra
npm install
export CDK_DEFAULT_ACCOUNT=$(aws sts get-caller-identity --query Account --output text)
export CDK_DEFAULT_REGION=$AWS_REGION
export APP_RELEASE=$(git rev-parse --short HEAD)
npx cdk deploy

# 3. Run migrations as a one-off task, not from the container entrypoint.
cd ..
./deploy/migrate.sh
```

The stack outputs the load balancer URL. Open it.

## Redeploying after a code change

```bash
cd infra && APP_RELEASE=$(git rev-parse --short HEAD) npx cdk deploy
```

CDK rebuilds the image, pushes it under a new content hash, updates the task
definitions and rolls the services. `minHealthyPercent: 100` means new tasks
pass their health check before old ones are drained, and the deployment circuit
breaker rolls back automatically if the new tasks never come up.

If the change includes migrations, run `./deploy/migrate.sh` before the deploy
and keep the migration backwards compatible, because for a minute both versions
of the code are live at once.

## Clean up

```bash
./deploy/destroy.sh
```

Empties the S3 bucket, destroys the stack, deletes the two SSM parameters, and
prints the remaining resource counts so you can confirm nothing is still
billing. The NAT gateway and the RDS instance are the two things you do not want
to leave running by accident.

## Notes on the choices here

**FrankenPHP in worker mode, run directly.** The image runs
`frankenphp run --config /etc/frankenphp/Caddyfile` rather than
`php artisan octane:start`. Octane's command is a supervisor, and in a container
ECS is already the supervisor. Running FrankenPHP as PID 1 means SIGTERM from
ECS reaches the server directly. The worker script is still Octane's.

The Caddyfile's `php_server` block sets `index frankenphp-worker.php` and
`try_files {path} frankenphp-worker.php`. Both lines are required. Declaring a
worker is not enough: FrankenPHP only routes a request to a worker when the
script it resolves to is the worker script, and without them every request
resolves to `index.php` and boots the framework from scratch. Nothing errors.
Measured on this app at 0.5 vCPU:

| | p50 latency | throughput at 20 concurrent |
| --- | --- | --- |
| Worker mode | 8.1 ms | 96.7 req/s |
| Classic mode | 18.3 ms | 33.8 req/s |

**Database-backed cache, session and queue.** No ElastiCache in this stack. The
important property is that all three are shared across tasks; `file` is the
Laravel default and it is wrong the moment there is more than one container.
Postgres handles this fine at small scale. Redis is the upgrade when queue
throughput justifies it, not before.

**A scheduler service rather than an EventBridge rule.** `schedule:work` is a
long-running process, so it is one always-on 0.25 vCPU task instead of launching
a container 1,440 times a day. Its `desiredCount` is 1 and must stay 1: two
schedulers run every scheduled command twice.

**Migrations as a one-off task.** Running them from the entrypoint races every
task in a rolling deploy against every other, and turns a failed migration into
a crash loop instead of a failed step you can read.

**Secrets in SSM Parameter Store, not Secrets Manager.** Standard SecureString
parameters are free and neither of these needs rotation or cross-account
sharing. The database password reaches CloudFormation as a
`{{resolve:ssm-secure:...}}` dynamic reference and the container gets both
values through the task definition's `secrets` block, so no secret is ever in
the template, in git, or in a `docker inspect`.

**Two IAM roles.** The execution role belongs to the ECS agent: pull the image,
write logs, decrypt the SSM parameters. The task role belongs to your PHP
process and is what the AWS SDK inside Laravel signs with. Application code
never gets the execution role, and there are no AWS keys in the container at
all.

## Cost

Running continuously in ap-southeast-2, roughly:

| Item | Monthly |
| --- | --- |
| NAT gateway | ~$36 |
| Application Load Balancer | ~$25 |
| Fargate, 2 web + 1 worker + 1 scheduler | ~$33 |
| RDS db.t4g.micro, 20 GB gp3 | ~$18 |
| S3, CloudWatch Logs, SSM | ~$1 |

The NAT gateway and the load balancer cost more than the compute. VPC endpoints
for ECR, S3, CloudWatch Logs and SSM remove most of the NAT data charge if the
tasks have no other reason to reach the internet.

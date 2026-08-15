#!/usr/bin/env node
import 'source-map-support/register';
import * as cdk from 'aws-cdk-lib';
import { LaravelFargateStack } from '../lib/laravel-fargate-stack';

const app = new cdk.App();

new LaravelFargateStack(app, 'LaravelFargate', {
  env: {
    account: process.env.CDK_DEFAULT_ACCOUNT,
    region: process.env.CDK_DEFAULT_REGION ?? 'ap-southeast-2',
  },
  release: process.env.APP_RELEASE ?? 'dev',
  secretPrefix: '/laravel-fargate',
  description: 'Laravel 13 on ECS Fargate: web, queue worker, scheduler, RDS, S3',
});

// Applied to every resource in the stack. `Owner` is not decoration: some
// organisations attach a service control policy that denies resource creation
// unless the request carries it, and a stack-level tag satisfies that for
// everything CloudFormation creates on your behalf.
cdk.Tags.of(app).add('Project', 'laravel-on-aws-fargate');
cdk.Tags.of(app).add('Owner', process.env.OWNER_TAG ?? 'you@example.com');

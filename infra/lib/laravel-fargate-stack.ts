import * as path from 'node:path';
import * as cdk from 'aws-cdk-lib';
import { Construct } from 'constructs';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as ecs from 'aws-cdk-lib/aws-ecs';
import * as ecrAssets from 'aws-cdk-lib/aws-ecr-assets';
import * as elbv2 from 'aws-cdk-lib/aws-elasticloadbalancingv2';
import * as iam from 'aws-cdk-lib/aws-iam';
import * as logs from 'aws-cdk-lib/aws-logs';
import * as rds from 'aws-cdk-lib/aws-rds';
import * as s3 from 'aws-cdk-lib/aws-s3';
import * as ssm from 'aws-cdk-lib/aws-ssm';

export interface LaravelFargateStackProps extends cdk.StackProps {
  /** Short git SHA of the build, shown on the status page. */
  readonly release: string;
  /** SSM SecureString parameter prefix holding APP_KEY and the database password. */
  readonly secretPrefix: string;
}

export class LaravelFargateStack extends cdk.Stack {
  constructor(scope: Construct, id: string, props: LaravelFargateStackProps) {
    super(scope, id, props);

    // ---------------------------------------------------------------- network
    //
    // Three tiers. The load balancer is the only thing with a route to the
    // internet gateway; the tasks reach out through NAT; the database has no
    // route out at all.
    const vpc = new ec2.Vpc(this, 'Vpc', {
      maxAzs: 2,
      natGateways: 1,
      subnetConfiguration: [
        { name: 'edge', subnetType: ec2.SubnetType.PUBLIC, cidrMask: 24 },
        { name: 'app', subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS, cidrMask: 24 },
        { name: 'data', subnetType: ec2.SubnetType.PRIVATE_ISOLATED, cidrMask: 26 },
      ],
    });

    // ---------------------------------------------------------------- secrets
    //
    // Referenced, never created here. Both parameters are written by
    // deploy/bootstrap-secrets.sh before the first deploy, so no secret value
    // ever passes through the CloudFormation template or the CDK context file.
    const appKey = ssm.StringParameter.fromSecureStringParameterAttributes(this, 'AppKey', {
      parameterName: `${props.secretPrefix}/app-key`,
    });

    // The database password is needed by CloudFormation at create time, which
    // an ecs.Secret cannot do. `{{resolve:ssm-secure:...}}` is resolved by
    // CloudFormation itself during the deploy and is never written to the
    // template or to any event.
    const dbPassword = cdk.SecretValue.ssmSecure(`${props.secretPrefix}/db-password`);
    const dbPasswordParam = ssm.StringParameter.fromSecureStringParameterAttributes(this, 'DbPassword', {
      parameterName: `${props.secretPrefix}/db-password`,
    });

    // --------------------------------------------------------------- database
    const database = new rds.DatabaseInstance(this, 'Database', {
      // `.of()` rather than the VER_* enum: the enum lags behind what RDS
      // actually offers, and pinning the literal is what you want in IaC anyway.
      engine: rds.DatabaseInstanceEngine.postgres({
        version: rds.PostgresEngineVersion.of('18.4', '18'),
      }),
      instanceType: ec2.InstanceType.of(ec2.InstanceClass.BURSTABLE4_GRAVITON, ec2.InstanceSize.MICRO),
      vpc,
      vpcSubnets: { subnetType: ec2.SubnetType.PRIVATE_ISOLATED },
      credentials: rds.Credentials.fromPassword('laravel', dbPassword),
      databaseName: 'laravel',
      allocatedStorage: 20,
      maxAllocatedStorage: 50,
      storageEncrypted: true,
      multiAz: false,
      publiclyAccessible: false,
      // Demo settings. A real deployment keeps backups and deletion protection.
      backupRetention: cdk.Duration.days(0),
      deleteAutomatedBackups: true,
      deletionProtection: false,
      removalPolicy: cdk.RemovalPolicy.DESTROY,
    });

    // ---------------------------------------------------------------- storage
    const uploads = new s3.Bucket(this, 'Uploads', {
      encryption: s3.BucketEncryption.S3_MANAGED,
      blockPublicAccess: s3.BlockPublicAccess.BLOCK_ALL,
      enforceSSL: true,
      versioned: false,
      removalPolicy: cdk.RemovalPolicy.DESTROY,
    });

    // ------------------------------------------------------------------ image
    //
    // Fargate on Graviton is roughly 20% cheaper per vCPU-hour than x86, and an
    // Apple silicon laptop builds arm64 natively, so there is no emulation tax
    // on the build either.
    const image = new ecrAssets.DockerImageAsset(this, 'AppImage', {
      directory: path.join(__dirname, '..', '..', 'app'),
      platform: ecrAssets.Platform.LINUX_ARM64,
    });

    // ------------------------------------------------------------------ roles
    //
    // Two roles, and the split matters. The execution role belongs to the ECS
    // agent: it pulls the image, writes log streams, and decrypts the SSM
    // parameters so it can inject them as environment variables. The task role
    // belongs to your PHP process: it is what the AWS SDK inside Laravel signs
    // requests with. Application code never gets the execution role.
    const executionRole = new iam.Role(this, 'ExecutionRole', {
      assumedBy: new iam.ServicePrincipal('ecs-tasks.amazonaws.com'),
      managedPolicies: [
        iam.ManagedPolicy.fromAwsManagedPolicyName('service-role/AmazonECSTaskExecutionRolePolicy'),
      ],
    });
    appKey.grantRead(executionRole);
    dbPasswordParam.grantRead(executionRole);

    const taskRole = new iam.Role(this, 'TaskRole', {
      assumedBy: new iam.ServicePrincipal('ecs-tasks.amazonaws.com'),
      description: 'Signed by the AWS SDK inside the Laravel process',
    });
    uploads.grantReadWrite(taskRole);

    // ------------------------------------------------------- load balancer
    const alb = new elbv2.ApplicationLoadBalancer(this, 'Alb', {
      vpc,
      internetFacing: true,
      idleTimeout: cdk.Duration.seconds(60),
    });

    // --------------------------------------------------------------- runtime
    const cluster = new ecs.Cluster(this, 'Cluster', {
      vpc,
      containerInsightsV2: ecs.ContainerInsights.DISABLED,
    });

    const environment: Record<string, string> = {
      APP_NAME: 'Laravel on Fargate',
      APP_ENV: 'production',
      APP_DEBUG: 'false',
      APP_URL: `http://${alb.loadBalancerDnsName}`,
      APP_RELEASE: props.release,

      // stderr, because the awslogs driver reads the container's stdout/stderr.
      // Writing to storage/logs/laravel.log inside a Fargate task means writing
      // to a disk nobody will ever read.
      LOG_CHANNEL: 'stderr',
      LOG_LEVEL: 'info',

      DB_CONNECTION: 'pgsql',
      DB_HOST: database.dbInstanceEndpointAddress,
      DB_PORT: database.dbInstanceEndpointPort,
      DB_DATABASE: 'laravel',
      DB_USERNAME: 'laravel',

      // Every one of these has to be shared across tasks. The `file` driver is
      // the default and it is wrong here: each container gets its own copy, so
      // a user's session disappears the moment the load balancer sends them to
      // a different task.
      CACHE_STORE: 'database',
      SESSION_DRIVER: 'database',
      QUEUE_CONNECTION: 'database',

      FILESYSTEM_DISK: 's3',
      AWS_BUCKET: uploads.bucketName,
      AWS_DEFAULT_REGION: this.region,

      OCTANE_SERVER: 'frankenphp',

      // One resident PHP process per worker. Two on half a vCPU; raise it with
      // the task size, not beyond it.
      OCTANE_WORKERS: '2',
    };

    const secrets: Record<string, ecs.Secret> = {
      APP_KEY: ecs.Secret.fromSsmParameter(appKey),
      DB_PASSWORD: ecs.Secret.fromSsmParameter(dbPasswordParam),
    };

    /** One task definition shape, three different commands. */
    const defineTask = (name: string, cpu: number, memoryLimitMiB: number, command?: string[]) => {
      const logGroup = new logs.LogGroup(this, `${name}Logs`, {
        logGroupName: `/ecs/${cdk.Stack.of(this).stackName}/${name.toLowerCase()}`,
        retention: logs.RetentionDays.ONE_WEEK,
        removalPolicy: cdk.RemovalPolicy.DESTROY,
      });

      const taskDefinition = new ecs.FargateTaskDefinition(this, `${name}Task`, {
        cpu,
        memoryLimitMiB,
        runtimePlatform: {
          cpuArchitecture: ecs.CpuArchitecture.ARM64,
          operatingSystemFamily: ecs.OperatingSystemFamily.LINUX,
        },
        executionRole,
        taskRole,
      });

      const container = taskDefinition.addContainer('app', {
        image: ecs.ContainerImage.fromDockerImageAsset(image),
        command,
        environment,
        secrets,
        logging: ecs.LogDrivers.awsLogs({ streamPrefix: name.toLowerCase(), logGroup }),
        // Any non-zero exit stops the task, which is what you want: ECS then
        // replaces it instead of leaving a container that is up but not working.
        essential: true,
        readonlyRootFilesystem: false,
      });

      return { taskDefinition, container };
    };

    // --- web -----------------------------------------------------------------
    //
    // 512 CPU units is half a vCPU. FrankenPHP allocates one thread per Octane
    // worker plus one spare, so two workers is three threads, which fits.
    // Measured cold start to a passing /up on this size: about 4 seconds.
    const web = defineTask('Web', 512, 1024);

    web.container.addPortMappings({ containerPort: 8000, protocol: ecs.Protocol.TCP });

    const webService = new ecs.FargateService(this, 'WebService', {
      cluster,
      taskDefinition: web.taskDefinition,
      desiredCount: 2,
      vpcSubnets: { subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS },
      assignPublicIp: false,
      // Rolling deploy: bring up the new tasks before killing the old ones.
      minHealthyPercent: 100,
      maxHealthyPercent: 200,
      circuitBreaker: { enable: true, rollback: true },
      healthCheckGracePeriod: cdk.Duration.seconds(60),
      enableExecuteCommand: true,
    });

    alb.addListener('Http', { port: 80, open: true }).addTargets('Web', {
      port: 8000,
      protocol: elbv2.ApplicationProtocol.HTTP,
      targets: [webService],
      healthCheck: {
        // Shipped by Laravel since 11, wired up in bootstrap/app.php.
        path: '/up',
        interval: cdk.Duration.seconds(15),
        timeout: cdk.Duration.seconds(5),
        healthyThresholdCount: 2,
        unhealthyThresholdCount: 3,
        healthyHttpCodes: '200',
      },
      // Default is 300s. Five minutes of waiting on every deploy, for an app
      // whose requests finish in milliseconds, is just dead time.
      deregistrationDelay: cdk.Duration.seconds(15),
    });

    webService
      .autoScaleTaskCount({ minCapacity: 2, maxCapacity: 6 })
      .scaleOnCpuUtilization('Cpu', {
        targetUtilizationPercent: 60,
        scaleInCooldown: cdk.Duration.minutes(3),
        scaleOutCooldown: cdk.Duration.minutes(1),
      });

    // --- queue worker --------------------------------------------------------
    //
    // --max-time recycles the worker every hour. Long-lived PHP workers hold
    // objects in memory across jobs, so a slow leak in one job class eventually
    // takes the whole worker down. Recycling is cheaper than hunting the leak.
    //
    // The wait loop is not decoration. On a first deploy CloudFormation creates
    // the database and the services together, so `queue:work` starts against a
    // database with no tables, exits non-zero, and takes the deployment circuit
    // breaker with it. Blocking until the migrations table exists turns that
    // into a container that is up and idle, which is what it actually is.
    const worker = defineTask('Worker', 256, 512, [
      'sh', '-c',
      'until php artisan migrate:status >/dev/null 2>&1; do ' +
      'echo "waiting for migrations"; sleep 5; done; ' +
      'exec php artisan queue:work --tries=3 --max-time=3600 --sleep=1',
    ]);

    const workerService = new ecs.FargateService(this, 'WorkerService', {
      cluster,
      taskDefinition: worker.taskDefinition,
      desiredCount: 1,
      vpcSubnets: { subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS },
      assignPublicIp: false,
      minHealthyPercent: 0,
      circuitBreaker: { enable: true, rollback: true },
      enableExecuteCommand: true,
    });

    // --- scheduler -----------------------------------------------------------
    //
    // `schedule:work` is a long-running process that ticks every minute, so this
    // is one always-on task rather than an EventBridge rule launching a fresh
    // task 1,440 times a day. Cheaper, and it removes a minute of container
    // startup from every scheduled job.
    //
    // desiredCount is 1 and must stay 1: two schedulers means every scheduled
    // command runs twice.
    const scheduler = defineTask('Scheduler', 256, 512, [
      'sh', '-c',
      'until php artisan migrate:status >/dev/null 2>&1; do ' +
      'echo "waiting for migrations"; sleep 5; done; ' +
      'exec php artisan schedule:work',
    ]);

    const schedulerService = new ecs.FargateService(this, 'SchedulerService', {
      cluster,
      taskDefinition: scheduler.taskDefinition,
      desiredCount: 1,
      vpcSubnets: { subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS },
      assignPublicIp: false,
      minHealthyPercent: 0,
      maxHealthyPercent: 100,
      circuitBreaker: { enable: true, rollback: true },
      enableExecuteCommand: true,
    });

    // ------------------------------------------------------- network access
    for (const service of [webService, workerService, schedulerService]) {
      database.connections.allowDefaultPortFrom(service, 'Laravel to PostgreSQL');
    }

    // ---------------------------------------------------------------- outputs
    new cdk.CfnOutput(this, 'Url', { value: `http://${alb.loadBalancerDnsName}` });
    new cdk.CfnOutput(this, 'ClusterName', { value: cluster.clusterName });
    new cdk.CfnOutput(this, 'WebTaskDefinition', { value: web.taskDefinition.family });
    new cdk.CfnOutput(this, 'AppSubnets', {
      value: vpc.selectSubnets({ subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS }).subnetIds.join(','),
    });
    new cdk.CfnOutput(this, 'AppSecurityGroup', {
      value: webService.connections.securityGroups[0].securityGroupId,
    });
    new cdk.CfnOutput(this, 'UploadsBucket', { value: uploads.bucketName });
  }
}

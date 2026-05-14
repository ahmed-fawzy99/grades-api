# grades-api Helm chart

Single Helm chart for the Laravel API. Three environment overlays.

## Layout

```
helm/
├── Chart.yaml                # chart metadata
├── values.yaml               # base: local docker-desktop
├── values-prod.yaml          # production overlay (DOKS)
├── values-preview.yaml       # ephemeral PR previews (no HPA, dedicated pg)
├── templates/
│   ├── _helpers.tpl
│   ├── namespace.yaml        # rendered only when .Values.namespace.create
│   ├── api-deployment.yaml   # Octane web tier
│   ├── api-service.yaml
│   ├── api-hpa.yaml          # rendered only when .Values.autoscaling.enabled
│   ├── horizon-deployment.yaml
│   ├── scheduler-deployment.yaml # rendered only when .Values.scheduler.enabled
│   ├── api-httproute.yaml    # Gateway API HTTPRoute
│   ├── postgres-cluster.yaml # rendered only when .Values.postgres.dedicated
│   └── sealed-secret.yaml    # inlines helm/secrets/{file}.sealed.yaml when enabled
└── secrets/                  # encrypted Secrets (.sealed.yaml). See secrets/README.md.
```

## Three environments

| Overlay | Image | Replicas | HPA | Scheduler | Postgres |
|---|---|---|---|---|---|
| `values.yaml` (base, not used directly) | `localhost:5000/grades-api:latest` | 3 | yes | yes | shared |
| `values-local.yaml` | `grades-api:local` (Docker Desktop daemon, `pullPolicy: Never`) | 1 | no | yes | dedicated tiny CR |
| `values-prod.yaml` | `ghcr.io/<owner>/grades-api:sha-<short>` | 3 → 10 | yes | yes | shared (DOKS) |
| `values-preview.yaml` | `ghcr.io/<owner>/grades-api:pr-<n>-<sha>` | 1 | no | no | dedicated tiny CR |

The image tag in `values-prod.yaml` is rewritten by CI on every push to `main`
(`.github/workflows/ci.yml`, job `update-helm-values`). Don't edit it by hand.

## Install locally (Docker Desktop)

Local dev uses a dedicated overlay, `values-local.yaml`. It builds the image
into Docker Desktop's shared daemon (no registry needed), provisions Postgres
via the Zalando operator that the GitOps bootstrap installs, and reuses the
shared Redis cluster in `ot-operators`. The web tier is exposed through the
same Gateway API path as prod — Traefik's `web` (HTTP) listener publishes on
`http://localhost/...` because Docker Desktop binds the Traefik LoadBalancer
Service to the host. HTTPS is prod-only (needs a Cloudflare-issued cert).

Prereqs:
1. `kubectl config use-context docker-desktop`
2. The GitOps bootstrap has run on this cluster — `sealed-secrets`,
   `cert-manager`, `traefik`, `postgres-operator`, `redis-operator`,
   `redis-replication`, and `gateway-routes` Applications all show
   `Synced/Healthy` (except websecure listener TLS — that needs Cloudflare).
   See `../../grades-gitops/README.md`.

```sh
# 1) Build the image into Docker Desktop's daemon (which Kubernetes shares).
docker build -t grades-api:local .

# 2) Create the namespace and the plain Secret. Generate APP_KEY first.
kubectl create namespace grades

APP_KEY="base64:$(openssl rand -base64 32)"
cat > /tmp/grades-local.env <<EOF
APP_KEY=$APP_KEY
APP_NAME=Grades
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8081
LOG_CHANNEL=stack
LOG_LEVEL=debug
BCRYPT_ROUNDS=12
DB_CONNECTION=pgsql
DB_HOST=grades-api-pg.grades.svc.cluster.local
DB_PORT=5432
DB_DATABASE=grades_db
SESSION_DRIVER=database
QUEUE_CONNECTION=redis
CACHE_STORE=redis
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
REDIS_CLIENT=phpredis
REDIS_HOST=redis-replication-master.ot-operators.svc.cluster.local
REDIS_PORT=6379
REDIS_PASSWORD=null
MAIL_MAILER=log
EOF

# DB_USERNAME / DB_PASSWORD are injected by the chart from the Zalando-
# generated Secret — don't put them in this file (see "Gotchas" below).

kubectl -n grades create secret generic grades-api-secrets \
  --from-env-file=/tmp/grades-local.env

# 3) Install the chart.
helm upgrade --install grades-api ./helm -n grades -f ./helm/values-local.yaml

# 4) Wait for the API tier.
kubectl -n grades wait --for=condition=ready pod \
  -l app.kubernetes.io/component=backend --timeout=180s

# 5) Hit it through the Gateway. Docker Desktop binds Traefik's LoadBalancer
#    Service to host port 8080 (EXTERNAL-IP shows `localhost`).
curl -i http://localhost:8080/up                 # 200 OK from Octane
curl -s http://localhost:8080/api/v1/grades | jq # the actual API
```

To tear down: `helm -n grades uninstall grades-api && kubectl delete ns grades`.
The Postgres PVC survives the namespace delete — clean up with
`kubectl get pv` if you want a totally fresh DB.

## Install in production

Don't `helm upgrade` prod by hand. ArgoCD does it. See `../argo/README.md`.

## Render & lint without applying

```sh
helm lint helm/
helm template grades-api helm/ > /tmp/local.yaml
helm template grades-api helm/ -f helm/values-prod.yaml > /tmp/prod.yaml
helm template grades-api helm/ -f helm/values-preview.yaml \
  --set image.tag=pr-1-abc1234 \
  --set 'route.hostnames[0]=pr-1.preview.ahmaddeghady.com' \
  --set 'microservice.namespace=grades-pr-1' > /tmp/preview.yaml
```

## Adding a new env

1. Copy `values-prod.yaml` → `values-<env>.yaml`. Adjust replicas, resources,
   hostname, namespace.
2. Add a new ArgoCD `Application` under `argo/apps/api/<env>.yaml` pointing
   to the new value file.
3. Seal the env's Secret (next section) and commit it.

## Sealing a Secret for a new env

The chart loads a Secret named by `.Values.secretName` (default
`grades-api-secrets`) via `envFrom`. That Secret holds `APP_KEY`, `DB_*`,
`REDIS_*`, etc. **Plain Secrets must never be committed** — encrypt with
`kubeseal` first.

The chart has a `templates/sealed-secret.yaml` that uses `.Files.Get` to
inline the encrypted blob from `helm/secrets/{file}.sealed.yaml`. Two values
control it:

```yaml
sealedSecret:
  enabled: true       # turn the loader on
  file: prod          # picks helm/secrets/prod.sealed.yaml
```

Generating the file for prod:

```sh
# 1) Make a plaintext .env.prod (covered by .gitignore — never committed).
cp .env.example .env.prod && $EDITOR .env.prod

# 2) Encrypt against the cluster's sealed-secrets public key and write the
#    output directly to helm/secrets/.
kubectl create secret generic grades-api-secrets \
  --from-env-file=.env.prod \
  --namespace=grades-prod \
  --dry-run=client -o yaml \
  | kubeseal \
      --controller-namespace=sealed-secrets \
      --controller-name=sealed-secrets \
      --format=yaml \
  > helm/secrets/prod.sealed.yaml

# 3) Commit ONLY the sealed file.
git add helm/secrets/prod.sealed.yaml
```

The sealed-secrets controller in the cluster decrypts on apply and
materializes the real Secret named `grades-api-secrets` in `grades-prod`.
The blob in Git can only be decrypted by *this cluster's* private key — safe
to commit.

If `sealedSecret.enabled=true` but the referenced file is missing, the chart
fails template-rendering with a clear message (`fail` directive). This makes
"forgot to seal the secret" a build-time error, not a runtime one.

For details on the full workflow, rotation, and what's safe to commit, see
[`secrets/README.md`](secrets/README.md).

## How `postgres.dedicated` works

When `true` (preview + local), the chart renders an extra Zalando `postgresql`
CR named `{release}-pg`. The operator provisions a 1-instance Postgres and
creates a Secret named `<owner>.<cluster>.credentials.postgresql.acid.zalan.do`
containing two keys: `username` and `password`.

The Deployments project those two keys into the env as `DB_USERNAME` /
`DB_PASSWORD` via `valueFrom.secretKeyRef` — a plain `envFrom` would expose
them under the operator's raw key names (`username`/`password`) and Laravel
wouldn't see them. The `.env`-style Secret still needs `DB_HOST` (`<release>-pg.<namespace>.svc.cluster.local`),
`DB_PORT`, `DB_DATABASE`, `DB_CONNECTION=pgsql`, and `APP_KEY`/`REDIS_*`.

## Gotchas

- **`OCTANE_SERVER` must equal `frankenphp`** — the Dockerfile base image is
  FrankenPHP, but `config/octane.php` defaults to RoadRunner. Both `values.yaml`
  and the Dockerfile set this; don't break either.
- **Scheduler must stay at 1 replica** — overlapping schedulers double-fire
  every job. The deployment uses `strategy: Recreate` to enforce this.
- **HPA needs metrics-server** — DOKS doesn't bundle it; the ArgoCD bootstrap
  doesn't either. Install separately if missing:
  `helm install metrics-server metrics-server/metrics-server -n kube-system`.
  `values-local.yaml` disables autoscaling for this reason.
- **Local access goes through Traefik's `web` listener on host :8080** —
  Docker Desktop binds the Traefik LoadBalancer Service to localhost. The
  `web` listener serves plain HTTP; the `websecure` listener exists but has
  no usable cert locally (DNS-01 via Cloudflare is prod-only). HTTPS
  enforcement on prod is done at the HTTPRoute level via
  `route.enforceHttps=true` (Gateway API `RequestRedirect` filter), not at
  the Traefik entrypoint, so local stays reachable.
- **`grades-api-secrets` must exist before `helm install`** — the API,
  Horizon, and Scheduler Deployments load it via `envFrom`. If the Secret is
  missing, pods stay in `CreateContainerConfigError`. Create it first; the
  chart never creates a plain Secret itself (it only inlines a SealedSecret
  when `sealedSecret.enabled=true`).
- **DB credentials come from two places** — `DB_HOST`/`DB_PORT`/`DB_DATABASE`
  live in `grades-api-secrets` (envFrom); `DB_USERNAME`/`DB_PASSWORD` are
  pulled from the Zalando-generated Secret via explicit `secretKeyRef`. Do
  not duplicate the latter pair in `grades-api-secrets` or you'll get a
  startup-order race.

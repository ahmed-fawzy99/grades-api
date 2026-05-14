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
| `values.yaml` (local) | `localhost:5000/grades-api:latest` | 3 | yes | yes | shared |
| `values-prod.yaml` | `ghcr.io/<owner>/grades-api:sha-<short>` | 3 → 10 | yes | yes | shared (DOKS) |
| `values-preview.yaml` | `ghcr.io/<owner>/grades-api:pr-<n>-<sha>` | 1 | no | no | dedicated tiny CR |

The image tag in `values-prod.yaml` is rewritten by CI on every push to `main`
(`.github/workflows/ci.yml`, job `update-helm-values`). Don't edit it by hand.

## Install locally

```sh
# from api/
kubectl config use-context docker-desktop
helm upgrade --install grades-api ./helm -n grades --create-namespace
kubectl -n grades rollout status deploy/grades-api --timeout=180s
curl -i http://localhost:8081/up        # 200 OK from Octane
```

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

When `true` (preview only), the chart renders an extra Zalando `postgresql`
CR named `{release}-pg`. The operator provisions a 1-instance Postgres and
creates a Secret named `<owner>.<cluster>.credentials.postgresql.acid.zalan.do`.
Our Deployments load this Secret as a second `envFrom` so `DB_USERNAME` /
`DB_PASSWORD` are available without manual wiring. The `.env`-style Secret
still needs `DB_HOST=<release>-pg.<namespace>.svc.cluster.local` and the
other DB settings.

## Gotchas

- **`OCTANE_SERVER` must equal `frankenphp`** — the Dockerfile base image is
  FrankenPHP, but `config/octane.php` defaults to RoadRunner. Both `values.yaml`
  and the Dockerfile set this; don't break either.
- **Scheduler must stay at 1 replica** — overlapping schedulers double-fire
  every job. The deployment uses `strategy: Recreate` to enforce this.
- **HPA needs metrics-server** — DOKS doesn't bundle it; the ArgoCD bootstrap
  doesn't either. Install separately if missing:
  `helm install metrics-server metrics-server/metrics-server -n kube-system`.

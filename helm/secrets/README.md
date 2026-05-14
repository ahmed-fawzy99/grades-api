# helm/secrets/

Encrypted Secret YAMLs live here. **Only `*.sealed.yaml` files belong in this
folder** — the parent `.gitignore` blocks everything else so a `.env` or plain
Secret cannot be committed by accident.

## How it works end-to-end

```
.env.prod  ──kubeseal──▶  helm/secrets/prod.sealed.yaml  ──git push──▶  GitHub
   (plaintext, never                   (encrypted blob,                    │
   leaves your laptop)                  safe in Git)                       │
                                                                           ▼
                                                       sealed-secrets controller
                                                       in cluster decrypts it
                                                                           │
                                                                           ▼
                                                       Secret `grades-api-secrets`
                                                       appears in target namespace
                                                                           │
                                                                           ▼
                                                       grades-api pods load it
                                                       via envFrom (APP_KEY,
                                                       DB_*, REDIS_*, etc.)
```

The Helm chart's `templates/sealed-secret.yaml` inlines whichever file matches
`.Values.sealedSecret.file`. For prod (`values-prod.yaml`) that's `prod`, so
the chart picks up `prod.sealed.yaml`.

## Generating `prod.sealed.yaml`

Prerequisites:
- `kubeseal` CLI installed (`brew install kubeseal` or grab a release binary
  from `github.com/bitnami-labs/sealed-secrets/releases`).
- `kubectl` pointed at the prod cluster.
- The sealed-secrets controller already running (the ArgoCD Application
  `sealed-secrets` should have synced — sync wave `-10`).

Workflow:

```sh
# 1) Make a plaintext .env.prod on your laptop. NEVER commit this.
#    Use .env.example as a template; fill real values.
cp .env.example .env.prod
$EDITOR .env.prod

# 2) Generate the sealed blob.
kubectl create secret generic grades-api-secrets \
  --from-env-file=.env.prod \
  --namespace=grades-prod \
  --dry-run=client -o yaml \
  | kubeseal \
      --controller-namespace=sealed-secrets \
      --controller-name=sealed-secrets \
      --format=yaml \
  > helm/secrets/prod.sealed.yaml

# 3) Verify it renders.
helm template grades-api ./helm -f helm/values-prod.yaml | grep -A2 SealedSecret

# 4) Commit ONLY the sealed file. .env.prod stays on your laptop.
git add helm/secrets/prod.sealed.yaml
git commit -m "secrets: seal prod app secrets"
git push
```

Once pushed, ArgoCD applies the SealedSecret, the controller decrypts it into
a real Secret named `grades-api-secrets` in `grades-prod`, and the rolling
update picks up the new env vars.

## Rotating a secret

Re-run the generation command. The new `prod.sealed.yaml` overwrites the old
one. After `git push`, ArgoCD applies the diff and the sealed-secrets
controller patches the live Secret. Pods read updated env vars on next
restart (env vars from envFrom are static at boot — `kubectl rollout restart
deploy/grades-api -n grades-prod` to force it).

## Other environments

For a hypothetical `staging` environment:

1. Add `values-staging.yaml` with `sealedSecret: { enabled: true, file: staging }`.
2. Generate `helm/secrets/staging.sealed.yaml` exactly as above, using
   `--namespace=grades-staging` and `.env.staging` as input.
3. Add an ArgoCD Application at `argo/apps/api/staging.yaml`.

## What's safe to commit

| File | Commit? | Why |
|---|---|---|
| `helm/secrets/prod.sealed.yaml` | ✅ | Encrypted; only this cluster can decrypt |
| `helm/secrets/README.md` | ✅ | Just docs |
| `helm/secrets/.gitkeep` | ✅ | Keeps the folder in Git |
| `.env.prod`, `.env.*` | ❌ | Plaintext secrets |
| `helm/secrets/anything-else.yaml` | ❌ | Blocked by `.gitignore` |

{{/*
Common labels applied to every resource in the chart.
*/}}
{{- define "grades-api.labels" -}}
app.kubernetes.io/name: {{ .Values.microservice.name }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
app.kubernetes.io/part-of: grades
helm.sh/chart: {{ .Chart.Name }}-{{ .Chart.Version | replace "+" "_" }}
{{- end -}}

{{/*
Per-tier selector labels. Pass the tier name as the argument.
Usage: {{ include "grades-api.tierSelector" (list . "backend") }}
*/}}
{{- define "grades-api.tierSelector" -}}
{{- $ctx := index . 0 -}}
{{- $tier := index . 1 -}}
app.kubernetes.io/instance: {{ $ctx.Values.microservice.name }}{{ if ne $tier "backend" }}-{{ $tier }}{{ end }}
{{- end -}}

{{/*
Name of the Postgres CR when dedicated mode is on.
*/}}
{{- define "grades-api.postgresName" -}}
{{ .Values.microservice.name }}-pg
{{- end -}}

{{/*
Resolve the Secret name the deployments load via envFrom.
When postgres.dedicated, we still expect a "non-secret" config Secret with
APP_KEY, REDIS_*, APP_URL etc — DB password comes from the Zalando-generated
Secret in a second envFrom.
*/}}
{{- define "grades-api.secretName" -}}
{{ .Values.secretName }}
{{- end -}}
